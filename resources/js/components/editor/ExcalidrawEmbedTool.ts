/**
 * EditorJS block tool: embed an Excalidraw diagram (image + link to open editor).
 * Data: { diagramId, imageUrl?, caption? }
 */
import type { BlockToolConstructorOptions } from '@editorjs/editorjs/types/tools/block-tool';

const ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="M2 2l7.586 7.586"/><circle cx="11" cy="11" r="2"/></svg>';

export interface ExcalidrawEmbedData {
    diagramId: string;
    imageUrl?: string;
    caption?: string;
}

export interface ExcalidrawEmbedConfig {
    getEmbedEditorUrl: (params: { diagramId: string; blockId: string }) => string;
}

export class ExcalidrawEmbedTool {
    private data: ExcalidrawEmbedData;
    private readOnly: boolean;
    private blockId: string;
    private config: ExcalidrawEmbedConfig;
    private api: any;

    static get toolbox() {
        return {
            icon: ICON_SVG,
            title: 'Excalidraw',
        };
    }

    static get isReadOnlySupported() {
        return true;
    }

    constructor({ data, block, readOnly, config }: BlockToolConstructorOptions<ExcalidrawEmbedData, ExcalidrawEmbedConfig>) {
        this.data = {
            diagramId: data?.diagramId ?? `embed-${Date.now()}`,
            imageUrl: data?.imageUrl,
            caption: data?.caption,
        };
        this.readOnly = readOnly;
        this.blockId = block.id;
        this.config = config || { getEmbedEditorUrl: () => '#' };
        this.api = (block as any).api;
    }

    render(): HTMLElement {
        const wrap = document.createElement('div');
        wrap.className = 'excalidraw-embed-block';
        wrap.setAttribute('data-diagram-id', this.data.diagramId);

        if (this.data.imageUrl) {
            const imgWrap = document.createElement('div');
            imgWrap.className = 'excalidraw-embed-block__image-wrap';
            const img = document.createElement('img');
            img.src = this.data.imageUrl;
            img.alt = this.data.caption || 'Excalidraw diagram';
            img.className = 'excalidraw-embed-block__image';
            imgWrap.appendChild(img);
            if (!this.readOnly) {
                imgWrap.style.cursor = 'pointer';
                imgWrap.title = 'Click to edit diagram';
                imgWrap.addEventListener('click', () => this.openEditor());
            }
            wrap.appendChild(imgWrap);
        } else {
            const placeholder = document.createElement('div');
            placeholder.className = 'excalidraw-embed-block__placeholder';
            placeholder.innerHTML = '<span class="excalidraw-embed-block__placeholder-icon">✏️</span><span>Excalidraw diagram</span><span class="excalidraw-embed-block__placeholder-hint">Click to open editor</span>';
            if (!this.readOnly) {
                placeholder.style.cursor = 'pointer';
                placeholder.addEventListener('click', () => this.openEditor());
            }
            wrap.appendChild(placeholder);
        }

        if (this.data.caption) {
            const cap = document.createElement('p');
            cap.className = 'excalidraw-embed-block__caption';
            cap.textContent = this.data.caption;
            wrap.appendChild(cap);
        }

        return wrap;
    }

    private openEditor(): void {
        const url = this.config.getEmbedEditorUrl({ diagramId: this.data.diagramId, blockId: this.blockId });
        if (url && url !== '#') window.location.href = url;
    }

    save(): ExcalidrawEmbedData {
        return {
            diagramId: this.data.diagramId,
            imageUrl: this.data.imageUrl,
            caption: this.data.caption,
        };
    }
}
