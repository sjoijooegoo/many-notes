<script setup lang="ts">
import VaultNodeController from '@/actions/App/Http/Controllers/VaultNodeController';
import { useAxiosForm } from '@/composables/useAxiosForm';
import { useEditor } from '@/composables/useEditor';
import { useTiptapPreferences } from '@/composables/useTiptapPreferences';
import { useToast } from '@/composables/useToast';
import { useVaultActions } from '@/composables/useVaultActions';
import { imageFiles, useVaultImageUpload } from '@/composables/useVaultImageUpload';
import { parseVaultFileDrag, vaultFileMarkdown } from '@/services/vault-file-drag';
import { resolveServerVaultImagePath, resolveVaultImagePath } from '@/services/vault-image-path';
import { useLayoutStore } from '@/stores/layout';
import { VaultNode } from '@/types/vault';
import { VaultShowPageProps } from '@/types/vault.pages';
import { usePage } from '@inertiajs/vue3';
import { inject, onMounted, ref, ShallowRef } from 'vue';

interface VaultFileNodeProps {
    node: VaultNode;
}

interface VaultFileNodeEmits {
    contentUpdated: [content: string];
}

const props = defineProps<VaultFileNodeProps>();
const emit = defineEmits<VaultFileNodeEmits>();

const page = usePage<VaultShowPageProps>();
const layoutStore = useLayoutStore();
const { createToast } = useToast();
const vaultActions = useVaultActions();
const { isEditMode, isEditingMarkdown } = useTiptapPreferences();
const form = useAxiosForm({});
const { uploadImages } = useVaultImageUpload(props.node.vault_id, props.node.parent_id);

const editorContext = inject<ShallowRef<ReturnType<typeof useEditor> | null>>('editorContext');

if (!editorContext) {
    throw new Error('editorContext is not provided');
}

const noteEditorRef = ref<HTMLElement | null>(null);
const noteMarkdownRef = ref<HTMLElement | null>(null);

let debounceTimer: ReturnType<typeof setTimeout> | null = null;

const { editor, setContent, onMarkdownChanged } = useEditor({
    vaultId: String(props.node.vault_id),
    element: noteEditorRef,
    markdownElement: noteMarkdownRef,
    content: props.node.content ?? '',
    isEditMode: isEditMode,
    onUpdate: (markdown: string) => {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }

        debounceTimer = setTimeout(() => {
            const url = VaultNodeController.update.url({
                vault: props.node.vault_id,
                node: props.node.id,
            });

            layoutStore.setVaultNodeUpdating(true);

            form.send({
                url: url,
                method: 'patch',
                data: {
                    content: markdown,
                },
                onError: error => {
                    const message = error.response?.statusText ?? 'Something went wrong';
                    createToast(message, 'error');
                    emit('contentUpdated', props.node.content ?? '');
                    editorContext.value?.setContent(props.node.content ?? '');
                },
                onSuccess: (response: { data: VaultNode }) => {
                    emit('contentUpdated', response.data.content ?? '');
                },
                onFinish: () => {
                    layoutStore.setVaultNodeUpdating(false);
                },
            });
        }, 1000);
    },
    openFilePath: vaultActions.openFilePath,
    uploadImages,
    canCopyImagePath,
    copyImagePath,
});

function imageServerPath(source: string): string | null {
    const vaultPath = resolveVaultImagePath(source, props.node.full_path);
    const vault = page.props.vault;

    if (!vaultPath || vault.id !== props.node.vault_id) {
        return null;
    }

    return resolveServerVaultImagePath(vaultPath, vault.user.id, vault.name);
}

function canCopyImagePath(source: string): boolean {
    return imageServerPath(source) !== null;
}

async function writeClipboard(text: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        return;
    } catch {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.append(textarea);
        textarea.select();
        const copied = document.execCommand('copy');
        textarea.remove();

        if (!copied) {
            throw new Error('Unable to copy path');
        }
    }
}

async function copyImagePath(source: string): Promise<void> {
    const path = imageServerPath(source);

    if (!path) {
        createToast('This image does not have a local server path', 'error');
        return;
    }

    try {
        await writeClipboard(path);
        createToast('Server image path copied', 'success');
    } catch {
        createToast('Unable to copy server image path', 'error');
    }
}

function caretOffset(element: HTMLElement): number {
    const selection = globalThis.getSelection();

    if (!selection?.rangeCount) {
        return element.textContent?.length ?? 0;
    }

    const range = selection.getRangeAt(0);

    if (!element.contains(range.endContainer)) {
        return element.textContent?.length ?? 0;
    }

    const beforeCaret = range.cloneRange();
    beforeCaret.selectNodeContents(element);
    beforeCaret.setEnd(range.endContainer, range.endOffset);

    return beforeCaret.toString().length;
}

