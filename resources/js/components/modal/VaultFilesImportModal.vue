<script setup lang="ts">
import VaultNodeImportController from '@/actions/App/Http/Controllers/VaultNodeImportController';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import { useModalManager } from '@/composables/useModalManager';
import { useToast } from '@/composables/useToast';
import Spinner from '@/icons/Spinner.vue';
import { useVaultRecentFileStore } from '@/stores/vaultRecentFile';
import { useVaultTreeStore } from '@/stores/vaultTree';
import { AppPageProps } from '@/types';
import { VaultNode } from '@/types/vault';
import { usePage } from '@inertiajs/vue3';
import axios, { AxiosError } from 'axios';
import { computed, onMounted, ref } from 'vue';

type SelectedFile = {
    file: File;
    relativePath: string;
};

type ImportResponse = {
    files: VaultNode[];
    folders: VaultNode[];
    root: VaultNode | null;
    skipped_files: string[];
};

const MAX_FILES_PER_BATCH = 20;

const vaultRecentFileStore = useVaultRecentFileStore();
const vaultTreeStore = useVaultTreeStore();
const page = usePage<AppPageProps>();
const { closeModal } = useModalManager();
const { createToast } = useToast();

const props = defineProps<{
    vaultId: number;
    parentId: number | null;
    dropEvent: DragEvent | null;
}>();

const uploadMaxFilesize = computed(() => page.props.app?.metadata?.upload_max_filesize ?? '0');
const uploadMaxFilesizeBytes = computed(
    () => page.props.app?.metadata?.upload_max_filesize_bytes ?? 0
);

const fileUpload = ref<HTMLInputElement | null>(null);
const folderUpload = ref<HTMLInputElement | null>(null);
const processing = ref(false);
const progress = ref(0);
const status = ref('');

function createBatches(files: SelectedFile[]): SelectedFile[][] {
    const batches: SelectedFile[][] = [];
    const maxBatchBytes = Math.max(1, Math.floor(uploadMaxFilesizeBytes.value * 0.9));
    let batch: SelectedFile[] = [];
    let batchBytes = 0;

    for (const file of files) {
        if (
            batch.length >= MAX_FILES_PER_BATCH ||
            (batch.length > 0 && batchBytes + file.file.size > maxBatchBytes)
        ) {
            batches.push(batch);
            batch = [];
            batchBytes = 0;
        }

        batch.push(file);
        batchBytes += file.file.size;
    }

    if (batch.length > 0) {
        batches.push(batch);
    }

    return batches;
}

function applyImportedNodes(response: ImportResponse): void {
    if (response.root) {
        vaultTreeStore.handleNodeSaved(response.root);
    }

    for (const folder of response.folders) {
        vaultTreeStore.handleNodeSaved(folder);
    }

    for (const file of response.files) {
        vaultRecentFileStore.upsertRecentFile(file);
        vaultTreeStore.handleNodeSaved(file);
    }
}

async function uploadBatch(
    files: SelectedFile[],
    parentId: number | null,
    rootName: string | null,
    processedFiles: number,
    totalFiles: number
): Promise<ImportResponse> {
    const data = new FormData();

    data.append('parent_id', parentId === null ? '' : String(parentId));

    if (rootName) {
        data.append('root_name', rootName);
    }

    files.forEach((selected, index) => {
        data.append(`files[${index}]`, selected.file);
        data.append(`relative_paths[${index}]`, selected.relativePath);
    });

    const response = await axios.post<ImportResponse>(
        VaultNodeImportController.url({ vault: props.vaultId }),
        data,
        {
            onUploadProgress: event => {
                const batchProgress = event.total ? event.loaded / event.total : 0;
                progress.value = Math.round(
                    ((processedFiles + files.length * batchProgress) / totalFiles) * 100
                );
            },
        }
    );

    return response.data;
}

