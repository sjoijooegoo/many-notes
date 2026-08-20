<script setup lang="ts">
import CheckboxToggle from '@/components/form/CheckboxToggle.vue';
import ModelInput from '@/components/form/ModelInput.vue';
import Submit from '@/components/form/Submit.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import { useAxiosForm } from '@/composables/useAxiosForm';
import { useModalManager } from '@/composables/useModalManager';
import { useToast } from '@/composables/useToast';
import DocumentDuplicate from '@/icons/DocumentDuplicate.vue';
import Spinner from '@/icons/Spinner.vue';
import Trash from '@/icons/Trash.vue';
import { formatExtendedDate } from '@/utils/time';
import axios from 'axios';
import { onMounted, ref } from 'vue';
import AxiosFormConfirmationModal from './AxiosFormConfirmationModal.vue';

interface McpTokenMetadata {
    id: number;
    name: string;
    read_all_vaults: boolean;
    can_write: boolean;
    created_at: string;
    last_used_at: string | null;
    expires_at: string | null;
    expired: boolean;
}

interface McpTokenListResponse {
    data: {
        endpoint: string;
        vault: {
            id: number;
            name: string;
        };
        tokens: McpTokenMetadata[];
    };
}

interface McpTokenCreateResponse {
    data: {
        token: string;
        metadata: McpTokenMetadata;
    };
}

const props = defineProps<{
    vaultId: number;
}>();

const { openModal } = useModalManager();
const { createToast } = useToast();
const baseUrl = `/vaults/${props.vaultId}/mcp-tokens`;
const endpoint = ref('');
const vaultName = ref('');
const tokens = ref<McpTokenMetadata[]>([]);
const createdToken = ref<string | null>(null);
const loading = ref(true);

const form = useAxiosForm<{
    name: string;
    expires: number;
    read_all_vaults: boolean;
    can_write: boolean;
}>({
    name: '',
    expires: 365,
    read_all_vaults: false,
    can_write: true,
});

async function loadTokens(): Promise<void> {
    loading.value = true;

    try {
        const response = await axios.get<McpTokenListResponse>(baseUrl);
        endpoint.value = response.data.data.endpoint;
        vaultName.value = response.data.data.vault.name;
        tokens.value = response.data.data.tokens;
    } catch {
        createToast('Could not load MCP API tokens', 'error');
    } finally {
        loading.value = false;
    }
}

function createToken(): void {
    createdToken.value = null;
    form.send<McpTokenCreateResponse>({
        url: baseUrl,
        method: 'post',
        onError: error => {
            const message = error.response?.statusText ?? 'Could not create MCP API token';
            createToast(message, 'error');
        },
        onSuccess: payload => {
            createdToken.value = payload.data.token;
            tokens.value = [payload.data.metadata, ...tokens.value];
            form.reset();
            createToast('MCP API token created', 'success');
        },
    });
}

function revokeToken(token: McpTokenMetadata): void {
    openModal(AxiosFormConfirmationModal, {
        title: 'Revoke MCP API token',
        url: `${baseUrl}/${token.id}`,
        method: 'delete',
        content: `Revoke “${token.name}”? Any client using it will lose access immediately.`,
        successMessage: 'MCP API token revoked',
        onSuccess: () => {
            tokens.value = tokens.value.filter(item => item.id !== token.id);
        },
    });
}

async function copyText(text: string, label: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        createToast(`${label} copied`, 'success');
    } catch {
        createToast(`Could not copy ${label.toLowerCase()}`, 'error');
    }
}

onMounted(loadTokens);
</script>

