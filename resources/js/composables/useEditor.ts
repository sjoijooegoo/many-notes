import { markedService } from '@/services/marked';
import { CustomCodeBlockLowlight } from '@/services/tiptap/extension-custom-code-block-low-light';
import { CustomImage } from '@/services/tiptap/extension-custom-image';
import { CustomLink } from '@/services/tiptap/extension-custom-link';
import { CustomTableCell } from '@/services/tiptap/extension-custom-table-cell';
import { CustomTableColumnAlign } from '@/services/tiptap/extension-custom-table-column-align';
import { CustomTableHeader } from '@/services/tiptap/extension-custom-table-header';
import { Hashtag } from '@/services/tiptap/extension-hashtag';
import { SmartBracket } from '@/services/tiptap/extension-smart-bracket';
import { VaultFileDrop } from '@/services/tiptap/extension-vault-file-drop';
import { turndownService } from '@/services/turndown';
import { Editor } from '@tiptap/core';
import { Table, TableRow } from '@tiptap/extension-table';
import TaskItem from '@tiptap/extension-task-item';
import TaskList from '@tiptap/extension-task-list';
import { NodeSelection } from '@tiptap/pm/state';
import StarterKit from '@tiptap/starter-kit';
import { common, createLowlight } from 'lowlight';
import { onMounted, onUnmounted, Ref, shallowRef, watch } from 'vue';

interface UploadedImage {
    name: string;
    full_path: string;
}

interface SetupEditorOptions {
    vaultId: string;
    element: Ref<HTMLElement | null>;
    markdownElement: Ref<HTMLElement | null>;
    autofocus?: boolean;
    content: string;
    isEditMode: Readonly<Ref<boolean>>;
    onUpdate: (markdown: string) => void;
    openFilePath: (path: string) => void;
    uploadImages: (files: File[]) => Promise<UploadedImage[]>;
    canCopyImagePath: (src: string) => boolean;
    copyImagePath: (src: string) => void | Promise<void>;
}

