import { mergeAttributes, type NodeViewRenderer } from '@tiptap/core';
import Image, { ImageOptions } from '@tiptap/extension-image';
import { createCopyButton } from './create-copy-button';

export interface CustomImageOptions extends ImageOptions {
    vaultId: string | null;
    canCopyPath: (src: string) => boolean;
    onCopyPath: (src: string) => void | Promise<void>;
}

export const CustomImage = Image.extend<CustomImageOptions>({
    addOptions() {
        return {
            ...this.parent!(),
            vaultId: null,
            canCopyPath: () => false,
            onCopyPath: () => undefined,
        };
    },

    renderHTML({ HTMLAttributes }) {
        const { src, ...rest } = HTMLAttributes;
        const resolvedSrc = resolveImageSource(src, this.options.vaultId);

        return ['img', mergeAttributes(this.options.HTMLAttributes, { ...rest, src: resolvedSrc })];
    },

    addNodeView(): NodeViewRenderer {
        const options = this.options;

        return ({ node, HTMLAttributes }) => {
            let currentNode = node;
            const wrapper = document.createElement('figure');
            wrapper.classList.add('vault-image-node', 'relative', 'w-fit', 'max-w-full');

            const image = document.createElement('img');
            image.classList.add('m-0', 'max-w-full');
            wrapper.appendChild(image);

            const copyButton = createCopyButton({
                title: 'Copy server path',
                onClick: () => options.onCopyPath(currentNode.attrs.src ?? ''),
            });
            copyButton.classList.add(
                'vault-image-copy-button',
                'text-light-base-700',
                'dark:text-base-200',
                'pointer-events-none',
                'absolute',
                'top-3',
                'right-2',
                'z-10',
                'opacity-0',
                'transition-opacity',
                'hover:opacity-100'
            );
            wrapper.appendChild(copyButton);

            function updateImage(): void {
                const attributes = mergeAttributes(options.HTMLAttributes, HTMLAttributes, {
                    ...currentNode.attrs,
                    src: resolveImageSource(currentNode.attrs.src, options.vaultId),
                });

                for (const attribute of Array.from(image.attributes)) {
                    image.removeAttribute(attribute.name);
                }

                image.classList.add('m-0', 'max-w-full');

                for (const [name, value] of Object.entries(attributes)) {
                    if (value !== null && value !== undefined) {
                        image.setAttribute(name, String(value));
                    }
                }

                copyButton.hidden = !options.canCopyPath(currentNode.attrs.src ?? '');
            }

            updateImage();

            return {
                dom: wrapper,
                update(updatedNode) {
                    if (updatedNode.type !== currentNode.type) {
                        return false;
                    }

                    currentNode = updatedNode;
                    updateImage();

                    return true;
                },
                stopEvent(event) {
                    return copyButton.contains(event.target as globalThis.Node | null);
                },
                ignoreMutation() {
                    return true;
                },
            };
        };
    },
});

function resolveImageSource(src: unknown, vaultId: string | null): unknown {
    return typeof src === 'string' &&
        !src.startsWith('http://') &&
        !src.startsWith('https://') &&
        vaultId
        ? `/files/${vaultId}?path=${src}`
        : src;
}
