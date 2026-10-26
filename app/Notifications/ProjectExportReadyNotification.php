<?php

namespace App\Notifications;

use App\Models\ProjectExport;
use Illuminate\Support\Number;

/**
 * To the person who exported a project, when its archive is ready (v1.41.0, BRAI-81; category:
 * workspace). The link is the signed download, valid for 24 h; after that it leads to the
 * project's settings, where a new export can be started.
 */
class ProjectExportReadyNotification extends CatalogNotification
{
    public const TYPE = 'export_ready';

    public function __construct(public readonly ProjectExport $export) {}

    public function catalogType(): string
    {
        return self::TYPE;
    }

    protected function message(object $notifiable): array
    {
        $project = $this->export->project;
        $workspace = $this->export->workspace;

        return [
            'title' => 'Your export of “'.($project?->name ?? 'a project').'” is ready to download',
            'body' => 'ZIP, '.Number::fileSize($this->export->file_size).' · the link works for '.ProjectExport::TTL_HOURS.' hours.',
            'url' => $this->export->downloadUrl() ?? ($project && $workspace ? route('projects.edit', [$workspace->slug, $project->slug]) : url('/')),
            'actor' => null,
            'project_name' => $project?->name,
            'subject_key' => null,
        ];
    }
}
