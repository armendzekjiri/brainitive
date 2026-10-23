<?php

namespace App\Support\Captures;

use App\Models\BookmarkItem;
use App\Models\Capture;
use App\Models\Item;
use App\Models\Page;
use App\Models\Snippet;
use App\Models\TaskListItem;
use App\Models\User;
use App\Support\Mcp\ResourceLocator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Turns a capture into content (BRAI-70). Atomic and idempotent:
 * - everything runs in one transaction on the locked capture row, so the target is created and
 *   the capture marked processed together, or neither happens;
 * - a capture that is already processed returns its existing target (`replayed`) — a double
 *   click or a retry never creates a second one;
 * - the destination is authorized with its own policy (`ContentCapture::authorize`): owning the
 *   capture grants nothing there. Any failure leaves the capture in the inbox.
 */
class CaptureConversion
{
    /** What a conversion creates, per destination type. */
    private const TARGETS = [
        'issue' => Item::class,
        'task' => TaskListItem::class,
        'page' => Page::class,
        'bookmark' => BookmarkItem::class,
        'snippet' => Snippet::class,
    ];

    /** Relations ResourceLocator walks for each target type. */
    private const LOCATOR_RELATIONS = [
        'issue' => ['board.project.workspace'],
        'task' => ['taskList.project.workspace'],
        'page' => ['project.workspace'],
        'bookmark' => ['bookmark.workspace'],
        'snippet' => ['collection.workspace'],
    ];

    /**
     * @param  array<string, mixed>  $fields
     * @return array{capture: Capture, location: array<string, mixed>, replayed: bool}
     *
     * @throws ValidationException|AuthorizationException
     */
    public static function convert(User $user, Capture $capture, string $type, int $destinationId, array $fields): array
    {
        return DB::transaction(function () use ($user, $capture, $type, $destinationId, $fields) {
            $locked = Capture::whereKey($capture->id)->lockForUpdate()->firstOrFail();

            if ($locked->processed_at !== null) {
                return ['capture' => $locked, 'location' => self::location($locked), 'replayed' => true];
            }
            if ($locked->archived_at !== null) {
                throw ValidationException::withMessages(['capture' => 'Restore this capture before organizing it.']);
            }

            $destination = ContentCapture::findDestination($type, $destinationId)
                ?? throw ValidationException::withMessages(['destination_id' => 'This destination does not exist any more.']);

            ContentCapture::authorize($user, $type, $destination);
            $data = Validator::make($fields, ContentCapture::rules($type, $destination))->validate();

            try {
                $target = ContentCapture::create($user, $type, $destination, $data);
            } catch (\DomainException $e) {
                throw ValidationException::withMessages(['destination_id' => $e->getMessage()]);
            }

            $location = ResourceLocator::locate($target);
            $locked->update([
                'processed_at' => now(),
                'target_type' => $type,
                'target_id' => $target->getKey(),
                'target_location' => $location,
            ]);

            return ['capture' => $locked, 'location' => $location, 'replayed' => false];
        });
    }

    /**
     * The created content's current location, or the snapshot taken at conversion when it was
     * deleted since (`available: false`).
     *
     * @return array<string, mixed>|null
     */
    public static function location(Capture $capture): ?array
    {
        return self::locations(collect([$capture]))[$capture->id] ?? null;
    }

    /**
     * Batch version for a list of captures: one query per target type.
     *
     * @param  Collection<int, Capture>  $captures
     * @return array<int, array<string, mixed>> capture id → location
     */
    public static function locations(Collection $captures): array
    {
        $result = [];

        $captures->filter(fn (Capture $c) => $c->target_type && $c->target_id && isset(self::TARGETS[$c->target_type]))
            ->groupBy('target_type')
            ->each(function (Collection $group, string $type) use (&$result) {
                /** @var class-string<Model> $class */
                $class = self::TARGETS[$type];
                $targets = $class::query()->with(self::LOCATOR_RELATIONS[$type])
                    ->whereKey($group->pluck('target_id')->unique()->all())
                    ->get()
                    ->keyBy(fn (Model $m) => $m->getKey());

                foreach ($group as $capture) {
                    $target = $targets->get($capture->target_id);
                    $result[$capture->id] = $target
                        ? ResourceLocator::locate($target) + ['available' => true]
                        : ($capture->target_location ?? ['type' => $type, 'title' => '', 'breadcrumb' => '', 'url' => null]) + ['available' => false];
                }
            });

        return $result;
    }
}
