<script setup lang="ts">
import ArrowDownTray from '@/icons/ArrowDownTray.vue';
import FileGeneric from '@/icons/FileGeneric.vue';
import { VaultNode } from '@/types/vault';

defineProps<{
    node: VaultNode;
}>();
</script>

<template>
    <div class="flex h-full w-full items-center justify-center px-4 pb-12">
        <div
            class="border-light-base-300 dark:border-base-600 flex max-w-md flex-col items-center gap-4 rounded-lg border p-8 text-center"
        >
            <FileGeneric class="h-12 w-12 opacity-60" />
            <div>
                <div class="font-semibold">
                    {{ node.name }}{{ node.extension ? `.${node.extension}` : '' }}
                </div>
                <div class="text-light-base-700 dark:text-base-300 mt-1 text-sm">
                    This file is stored safely and is available for download.
                </div>
                <div v-if="node.mime_type" class="mt-1 text-xs opacity-60">
                    {{ node.mime_type }}
                </div>
            </div>
            <a
                :href="node.url"
                class="bg-primary-600 hover:bg-primary-500 text-light-base-50 flex items-center gap-2 rounded px-4 py-2 text-sm font-semibold"
            >
                <ArrowDownTray class="h-4 w-4" />
                Download
            </a>
        </div>
    </div>
</template>