async function importFiles(files: SelectedFile[], rootName: string | null = null): Promise<void> {
    if (processing.value) {
        return;
    }

    const validFiles = files.filter(file => file.file.size <= uploadMaxFilesizeBytes.value);
    let skippedCount = files.length - validFiles.length;

    if (validFiles.length === 0) {
        createToast('No valid files to import', 'error');
        return;
    }

    const batches = createBatches(validFiles);
    let parentId = props.parentId;
    let importedCount = 0;
    let processedCount = 0;
    let failedBatch = false;

    processing.value = true;
    progress.value = 0;

    for (const [index, batch] of batches.entries()) {
        status.value = `Uploading batch ${index + 1} of ${batches.length}`;

        try {
            const response = await uploadBatch(
                batch,
                parentId,
                index === 0 ? rootName : null,
                processedCount,
                validFiles.length
            );

            if (rootName && index === 0) {
                if (!response.root) {
                    throw new Error('Unable to create root folder');
                }

                parentId = response.root.id;
            }

            applyImportedNodes(response);
            importedCount += response.files.length;
            skippedCount += batch.length - response.files.length;
        } catch (error) {
            skippedCount += batch.length;
            failedBatch = true;

            if (rootName && index === 0) {
                break;
            }

            if (error instanceof AxiosError && error.response?.status === 413) {
                status.value = 'A batch exceeded the server upload limit';
            }
        }

        processedCount += batch.length;
        progress.value = Math.round((processedCount / validFiles.length) * 100);
    }

    processing.value = false;

    if (importedCount > 0) {
        createToast(
            skippedCount > 0
                ? `${importedCount} files imported, ${skippedCount} skipped`
                : `${importedCount} ${importedCount === 1 ? 'file' : 'files'} imported`,
            skippedCount > 0 ? 'warning' : 'success',
            4000
        );
        closeModal();
    } else {
        status.value = failedBatch ? 'Upload failed. Please try again.' : 'No files were imported.';
        createToast(status.value, 'error');
    }
}

function selectedFiles(files: FileList, folder: boolean): SelectedFile[] {
    return Array.from(files).map(file => {
        const path = folder && file.webkitRelativePath ? file.webkitRelativePath : file.name;
        const segments = path.split('/');

        return {
            file,
            relativePath: folder ? segments.slice(1).join('/') || file.name : file.name,
        };
    });
}

function handleFileSelection(event: Event): void {
    const input = event.target as HTMLInputElement;

    if (input.files) {
        void importFiles(selectedFiles(input.files, false));
    }
}

function handleFolderSelection(event: Event): void {
    const input = event.target as HTMLInputElement;

    if (!input.files || input.files.length === 0) {
        return;
    }

    const firstPath = input.files[0].webkitRelativePath;
    const rootName = firstPath.split('/')[0] || 'Uploaded folder';

    void importFiles(selectedFiles(input.files, true), rootName);
}

function drop(event: DragEvent): void {
    if (event.dataTransfer?.files.length) {
        void importFiles(selectedFiles(event.dataTransfer.files, false));
    }
}

onMounted(() => {
    if (props.dropEvent) {
        drop(props.dropEvent);
    }
});
</script>

<template>
    <div class="flex flex-col gap-4" :inert="processing">
        <div
            class="border-light-base-300 dark:border-base-500 flex h-52 w-full flex-col items-center justify-center gap-3 rounded-lg border-2 border-dashed px-6 text-center"
            @dragover.prevent
            @drop.prevent="drop"
        >
            <h6 class="font-semibold">Drop files here or choose what to import</h6>
            <span class="text-sm">Any file type · up to {{ uploadMaxFilesize }} per file</span>

            <div class="flex flex-wrap justify-center gap-2">
                <SecondaryButton :disabled="processing" @click="fileUpload?.click()">
                    Choose files
                </SecondaryButton>
                <SecondaryButton :disabled="processing" @click="folderUpload?.click()">
                    Choose folder
                </SecondaryButton>
            </div>

            <input
                ref="fileUpload"
                type="file"
                multiple
                class="hidden"
                @change="handleFileSelection"
            />
            <input
                ref="folderUpload"
                type="file"
                multiple
                webkitdirectory
                class="hidden"
                @change="handleFolderSelection"
            />
        </div>

        <div v-if="processing || status" class="flex flex-col gap-2 text-sm">
            <div class="flex items-center justify-between gap-3">
                <span class="flex items-center gap-2">
                    <Spinner v-if="processing" class="h-4 w-4 animate-spin" />
                    {{ status }}
                </span>
                <span v-if="processing">{{ progress }}%</span>
            </div>
            <progress v-if="processing" class="h-1 w-full" :value="progress" max="100"></progress>
        </div>
    </div>
</template>
