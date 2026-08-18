import { Extension } from '@tiptap/core';
import { Fragment } from '@tiptap/pm/model';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { parseVaultFileDrag } from '../vault-file-drag';

export const VaultFileDrop = Extension.create({
    name: 'vaultFileDrop',

    addProseMirrorPlugins() {
        const editor = this.editor;

        return [
            new Plugin({
                key: new PluginKey('vaultFileDrop'),
                props: {
                    handleDrop(view, event) {
                        const file = parseVaultFileDrag(event.dataTransfer);

                        if (!file) {
                            return false;
                        }

                        event.preventDefault();

                        const position = view.posAtCoords({
                            left: event.clientX,
                            top: event.clientY,
                        });

                        if (!position) {
                            return true;
                        }

                        if (file.type === 'image') {
                            editor
                                .chain()
                                .focus()
                                .insertContentAt(position.pos, {
                                    type: 'image',
                                    attrs: { src: file.url, alt: file.name },
                                })
                                .run();

                            return true;
                        }

                        const { schema, tr } = view.state;
                        const linkMark = schema.marks.link?.create({ href: file.url });

                        if (!linkMark) {
                            return true;
                        }

                        const resolved = view.state.doc.resolve(position.pos);
                        const prefix =
                            resolved.nodeBefore?.isText && !resolved.nodeBefore?.text?.endsWith(' ')
                                ? [schema.text(' ')]
                                : [];
                        const suffix =
                            resolved.nodeAfter?.isText && !resolved.nodeAfter?.text?.startsWith(' ')
                                ? [schema.text(' ')]
                                : [];

                        tr.insert(
                            position.pos,
                            Fragment.fromArray([
                                ...prefix,
                                schema.text(file.name, [linkMark]),
                                ...suffix,
                            ])
                        );
                        view.dispatch(tr);

                        return true;
                    },
                },
            }),
        ];
    },
});