export function useEditor(options: SetupEditorOptions) {
    const editor = shallowRef<Editor | null>(null);
    let isSyncing: boolean = true;

    const prepareTiptapHTML = (html: string) => {
        return (
            html
                // Prepare plain text code
                .replace(
                    /<code\s+([^>]*?)class="language-plaintext"([^>]*?)>/g,
                    (match, before, after) => {
                        return `<code${before}${after}>`;
                    }
                )
                // Prepare links
                .replace(/<a\s+([^>]*?)data-href([^>]*?)>/g, (match, before, after) => {
                    return `<a ${before}href${after}>`;
                })
                // Prepare task lists
                .replace(
                    /<li([^>]*)>\s*(?:<label[^>]*>)\s*(<input type="checkbox"[^>]*>)(?:<span><\/span><\/label>)(?:<div>)?(?:<p>)?(.*?)(?:<\/p>)?(?:<\/div>)?<\/li>/gs,
                    (match, liAttributes, input, content) => {
                        return `<li${liAttributes}">${input}${content}</li>`;
                    }
                )
        );
    };

    const encodeText = (text: string) => {
        // Encode paths from Markdown links
        const encoded = text.replace(
            /\[(.*?)\]\((.*?)(\s".*?")?\)/g,
            (match, text, path, title) => {
                if (title === undefined) {
                    title = '';
                }

                try {
                    return `[${text}](${encodeURI(decodeURI(path))}${title})`;
                } catch {
                    return `[${text}](${path}${title})`;
                }
            }
        );

        // Prevent HTML rendering
        return encoded.replace(/</g, '&lt;');
    };

    const content = options.content ? markedService.parse(encodeText(options.content)) : '';

    function localImages(files: FileList | null): File[] {
        return files ? Array.from(files).filter(file => file.type.startsWith('image/')) : [];
    }

    async function uploadAndInsertImages(files: File[], position: number): Promise<void> {
        const uploaded = await options.uploadImages(files);

        if (!editor.value || uploaded.length === 0) {
            return;
        }

        const safePosition = Math.min(position, editor.value.state.doc.content.size);
        let chain = editor.value.chain().focus().setTextSelection(safePosition);

        for (const image of uploaded) {
            let src = image.full_path;

            try {
                src = encodeURI(src);
            } catch {
                // Keep the original path when the browser cannot encode it.
            }

            chain = chain.setImage({ src, alt: image.name, title: image.name });
        }

        chain.run();
    }

    onMounted(() => {
        editor.value = new Editor({
            element: options.element.value,
            autofocus: options.autofocus,
            extensions: [
                StarterKit.configure({
                    code: {
                        HTMLAttributes: {
                            class: 'not-prose px-1 py-0.5 text-sm rounded-sm bg-light-base-400 dark:bg-base-700',
                        },
                    },
                    codeBlock: false,
                    link: false,
                }),
                SmartBracket,
                Hashtag,
                CustomCodeBlockLowlight.configure({
                    defaultLanguage: 'plaintext',
                    lowlight: createLowlight(common),
                }),
                CustomImage.configure({
                    vaultId: options.vaultId,
                    canCopyPath: options.canCopyImagePath,
                    onCopyPath: options.copyImagePath,
                }),
                CustomLink.configure({
                    autolink: false,
                    onOpenFile: href => options.openFilePath(href),
                }),
                TaskList,
                TaskItem.configure({
                    nested: true,
                }),
                Table,
                TableRow,
                CustomTableHeader.configure({
                    HTMLAttributes: {
                        class: 'border border-light-base-400 dark:border-base-700 p-2',
                    },
                }),
                CustomTableCell.configure({
                    HTMLAttributes: {
                        class: 'border border-light-base-400 dark:border-base-700 p-2',
                    },
                }),
                CustomTableColumnAlign,
                VaultFileDrop,
            ],
            content: content,
            editable: options.isEditMode.value,
            editorProps: {
                attributes: {
                    class: 'h-full !max-w-none flow-root focus:outline-none prose dark:prose-invert',
                },
                handleClickOn(view, _position, node, nodePosition, _event, direct) {
                    if (!direct || node.type.name !== 'image') {
                        return false;
                    }

                    view.dispatch(
                        view.state.tr.setSelection(
                            NodeSelection.create(view.state.doc, nodePosition)
                        )
                    );
                    view.focus();

                    return true;
                },
                handlePaste(view, event) {
                    if (!options.isEditMode.value) {
                        return false;
                    }

                    const files = localImages(event.clipboardData?.files ?? null);

                    if (files.length === 0) {
                        return false;
                    }

                    event.preventDefault();
                    const position = view.state.selection.from;
                    void uploadAndInsertImages(files, position).catch(() => undefined);

                    return true;
                },
                handleDrop(view, event, _slice, moved) {
                    if (!options.isEditMode.value || moved) {
                        return false;
                    }

                    const files = localImages(event.dataTransfer?.files ?? null);

                    if (files.length === 0) {
                        return false;
                    }

                    event.preventDefault();
                    const position = view.posAtCoords({ left: event.clientX, top: event.clientY });
                    const insertAt = position?.pos ?? view.state.selection.from;
                    void uploadAndInsertImages(files, insertAt).catch(() => undefined);

                    return true;
                },
            },
            onCreate(props) {
                const firstNode = props.editor.state.doc.firstChild;

                if (firstNode?.type.name === 'paragraph' && firstNode.content.size === 0) {
                    props.editor.commands.deleteNode('paragraph');
                }

                isSyncing = false;

                setMarkdownContent(options.content);
            },
            onUpdate() {
                if (isSyncing) {
                    return;
                }

                isSyncing = true;

                const html = prepareTiptapHTML(editor.value?.getHTML() ?? '');
                const markdown = turndownService.turndown(html);
                setMarkdownContent(markdown);

                isSyncing = false;

                options.onUpdate(markdown);
            },
        });
    });

    function setTiptapContent(html: string) {
        editor.value?.commands.setContent(html);
    }

    function setMarkdownContent(markdown: string) {
        if (options.markdownElement.value) {
            options.markdownElement.value.textContent = markdown;
        }
    }

    async function setContent(markdown: string) {
        isSyncing = true;

        const html = await markedService.parse(encodeText(markdown));
        setTiptapContent(html);
        setMarkdownContent(markdown);

        isSyncing = false;
    }

    async function onMarkdownChanged(markdown: string) {
        if (isSyncing) {
            return;
        }

        isSyncing = true;

        const html = await markedService.parse(encodeText(markdown));
        setTiptapContent(html);

        isSyncing = false;

        options.onUpdate(markdown);
    }

    watch(options.isEditMode, value => {
        isSyncing = true;

        editor.value?.setEditable(value);

        isSyncing = false;
    });

    onUnmounted(() => {
        editor.value?.destroy();
        editor.value = null;
    });

    return {
        editor,
        setContent,
        onMarkdownChanged,
    };
}
