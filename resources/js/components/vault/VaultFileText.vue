<script setup lang="ts">
import VaultNodeController from '@/actions/App/Http/Controllers/VaultNodeController';
import { useAxiosForm } from '@/composables/useAxiosForm';
import { useToast } from '@/composables/useToast';
import { useLayoutStore } from '@/stores/layout';
import { VaultNode } from '@/types/vault';
import { ref, watch } from 'vue';

const props = defineProps<{
    node: VaultNode;
}>();

const emit = defineEmits<{
    contentUpdated: [content: string];
}>();

const layoutStore = useLayoutStore();
const form = useAxiosForm({});
const { createToast } = useToast();
const content = ref(props.node.content ?? '');
let debounceTimer: ReturnType<typeof setTimeout> | null = null;

function save(): void {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }

    debounceTimer = setTimeout(() => {
        layoutStore.setVaultNodeUpdating(true);

        form.send({
            url: VaultNodeController.update.url({
                vault: props.node.vault_id,
                node: props.node.id,
            }),
            method: 'patch',
            data: { content: content.value },
            onError: error => {
                content.value = props.node.content ?? '';
                createToast(error.response?.statusText ?? 'Unable to save file', 'error');
            },
            onSuccess: (response: { data: VaultNode }) => {
                content.value = response.data.content ?? '';
                emit('contentUpdated', content.value);
            },
            onFinish: () => layoutStore.setVaultNodeUpdating(false),
        });
    }, 1000);
}

watch(
    () => props.node.content,
    value => {
        if (value !== null && value !== content.value) {
            content.value = value;
        }
    }
);
</script>

<template>
    <div class="h-full w-full px-4 pb-4">
        <textarea
            v-model="content"
            class="border-light-base-300 dark:border-base-600 bg-light-base-100 dark:bg-base-900 h-full min-h-64 w-full resize-none rounded border p-4 font-mono text-sm leading-6 focus:ring-1 focus:outline-none"
            spellcheck="false"
            autocomplete="off"
            @input="save"
        ></textarea>
    </div>
</template>
