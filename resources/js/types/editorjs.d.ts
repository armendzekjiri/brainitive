declare module '@editorjs/editorjs' {
    export interface OutputData {
        time?: number;
        blocks: OutputBlockData[];
        version?: string;
    }

    export interface OutputBlockData {
        id?: string;
        type: string;
        data: any;
    }

    export interface API {
        blocks: {
            clear: () => void;
            render: (data: OutputData) => Promise<void>;
            delete: (index?: number) => void;
            swap: (fromIndex: number, toIndex: number) => void;
            getBlockByIndex: (index: number) => BlockAPI | undefined;
            getCurrentBlockIndex: () => number;
            getBlocksCount: () => number;
            stretchBlock: (index: number, status?: boolean) => void;
            insert: (type?: string, data?: object, config?: object, index?: number, needToFocus?: boolean) => void;
        };
        caret: {
            setToFirstBlock: (position?: 'end' | 'start' | 'default', offset?: number) => boolean;
            setToLastBlock: (position?: 'end' | 'start' | 'default', offset?: number) => boolean;
            setToNextBlock: (position?: 'end' | 'start' | 'default', offset?: number) => boolean;
            setToPreviousBlock: (position?: 'end' | 'start' | 'default', offset?: number) => boolean;
            setToBlock: (index: number, position?: 'end' | 'start' | 'default', offset?: number) => boolean;
            focus: (atEnd?: boolean) => boolean;
        };
        events: {
            emit: (eventName: string, data?: object) => void;
            off: (eventName: string, callback: () => void) => void;
            on: (eventName: string, callback: () => void) => void;
        };
        listeners: {
            on: (element: HTMLElement, eventType: string, handler: () => void, useCapture?: boolean) => void;
            off: (element: HTMLElement, eventType: string, handler: () => void, useCapture?: boolean) => void;
        };
        notifier: {
            show: (options: { message: string; style?: 'success' | 'error' }) => void;
        };
        sanitizer: {
            clean: (taintString: string, config?: object) => string;
        };
        saver: {
            save: () => Promise<OutputData>;
        };
        selection: {
            findParentTag: (tagName: string, className?: string) => HTMLElement | null;
            expandToTag: (node: HTMLElement) => void;
        };
        styles: {
            block: string;
            inlineToolButton: string;
            inlineToolButtonActive: string;
            input: string;
            loader: string;
            button: string;
            settingsButton: string;
            settingsButtonActive: string;
        };
        toolbar: {
            close: () => void;
            open: () => void;
        };
        inlineToolbar: {
            close: () => void;
            open: () => void;
        };
        tooltip: {
            show: (element: HTMLElement, text: string, options?: object) => void;
            hide: () => void;
        };
        i18n: {
            t: (namespace: string) => string;
        };
        readOnly: {
            isEnabled: boolean;
            toggle: (state?: boolean) => Promise<boolean>;
        };
    }

    export interface BlockAPI {
        id: string;
        name: string;
        config: object;
        holder: HTMLElement;
        isEmpty: boolean;
        selected: boolean;
        stretched: boolean;
        call: (methodName: string, param?: object) => void;
        save: () => Promise<OutputBlockData>;
    }

    export interface EditorConfig {
        holder?: string | HTMLElement;
        autofocus?: boolean;
        defaultBlock?: string;
        placeholder?: string | false;
        sanitizer?: object;
        hideToolbar?: boolean;
        tools?: { [toolName: string]: any };
        data?: OutputData;
        readOnly?: boolean;
        minHeight?: number;
        logLevel?: 'VERBOSE' | 'INFO' | 'WARN' | 'ERROR';
        i18n?: object;
        inlineToolbar?: string[] | boolean;
        tunes?: string[];
        onChange?: (api: API, event: CustomEvent) => void;
        onReady?: () => void;
    }

    export default class EditorJS {
        constructor(config?: EditorConfig);
        isReady: Promise<void>;
        save(): Promise<OutputData>;
        render(data: OutputData): Promise<void>;
        clear(): void;
        destroy(): void;
        readonly readOnly: {
            isEnabled: boolean;
            toggle: (state?: boolean) => Promise<boolean>;
        };
    }
}

declare module '@editorjs/header' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const Header: ToolConstructable;
    export default Header;
}

declare module '@editorjs/list' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const List: ToolConstructable;
    export default List;
}

declare module '@editorjs/nested-list' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const NestedList: ToolConstructable;
    export default NestedList;
}

declare module '@editorjs/checklist' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const Checklist: ToolConstructable;
    export default Checklist;
}

declare module '@editorjs/quote' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const Quote: ToolConstructable;
    export default Quote;
}

declare module '@editorjs/code' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const Code: ToolConstructable;
    export default Code;
}

declare module '@editorjs/delimiter' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const Delimiter: ToolConstructable;
    export default Delimiter;
}

declare module '@editorjs/table' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const Table: ToolConstructable;
    export default Table;
}

declare module '@editorjs/image' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const ImageTool: ToolConstructable;
    export default ImageTool;
}

declare module '@editorjs/link' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const LinkTool: ToolConstructable;
    export default LinkTool;
}

declare module '@editorjs/marker' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const Marker: ToolConstructable;
    export default Marker;
}

declare module '@editorjs/inline-code' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const InlineCode: ToolConstructable;
    export default InlineCode;
}

declare module '@editorjs/warning' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const Warning: ToolConstructable;
    export default Warning;
}

declare module '@editorjs/embed' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const Embed: ToolConstructable;
    export default Embed;
}

declare module '@editorjs/paragraph' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const Paragraph: ToolConstructable;
    export default Paragraph;
}

declare module '@editorjs/attaches' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const Attaches: ToolConstructable;
    export default Attaches;
}

declare module 'editorjs-alert' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const Alert: ToolConstructable;
    export default Alert;
}

declare module '@editorjs/raw' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const RawTool: ToolConstructable;
    export default RawTool;
}

declare module '@editorjs/underline' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const Underline: ToolConstructable;
    export default Underline;
}

declare module '@editorjs/text-variant-tune' {
    import { ToolConstructable } from '@editorjs/editorjs';
    const TextVariantTune: ToolConstructable;
    export default TextVariantTune;
}

declare module 'editorjs-undo' {
    import EditorJS from '@editorjs/editorjs';
    export default class Undo {
        constructor(params: { editor: EditorJS; config?: object });
        initialize(data: any): void;
    }
}

declare module 'editorjs-drag-drop' {
    import EditorJS from '@editorjs/editorjs';
    export default class DragDrop {
        constructor(editor: EditorJS);
    }
}
