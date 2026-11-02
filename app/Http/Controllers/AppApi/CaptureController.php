<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\AnswersWithETag;
use App\Http\Controllers\AppApi\Concerns\CreatesOnce;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\CapturePreviewRequest;
use App\Http\Requests\AppApi\ConvertCaptureRequest;
use App\Http\Requests\AppApi\ListCapturesRequest;
use App\Http\Requests\AppApi\StoreCaptureRequest;
use App\Http\Requests\AppApi\UpdateCaptureRequest;
use App\Http\Resources\AppApi\CaptureCollection;
use App\Http\Resources\AppApi\CaptureConversionResource;
use App\Http\Resources\AppApi\CaptureCountsResource;
use App\Http\Resources\AppApi\CaptureDestinationResource;
use App\Http\Resources\AppApi\CapturePreviewResource;
use App\Http\Resources\AppApi\CaptureResource;
use App\Models\Capture;
use App\Models\User;
use App\Support\AppApi\AppTokens;
use App\Support\Captures\CaptureConversion;
use App\Support\Captures\CaptureDestinations;
use App\Support\Captures\CaptureFields;
use App\Support\Captures\CaptureInbox;
use App\Support\Entitlements\LimitReached;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — the capture inbox (BRAI-398): a private list of ideas saved without a project (desktop
 * quick-capture hotkey, mobile share sheet), organized later into an issue, task, doc, bookmark or
 * snippet. Same code as the web page, the extension and the MCP (CaptureInbox, CaptureConversion,
 * CaptureFields, CaptureDestinations). Only the owner sees a capture; anybody else's is a 404.
 */
#[Group('Captures', weight: 160)]
class CaptureController extends Controller
{
    use AnswersWithETag;
    use CreatesOnce;

    /**
     * The user's captures in one state, newest first.
     *
     * `state` = inbox (default) | processed | archived; `q` searches the text, URL and source.
     */
    public function index(ListCapturesRequest $request): CaptureCollection
    {
        $user = $request->user();
        $since = $request->updatedSince();

        $page = CaptureInbox::query($user, $request->state(), $request->search())
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());

        $captures = $page->getCollection();
        $locations = CaptureConversion::locations($captures, $user);
        $page->setCollection($captures->map(fn (Capture $capture) => new CaptureResource($capture, $locations[$capture->id] ?? null)));

        return new CaptureCollection($page);
    }

    /** How many captures the user has in each state (the inbox badge). */
    public function counts(Request $request): CaptureCountsResource
    {
        return new CaptureCountsResource(CaptureInbox::counts($request->user()));
    }

    /**
     * Save an idea to the inbox.
     *
     * `source` defaults to the app's name ("Brainitive Desktop"). With `request_id` a retry answers 200
     * with the capture the first call created.
     */
    public function store(StoreCaptureRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->safe()->except(['request_id']);
        $client = AppTokens::currentDevice($request)?->client;
        $source = $client ? config("app-api.clients.{$client}.name") : null;

        [$capture, $replayed] = $this->createOnce($request, 'captures.store', Capture::class, $data,
            fn () => CaptureInbox::create($user, $data, is_string($source) ? $source : null));

        if ($replayed) {
            return (new CaptureResource($capture))->response();
        }

        return (new CaptureResource($capture))->response()->setStatusCode(201);
    }

    /**
     * One capture.
     *
     * @throws NotFoundHttpException not the user's capture (404)
     */
    public function show(Request $request, string $capture): CaptureResource
    {
        return $this->present($this->owned($request, $capture), $request->user());
    }

    /**
     * Edit a capture's text, URL, intended type or source (only the sent fields).
     *
     * @throws NotFoundHttpException not the user's capture (404)
     */
    public function update(UpdateCaptureRequest $request, string $capture): CaptureResource
    {
        $capture = $request->ownedCapture();
        $capture->update($request->validated());

        return $this->present($capture, $request->user());
    }

    /**
     * Archive a capture (it leaves the inbox; nothing is deleted).
     *
     * @throws NotFoundHttpException not the user's capture (404)
     */
    public function archive(Request $request, string $capture): CaptureResource
    {
        $capture = $this->owned($request, $capture);
        CaptureInbox::archive($capture);

        return $this->present($capture, $request->user());
    }

    /**
     * Restore an archived capture (back to the inbox, or to processed when it was converted).
     *
     * @throws NotFoundHttpException not the user's capture (404)
     */
    public function restore(Request $request, string $capture): CaptureResource
    {
        $capture = $this->owned($request, $capture);
        CaptureInbox::restore($capture);

        return $this->present($capture, $request->user());
    }

    /**
     * Delete a capture for good — archived ones only (422 otherwise), as the web.
     *
     * @throws NotFoundHttpException not the user's capture (404)
     */
    public function destroy(Request $request, string $capture): Response
    {
        $capture = $this->owned($request, $capture);
        if ($capture->archived_at === null) {
            throw ValidationException::withMessages(['capture' => ['Archive a capture before deleting it.']]);
        }

        $capture->delete();

        return response()->noContent();
    }

    /**
     * The fields the capture becomes for one destination type, to edit before converting.
     *
     * @throws NotFoundHttpException not the user's capture (404)
     */
    public function preview(CapturePreviewRequest $request, string $capture): CapturePreviewResource
    {
        $capture = $request->ownedCapture();
        $type = (string) $request->validated('type');

        return new CapturePreviewResource(['type' => $type, 'fields' => CaptureFields::defaults($capture, $type)]);
    }

    /**
     * Turn a capture into content: an issue, task, doc, bookmark or snippet.
     *
     * Atomic and idempotent, as the web: a capture converted before answers 200 with what it became
     * (`replayed`); a new conversion 201. The destination needs its own rights (403); archived captures
     * and invalid fields → 422.
     *
     * @throws NotFoundHttpException not the user's capture (404)
     * @throws AuthorizationException the user may not create content at the destination (403)
     * @throws LimitReached the workspace is at its storage limit (402 limit_reached; issues and docs)
     */
    public function convert(ConvertCaptureRequest $request, string $capture): JsonResponse
    {
        $user = $request->user();
        $capture = $request->ownedCapture();
        $data = $request->validated();

        $result = CaptureConversion::convert($user, $capture, $data['type'], (int) $data['destination_id'], $data['fields']);
        $current = CaptureConversion::location($result['capture'], $user);

        if ($result['replayed']) {
            return (new CaptureConversionResource($result, $current))->response();
        }

        return (new CaptureConversionResource($result, $current))->response()->setStatusCode(201);
    }

    /**
     * Where the user can organize captures into, per workspace (only what they may write to).
     *
     * Answers an `ETag`: send it back as `If-None-Match` to get 304 while nothing changed.
     *
     * @response AnonymousResourceCollection<int, CaptureDestinationResource>
     */
    public function destinations(Request $request): JsonResponse|Response
    {
        return $this->withETag($request, CaptureDestinationResource::collection(CaptureDestinations::for($request->user()))->response($request));
    }

    private function owned(Request $request, string $id): Capture
    {
        $capture = Str::isUuid($id) ? Capture::ownedBy($request->user())->where('uuid', $id)->first() : null;
        abort_unless($capture instanceof Capture, 404);

        return $capture;
    }

    private function present(Capture $capture, User $user): CaptureResource
    {
        return new CaptureResource($capture, CaptureConversion::location($capture, $user));
    }
}
