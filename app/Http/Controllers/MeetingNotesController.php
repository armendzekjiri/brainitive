<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Mcp\RequestIdConflict;
use App\Support\MeetingNotes\ActionItemCreator;
use App\Support\MeetingNotes\ActionItemExtractor;
use App\Support\MeetingNotes\ExtractionFailed;
use App\Support\MeetingNotes\MeetingNotesSource;
use App\Support\MeetingNotes\ProposalReview;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Meeting notes → issues (BRAI-62): options for the dialog, AI extraction of proposals (reads
 * only), and the creation of the reviewed proposals (atomic, idempotent). Every step authorizes
 * the destination board and the source doc again.
 */
class MeetingNotesController extends Controller
{
    /** What the dialog needs: writable boards, assignable members, AI state, the source doc. */
    public function options(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $user = $request->user();

        $request->validate(['page' => ['nullable', 'string', 'max:64']]);

        $source = null;
        if ($request->filled('page')) {
            $notes = MeetingNotesSource::fromPage($user, $project, (string) $request->query('page'));
            $source = [
                'uuid' => $notes->page->uuid,
                'title' => $notes->title(),
                'uses_draft' => $notes->usesDraft,
                'length' => $notes->length(),
                'too_long' => $notes->tooLong(),
            ];
        }

        return response()->json([
            'ai_configured' => ActionItemExtractor::configured($workspace),
            'can_configure_ai' => $user->can('update', $workspace),
            'settings_url' => route('workspaces.edit', $workspace->slug),
            'boards' => $this->writableBoards($user, $project)->map(fn (Board $b) => ['id' => $b->id, 'name' => $b->name])->values(),
            'members' => $this->members($project)->map(fn (User $m) => ['id' => $m->id, 'name' => $m->name])->values(),
            'timezone' => $user->localTimezone(),
            'source' => $source,
            'limits' => ['max_chars' => ActionItemExtractor::MAX_SOURCE_CHARS, 'max_proposals' => ActionItemExtractor::MAX_PROPOSALS],
        ]);
    }

    /** Proposals for review. Writes nothing. */
    public function extract(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $user = $request->user();

        $validated = $request->validate([
            'board_id' => ['required', 'integer'],
            'page' => ['nullable', 'string', 'max:64', 'required_without:text'],
            'text' => ['nullable', 'string', 'required_without:page', 'max:'.(ActionItemExtractor::MAX_SOURCE_CHARS * 2)],
            'meeting_date' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', 'required_with:meeting_date', 'timezone:all_with_bc'],
        ]);

        $board = $this->board($project, (int) $validated['board_id']);
        $this->authorize('createItems', $board);

        $source = ! empty($validated['page'])
            ? MeetingNotesSource::fromPage($user, $project, $validated['page'])
            : MeetingNotesSource::fromText($validated['text']);

        $meetingDate = ! empty($validated['meeting_date'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['meeting_date']) // a calendar day; the zone goes to the prompt
            : null;

        try {
            if ($source->text === '') {
                throw new ExtractionFailed('empty', 'There is no text to read action items from.');
            }
            if ($source->tooLong()) {
                throw new ExtractionFailed('too_long', 'These notes are '.number_format($source->length()).' characters long; the limit is '.number_format(ActionItemExtractor::MAX_SOURCE_CHARS).'. Use a shorter section.');
            }

            $candidates = ActionItemExtractor::extract($workspace, $source->text, $meetingDate, $validated['timezone'] ?? null);
        } catch (ExtractionFailed $e) {
            return response()->json(['code' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        $review = ProposalReview::review($candidates, $source->text, $this->members($project), $workspace, $project, $board, $meetingDate);

        return response()->json($review + [
            'board' => ['id' => $board->id, 'name' => $board->name],
            'source' => $source->page ? ['uuid' => $source->page->uuid, 'title' => $source->title(), 'uses_draft' => $source->usesDraft] : null,
        ]);
    }

    /** Create the selected, edited proposals as issues — once per idempotency key. */
    public function store(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $user = $request->user();

        $validated = $request->validate([
            'board_id' => ['required', 'integer'],
            'page' => ['nullable', 'string', 'max:64'],
            'link_source' => ['boolean'],
            'idempotency_key' => ['required', 'uuid'],
            'actions' => ['required', 'array', 'min:1', 'max:'.ActionItemExtractor::MAX_PROPOSALS],
            'actions.*.title' => ['required', 'string', 'max:255'],
            'actions.*.description' => ['nullable', 'string', 'max:5000'],
            'actions.*.excerpt' => ['nullable', 'string', 'max:1000'],
            'actions.*.assigned_to' => ['nullable', 'integer'],
            'actions.*.due_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        // Permissions are checked again here, also for a retry with a known key.
        $board = $this->board($project, (int) $validated['board_id']);
        $this->authorize('createItems', $board);
        $page = ! empty($validated['page']) ? MeetingNotesSource::fromPage($user, $project, $validated['page'])->page : null;

        // assignees: people who can open the project (the `ProjectMember` set, loaded once)
        $memberIds = $this->members($project)->pluck('id')->all();
        foreach ($validated['actions'] as $i => $action) {
            if (isset($action['assigned_to']) && ! in_array((int) $action['assigned_to'], $memberIds, true)) {
                throw ValidationException::withMessages(["actions.{$i}.assigned_to" => 'Pick a member of this project.']);
            }
        }

        $actions = array_map(fn (array $a) => [
            'title' => trim($a['title']),
            'description' => $a['description'] ?? null,
            'excerpt' => $a['excerpt'] ?? null,
            'assigned_to' => isset($a['assigned_to']) ? (int) $a['assigned_to'] : null,
            'due_date' => $a['due_date'] ?? null,
        ], array_values($validated['actions']));

        try {
            $result = ActionItemCreator::create($user, $workspace, $project, $board, $page, (bool) ($validated['link_source'] ?? false), $validated['idempotency_key'], $actions);
        } catch (RequestIdConflict) {
            return response()->json(['message' => 'These issues were already created from a different selection. Close the dialog and check the board.'], 409);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['board_id' => $e->getMessage()]);
        }

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }

    /** A board of this project (trashed boards don't bind). */
    private function board(Project $project, int $id): Board
    {
        $board = Board::where('project_id', $project->id)->find($id);
        if (! $board) {
            throw ValidationException::withMessages(['board_id' => 'Pick a board of this project.']);
        }

        return $board;
    }

    /** @return Collection<int, Board> */
    private function writableBoards(User $user, Project $project): Collection
    {
        $boards = Board::where('project_id', $project->id)->orderBy('position')->orderBy('id')->get(['id', 'name', 'project_id']);
        $first = $boards->first()?->setRelation('project', $project);

        // createItems depends only on the project role: one check answers for every board
        return $first && $user->can('createItems', $first) ? $boards : collect();
    }

    /**
     * People who can open the project (members + workspace admins) — the only possible assignees,
     * the same set `ProjectMember` accepts.
     *
     * @return Collection<int, User>
     */
    private function members(Project $project): Collection
    {
        // project members, plus workspace admins (they open every project) — `hasProjectAccess`
        $admins = $project->workspace->users()->wherePivot('role', 'admin')->get(['users.id', 'users.name']);

        return $project->users()->get(['users.id', 'users.name'])
            ->concat($admins)
            ->unique('id')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }
}