function setCaretOffset(element: HTMLElement, offset: number): void {
    const selection = globalThis.getSelection();
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    let remaining = offset;
    let node = walker.nextNode();

    while (node) {
        const length = node.textContent?.length ?? 0;

        if (remaining <= length) {
            const range = document.createRange();
            range.setStart(node, remaining);
            range.collapse(true);
            selection?.removeAllRanges();
            selection?.addRange(range);

            return;
        }

        remaining -= length;
        node = walker.nextNode();
    }

    element.focus();
}

function moveCaretToDropPoint(event: DragEvent): void {
    const documentWithCaretRange = document as Document & {
        caretRangeFromPoint?: (x: number, y: number) => Range | null;
    };
    const range = documentWithCaretRange.caretRangeFromPoint?.(event.clientX, event.clientY);

    if (!range || !noteMarkdownRef.value?.contains(range.startContainer)) {
        return;
    }

    const selection = globalThis.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(range);
}

async function insertRawMarkdown(markdown: string, offset: number): Promise<void> {
    if (!noteMarkdownRef.value || markdown === '') {
        return;
    }

    const current = noteMarkdownRef.value.textContent ?? '';
    const safeOffset = Math.min(offset, current.length);
    const prefix = safeOffset > 0 && current[safeOffset - 1] !== '\n' ? '\n' : '';
    const suffix = safeOffset < current.length && current[safeOffset] !== '\n' ? '\n' : '';
    const insertion = `${prefix}${markdown}${suffix}`;
    const updated = current.slice(0, safeOffset) + insertion + current.slice(safeOffset);

    noteMarkdownRef.value.textContent = updated;
    setCaretOffset(noteMarkdownRef.value, safeOffset + insertion.length);
    await editorContext?.value?.onMarkdownChanged(updated);
}

async function insertRawMarkdownImages(files: File[], offset: number): Promise<void> {
    const uploaded = await uploadImages(files);
    const markdown = uploaded
        .map(image => {
            const alt = image.name.replaceAll('[', '\\[').replaceAll(']', '\\]');

            return `![${alt}](${image.full_path})`;
        })
        .join('\n');

    await insertRawMarkdown(markdown, offset);
}

function onMarkdownPaste(event: ClipboardEvent): void {
    const files = imageFiles(event.clipboardData?.files ?? []);

    if (files.length === 0 || !noteMarkdownRef.value) {
        return;
    }

    event.preventDefault();
    const offset = caretOffset(noteMarkdownRef.value);
    void insertRawMarkdownImages(files, offset).catch(() => undefined);
}

function onMarkdownDrop(event: DragEvent): void {
    const vaultFile = parseVaultFileDrag(event.dataTransfer);

    if (vaultFile && noteMarkdownRef.value) {
        event.preventDefault();
        moveCaretToDropPoint(event);
        const offset = caretOffset(noteMarkdownRef.value);
        void insertRawMarkdown(vaultFileMarkdown(vaultFile), offset).catch(() => undefined);

        return;
    }

    const files = imageFiles(event.dataTransfer?.files ?? []);

    if (files.length === 0 || !noteMarkdownRef.value) {
        return;
    }

    event.preventDefault();
    moveCaretToDropPoint(event);
    const offset = caretOffset(noteMarkdownRef.value);
    void insertRawMarkdownImages(files, offset).catch(() => undefined);
}

onMounted(() => {
    editorContext.value = { editor, setContent, onMarkdownChanged };
});
</script>

<template>
    <div class="flex h-full w-full flex-col">
        <div
            ref="noteEditorRef"
            class="h-full w-full px-4"
            :class="isEditingMarkdown ? 'hidden' : ''"
            spellcheck="false"
        ></div>

        <div
            ref="noteMarkdownRef"
            class="h-full w-full px-4 whitespace-pre-wrap focus:outline-none"
            :class="isEditingMarkdown ? '' : 'hidden'"
            :contenteditable="isEditMode ? 'plaintext-only' : 'false'"
            spellcheck="false"
            @input="editorContext?.onMarkdownChanged(noteMarkdownRef?.textContent ?? '')"
            @paste="onMarkdownPaste"
            @dragover.prevent
            @drop="onMarkdownDrop"
        />
    </div>
</template>

<style scoped>
:deep(.vault-image-node.ProseMirror-selectednode) {
    outline: none;
}

:deep(.vault-image-node.ProseMirror-selectednode img) {
    outline: 2px solid rgb(255 255 255 / 95%);
    outline-offset: -2px;
    box-shadow: 0 0 0 1px rgb(0 0 0 / 35%);
}

:deep(.vault-image-node.ProseMirror-selectednode .vault-image-copy-button) {
    pointer-events: auto;
    opacity: 0.8;
}
</style>
