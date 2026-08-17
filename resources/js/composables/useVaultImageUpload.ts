import VaultNodeChildrenController from '@/actions/App/Http/Controllers/VaultNodeChildrenController';
import VaultNodeController from '@/actions/App/Http/Controllers/VaultNodeController';
import VaultNodeImportController from '@/actions/App/Http/Controllers/VaultNodeImportController';
import { useToast } from '@/composables/useToast';
import { useVaultRecentFileStore } from '@/stores/vaultRecentFile';
import { useVaultTreeStore } from '@/stores/vaultTree';
import { VaultNode, VaultNodeTreeItem } from '@/types/vault';

const supportedExtensions = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif']);
const uploadFolderPath = ['attachments', 'images'] as const;

function extensionFromName(name: string): string {
    return name.split('.').pop()?.toLowerCase() ?? '';
}

export function imageFiles(files: FileList | File[]): File[] {
    return Array.from(files).filter(file => {
        return (
            file.type.startsWith('image/') && supportedExtensions.has(extensionFromName(file.name))
        );
    });
}

function timestamp(date: Date): string {
    const pad = (value: number, length = 2) => String(value).padStart(length, '0');

    return [
        date.getFullYear(),
        pad(date.getMonth() + 1),
        pad(date.getDate()),
        '-',
        pad(date.getHours()),
        pad(date.getMinutes()),
        pad(date.getSeconds()),
        '-',
        pad(date.getMilliseconds(), 3),
    ].join('');
}

function randomSuffix(): string {
    const bytes = new Uint8Array(3);
    globalThis.crypto.getRandomValues(bytes);

    return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
}

function renamedImage(file: File): File {
    const extension = extensionFromName(file.name);
    const name = `${timestamp(new Date())}-${randomSuffix()}.${extension}`;

    return new File([file], name, {
        type: file.type,
        lastModified: file.lastModified,
    });
}

export function useVaultImageUpload(vaultId: number, parentId: number | null) {
    const { createToast } = useToast();
    const vaultRecentFileStore = useVaultRecentFileStore();
    const vaultTreeStore = useVaultTreeStore();
    let folderSetup: Promise<number> | null = null;

    function findFolder(parentNodeId: number | null, name: string): VaultNodeTreeItem | null {
        for (const childId of vaultTreeStore.getChildren(parentNodeId)) {
            const child = vaultTreeStore.getNodeById(childId);

            if (child && !child.is_file && child.name === name) {
                return child;
            }
        }

        return null;
    }

    async function loadFolderChildren(folderId: number): Promise<void> {
        if (vaultTreeStore.isFolderLoaded(folderId)) {
            return;
        }

        const response = await globalThis.axios.get<{ children: VaultNode[] }>(
            VaultNodeChildrenController.url({ vault: vaultId, node: folderId })
        );

        vaultTreeStore.setChildren(folderId, response.data.children);
        vaultTreeStore.setLoadedFolder(folderId);
    }

    async function ensureFolder(
        parentNodeId: number | null,
        name: string
    ): Promise<VaultNodeTreeItem> {
        if (parentNodeId !== null) {
            await loadFolderChildren(parentNodeId);
        }

        const existing = findFolder(parentNodeId, name);

        if (existing) {
            return existing;
        }

        const response = await globalThis.axios.post<{ data: VaultNode }>(
            VaultNodeController.store.url({ vault: vaultId }),
            {
                parent_id: parentNodeId,
                is_file: false,
                name,
            }
        );
        const folder = response.data.data;

        vaultTreeStore.handleNodeSaved(folder);
        vaultTreeStore.setChildren(folder.id, []);
        vaultTreeStore.setLoadedFolder(folder.id);

        return folder;
    }

    async function ensureUploadFolder(): Promise<number> {
        let currentParentId = parentId;

        for (const folderName of uploadFolderPath) {
            const folder = await ensureFolder(currentParentId, folderName);
            currentParentId = folder.id;
        }

        return currentParentId!;
    }

    async function uploadFolderId(): Promise<number> {
        const pending = folderSetup ?? ensureUploadFolder();
        folderSetup = pending;

        try {
            return await pending;
        } finally {
            if (folderSetup === pending) {
                folderSetup = null;
            }
        }
    }

    async function uploadImages(files: File[]): Promise<VaultNode[]> {
        const images = imageFiles(files);

        if (images.length === 0) {
            return [];
        }

        try {
            const targetFolderId = await uploadFolderId();
            const data = new FormData();
            data.append('parent_id', String(targetFolderId));

            for (const image of images) {
                data.append('files[]', renamedImage(image));
            }

            const response = await globalThis.axios.post<{ files: VaultNode[] }>(
                VaultNodeImportController.url({ vault: vaultId }),
                data
            );
            const imported = response.data.files;

            for (const file of imported) {
                vaultRecentFileStore.upsertRecentFile(file);
                vaultTreeStore.handleNodeSaved(file);
            }

            if (imported.length === 0) {
                throw new Error('No valid images were uploaded');
            }

            const label = imported.length === 1 ? 'image' : 'images';
            createToast(`${imported.length} ${label} uploaded`, 'success');

            return imported;
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Image upload failed';
            createToast(message, 'error');

            throw error;
        }
    }

    return { uploadImages };
}
