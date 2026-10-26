import NotionPreview from '@/components/imports/sources/NotionPreview';
import TrelloPreview from '@/components/imports/sources/TrelloPreview';
import type { ImportRunData } from '@/types';
import type { ComponentType } from 'react';

/**
 * Per-source preview + options (v1.41.0). The run page renders the component registered for the
 * run's source: it shows the importer's `preview` and calls `onStart(options)` with the options the
 * importer's optionRules() expect. Each ticket adds one line here.
 */
export interface SourcePreviewProps {
    run: ImportRunData;
    /** project the import goes into (null for a restore) */
    workspaceSlug: string;
    busy: boolean;
    onStart: (options: Record<string, unknown>) => void;
}

export const SOURCE_PREVIEWS: Record<string, ComponentType<SourcePreviewProps>> = {
    trello: TrelloPreview,            // BRAI-65
    // jira: JiraPreview,              // BRAI-139
    notion: NotionPreview,            // BRAI-66
    // brainitive: ArchivePreview,     // BRAI-81 (restore)
};
