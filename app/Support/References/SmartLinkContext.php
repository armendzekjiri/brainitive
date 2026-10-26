<?php

namespace App\Support\References;

use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;

/**
 * What one SmartLinks::resolve() batch knows about the viewer: the workspace's live projects,
 * the ones the user can open, and the first column per board / helpdesk (the "To Do" status).
 *
 * @internal
 */
final class SmartLinkContext
{
    /** @var array<int, int> board id → id of its first column */
    public array $firstColumns = [];

    /** @var array<int, int> helpdesk id → id of its first column */
    public array $firstHelpdeskColumns = [];

    /** @var array<int, true>|null */
    private ?array $adminProjectIds = null;

    /**
     * @param  Collection<int, Project>  $projects  live projects of the workspace, by id
     * @param  array<int, true>  $accessible  ids of the ones the user can open
     */
    public function __construct(
        public readonly Workspace $workspace,
        public readonly User $user,
        public readonly Collection $projects,
        private readonly array $accessible,
    ) {}

    public function canOpenProject(int $projectId): bool
    {
        return isset($this->accessible[$projectId]);
    }

    /** Page::visibleTo for one page: unpublished docs are their author's and admins' only. */
    public function canSeePage(Page $page): bool
    {
        if ($page->status !== 'draft' || (int) $page->created_by === (int) $this->user->id) {
            return true;
        }
        $this->adminProjectIds ??= array_fill_keys(array_map('intval', $this->user->adminProjectIds()), true);

        return isset($this->adminProjectIds[(int) $page->project_id]);
    }
}
