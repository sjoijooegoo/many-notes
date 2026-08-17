import VaultNodeImportController from '@/actions/App/Http/Controllers/VaultNodeImportController';
import { useToast } from '@/composables/useToast';
import { useVaultRecentFileStore } from '@/stores/vaultRecentFile';
import { useVaultTreeStore } from '@/stores/vaultTree';
import { VaultNode } from '@/types/vault';

const supportedExtensions = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif']);

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

export function useVaultImageUpload(vaultId: number, parentId: number | null) {
    const { createToast } = useToast();
    const vaultRecentFileStore = useVaultRecentFileStore();
    const vaultTreeStore = useVaultTreeStore();

    async function uploadImages(files: File[]): Promise<VaultNode[]> {
        const images = imageFiles(files);

        if (images.length === 0) {
            return [];
        }

        const data = new FormData();
        data.append('parent_id', parentId === null ? '' : String(parentId));

        for (const image of images) {
            data.append('files[]', image);
        }

        try {
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
