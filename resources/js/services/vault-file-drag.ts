import type { VaultNode } from '@/types/vault';

export const vaultFileDataType = 'application/vault-file';

export interface VaultFileDragPayload {
    name: string;
    url: string;
    type: VaultNode['type'];
}

const vaultFileTypes: VaultNode['type'][] = [
    'audio',
    'file',
    'folder',
    'image',
    'note',
    'pdf',
    'text',
    'video',
];

function isVaultFileType(value: unknown): value is VaultNode['type'] {
    return typeof value === 'string' && vaultFileTypes.includes(value as VaultNode['type']);
}

export function parseVaultFileDrag(dataTransfer: DataTransfer | null): VaultFileDragPayload | null {
    const raw = dataTransfer?.getData(vaultFileDataType);

    if (!raw) {
        return null;
    }

    try {
        const value = JSON.parse(raw) as Partial<VaultFileDragPayload>;

        if (
            typeof value.name !== 'string' ||
            value.name === '' ||
            typeof value.url !== 'string' ||
            value.url === '' ||
            !isVaultFileType(value.type)
        ) {
            return null;
        }

        return {
            name: value.name,
            url: value.url,
            type: value.type,
        };
    } catch {
        return null;
    }
}

function markdownLabel(value: string): string {
    return value.replaceAll('\\', '\\\\').replaceAll('[', '\\[').replaceAll(']', '\\]');
}

export function vaultFileMarkdown(file: VaultFileDragPayload): string {
    const label = markdownLabel(file.name);

    return file.type === 'image' ? `![${label}](${file.url})` : `[${label}](${file.url})`;
}
