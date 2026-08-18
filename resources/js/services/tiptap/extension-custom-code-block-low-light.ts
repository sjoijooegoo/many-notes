import { type Editor, type NodeViewRenderer } from '@tiptap/core';
import { CodeBlockLowlight } from '@tiptap/extension-code-block-lowlight';
import { type Node } from '@tiptap/pm/model';
import { createCopyButton } from './create-copy-button';

export const CustomCodeBlockLowlight = CodeBlockLowlight.extend({
    addNodeView(): NodeViewRenderer {
        return ({
            editor,
            node,
            getPos,
        }: {
            editor: Editor;
            node: Node;
            getPos: () => number | undefined;
        }) => {
            const pre = document.createElement('pre');

            const header = document.createElement('div');
            header.classList.add(
                'flex',
                'justify-between',
                'mb-2',
                'text-light-base-700',
                'dark:text-base-200',
                'print:hidden'
            );

            const languageSpan = document.createElement('span');
            languageSpan.innerText =
                node.attrs.language === 'plaintext' ? 'text' : (node.attrs.language ?? 'text');
            header.appendChild(languageSpan);

            if (navigator.clipboard) {
                const button = createCopyButton({
                    title: 'Copy code',
                    onClick: async () => {
                        const pos = getPos();

                        if (pos === undefined) {
                            return;
                        }

                        const domNode = editor.view.nodeDOM(pos);
                        const code = (domNode as Element | null)?.querySelector('code');

                        if (!code) {
                            return;
                        }

                        editor.commands.focus();
                        editor.commands.setTextSelection(pos + 1);
                        await navigator.clipboard.writeText(code.textContent ?? '');
                    },
                });
                button.classList.add('mt-1');

                header.appendChild(button);
            }

            const code = document.createElement('code');
            code.classList.add(`language-${node.attrs.language || 'text'}`);

            pre.appendChild(header);
            pre.appendChild(code);

            return {
                dom: pre,
                contentDOM: code,
            };
        };
    },
});
