import { Node, mergeAttributes } from '@tiptap/core';

export interface CalloutOptions {
    HTMLAttributes: Record<string, any>;
}

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        callout: {
            setCallout: (attributes?: { type?: string }) => ReturnType;
            insertCallout: (attributes?: { type?: string }) => ReturnType;
            toggleCallout: (attributes?: { type?: string }) => ReturnType;
            unsetCallout: () => ReturnType;
        };
    }
}

export const Callout = Node.create<CalloutOptions>({
    name: 'callout',

    group: 'block',

    content: 'block+',

    defining: true,

    addOptions() {
        return {
            HTMLAttributes: {},
        };
    },

    addAttributes() {
        return {
            type: {
                default: 'info',
                parseHTML: (element) => element.getAttribute('data-type') || 'info',
                renderHTML: (attributes) => ({
                    'data-type': attributes.type,
                }),
            },
        };
    },

    parseHTML() {
        return [
            {
                tag: 'div[data-callout]',
            },
        ];
    },

    renderHTML({ node, HTMLAttributes }) {
        const typeClasses: Record<string, string> = {
            info: 'bg-blue-50 border-blue-200 text-blue-900 dark:bg-blue-950 dark:border-blue-800 dark:text-blue-100',
            warning: 'bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-950 dark:border-amber-800 dark:text-amber-100',
            success: 'bg-green-50 border-green-200 text-green-900 dark:bg-green-950 dark:border-green-800 dark:text-green-100',
            error: 'bg-red-50 border-red-200 text-red-900 dark:bg-red-950 dark:border-red-800 dark:text-red-100',
        };

        const typeClass = typeClasses[node.attrs.type] || typeClasses.info;

        return [
            'div',
            mergeAttributes(this.options.HTMLAttributes, HTMLAttributes, {
                'data-callout': '',
                class: `callout callout-${node.attrs.type} ${typeClass} p-4 rounded-lg border my-4`,
            }),
            0,
        ];
    },

    addCommands() {
        return {
            setCallout:
                (attributes) =>
                ({ commands }) => {
                    return commands.wrapIn(this.name, attributes);
                },
            insertCallout:
                (attributes) =>
                ({ chain }) => {
                    return chain()
                        .insertContent({
                            type: this.name,
                            attrs: attributes,
                            content: [
                                {
                                    type: 'paragraph',
                                },
                            ],
                        })
                        .run();
                },
            toggleCallout:
                (attributes) =>
                ({ commands }) => {
                    return commands.toggleWrap(this.name, attributes);
                },
            unsetCallout:
                () =>
                ({ commands }) => {
                    return commands.lift(this.name);
                },
        };
    },
});