<template>
    <div v-if="loading" class="flex min-h-40 items-center justify-center">
        <Spinner class="h-6 w-6 animate-spin opacity-70" />
    </div>

    <div v-else class="flex flex-col gap-6">
        <section class="flex flex-col gap-2">
            <h4 class="font-semibold">Connection</h4>
            <p class="text-light-base-600 dark:text-base-400 text-sm">
                Tokens created here belong to your account. Existing token secrets cannot be shown
                again.
            </p>
            <div
                class="border-light-base-300 dark:border-base-600 bg-light-base-100 dark:bg-base-800 flex items-center gap-2 rounded-lg border px-3 py-2"
            >
                <code class="min-w-0 flex-1 truncate text-sm" :title="endpoint">{{
                    endpoint
                }}</code>
                <button
                    type="button"
                    class="hover:bg-light-base-300 dark:hover:bg-base-600 shrink-0 rounded p-1"
                    title="Copy MCP endpoint"
                    aria-label="Copy MCP endpoint"
                    @click="copyText(endpoint, 'MCP endpoint')"
                >
                    <DocumentDuplicate class="h-4 w-4" />
                </button>
            </div>
        </section>

        <section
            v-if="createdToken"
            class="border-warning-600/40 bg-warning-600/10 flex flex-col gap-3 rounded-lg border p-4"
        >
            <div>
                <h4 class="font-semibold">Copy this token now</h4>
                <p class="text-sm opacity-80">
                    It will not be shown again after this modal closes.
                </p>
            </div>
            <code
                class="bg-light-base-50 dark:bg-base-950 rounded p-3 text-sm break-all select-all"
                >{{ createdToken }}</code
            >
            <div class="flex justify-end">
                <SecondaryButton @click="copyText(createdToken, 'Token')">
                    <span class="flex items-center gap-2">
                        <DocumentDuplicate class="h-4 w-4" />
                        Copy token
                    </span>
                </SecondaryButton>
            </div>
        </section>

        <section class="flex flex-col gap-4">
            <div>
                <h4 class="font-semibold">Create token for {{ vaultName }}</h4>
                <p class="text-light-base-600 dark:text-base-400 text-sm">
                    Reading defaults to this vault. Writing is always restricted to this vault, and
                    file deletion is never available.
                </p>
            </div>

            <form
                class="flex flex-col gap-4 inert:pointer-events-none"
                autocomplete="off"
                novalidate
                :inert="form.processing"
                @submit.prevent="createToken"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <ModelInput
                        v-model="form.name"
                        name="name"
                        type="text"
                        label="Token name"
                        placeholder="Windows Codex"
                        :error="form.errors.name"
                        maxlength="80"
                        required
                        autofocus
                    />
                    <ModelInput
                        v-model="form.expires"
                        name="expires"
                        type="number"
                        label="Expires in days"
                        :error="form.errors.expires"
                        min="1"
                        max="3650"
                        required
                    />
                </div>

                <div
                    class="border-light-base-300 dark:border-base-600 flex flex-col gap-4 rounded-lg border p-4"
                >
                    <div class="flex flex-col gap-1">
                        <CheckboxToggle
                            v-model="form.read_all_vaults"
                            name="read_all_vaults"
                            label="Read every vault visible to my account"
                        />
                        <p class="text-light-base-600 dark:text-base-400 text-xs">
                            Includes accepted collaboration vaults and vaults you create or join
                            later.
                        </p>
                    </div>
                    <div class="flex flex-col gap-1">
                        <CheckboxToggle
                            v-model="form.can_write"
                            name="can_write"
                            label="Create and update notes in this vault"
                        />
                        <p class="text-light-base-600 dark:text-base-400 text-xs">
                            Does not allow writing to other vaults or deleting files.
                        </p>
                    </div>
                </div>

                <div class="flex justify-end">
                    <Submit label="Create token" :processing="form.processing" />
                </div>
            </form>
        </section>

        <section class="flex flex-col gap-3">
            <div>
                <h4 class="font-semibold">Your tokens for this vault</h4>
                <p class="text-light-base-600 dark:text-base-400 text-sm">
                    Only tokens created by your account are listed.
                </p>
            </div>

            <p
                v-if="tokens.length === 0"
                class="border-light-base-300 dark:border-base-600 rounded-lg border p-4 text-center text-sm opacity-70"
            >
                No MCP API tokens apply to this vault.
            </p>

            <div
                v-for="token in tokens"
                :key="token.id"
                class="border-light-base-300 dark:border-base-600 flex gap-3 rounded-lg border p-4"
            >
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="truncate font-medium" :title="token.name">{{ token.name }}</p>
                        <span
                            v-if="token.expired"
                            class="bg-error-500/10 text-error-700 dark:text-error-400 rounded px-2 py-0.5 text-xs"
                        >
                            Expired
                        </span>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2 text-xs">
                        <span class="bg-light-base-300 dark:bg-base-700 rounded px-2 py-1">
                            {{
                                token.read_all_vaults
                                    ? 'Read all visible vaults'
                                    : 'Read this vault'
                            }}
                        </span>
                        <span class="bg-light-base-300 dark:bg-base-700 rounded px-2 py-1">
                            {{ token.can_write ? 'Write this vault' : 'Read only' }}
                        </span>
                        <span class="bg-light-base-300 dark:bg-base-700 rounded px-2 py-1">
                            No deletion
                        </span>
                    </div>
                    <div
                        class="text-light-base-600 dark:text-base-400 mt-3 grid gap-1 text-xs sm:grid-cols-2"
                    >
                        <span>Created {{ formatExtendedDate(token.created_at) }}</span>
                        <span>
                            Last used
                            {{
                                token.last_used_at
                                    ? formatExtendedDate(token.last_used_at)
                                    : 'never'
                            }}
                        </span>
                        <span>
                            Expires
                            {{ token.expires_at ? formatExtendedDate(token.expires_at) : 'never' }}
                        </span>
                    </div>
                </div>
                <button
                    type="button"
                    class="hover:bg-error-500/10 hover:text-error-700 dark:hover:text-error-400 h-fit shrink-0 rounded p-2"
                    title="Revoke token"
                    aria-label="Revoke token"
                    @click="revokeToken(token)"
                >
                    <Trash class="h-4 w-4" />
                </button>
            </div>
        </section>
    </div>
</template>
