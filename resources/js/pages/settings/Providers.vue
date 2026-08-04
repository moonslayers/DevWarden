<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import AiProviderController from '@/actions/App/Http/Controllers/Settings/AiProviderController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { index } from '@/routes/providers';

interface ProviderType {
    value: string;
    label: string;
}

interface Provider {
    id: number;
    provider: string;
    is_enabled: boolean;
    base_url: string | null;
    model_text: string | null;
    failover_order: number;
    has_api_key: boolean;
}

const props = defineProps<{
    providers: Provider[];
    provider_types: ProviderType[];
}>();

const newProviderType = ref<string>(props.provider_types[0]?.value ?? 'openai');
const newProviderEnabled = ref(true);

const enabledState = reactive<Record<number, boolean>>(
    Object.fromEntries(props.providers.map((provider) => [provider.id, provider.is_enabled])),
);

function providerLabel(value: string): string {
    return props.provider_types.find((type) => type.value === value)?.label ?? value;
}

function testConnection(provider: Provider): void {
    router.post(AiProviderController.test.url(provider.id), {
        preserveScroll: true,
    });
}

function deleteProvider(provider: Provider): void {
    router.delete(AiProviderController.destroy.url(provider.id), {
        preserveScroll: true,
    });
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'AI Providers',
                href: index(),
            },
        ],
    },
});
</script>

<template>
    <Head title="AI Providers" />

    <h1 class="sr-only">AI Providers</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="AI Providers"
            description="Configure the AI providers used by the bot"
        />

        <Form
            v-bind="AiProviderController.store.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label>Provider type</Label>
                <Select v-model="newProviderType">
                    <SelectTrigger class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="type in provider_types"
                            :key="type.value"
                            :value="type.value"
                        >
                            {{ type.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <input type="hidden" name="provider" :value="newProviderType" />
                <InputError class="mt-2" :message="errors.provider" />
            </div>

            <div class="grid gap-2">
                <Label for="new_api_key">API key</Label>
                <Input
                    id="new_api_key"
                    class="mt-1 block w-full"
                    name="api_key"
                    type="password"
                    autocomplete="new-password"
                    placeholder="Paste the provider API key"
                />
                <InputError class="mt-2" :message="errors.api_key" />
            </div>

            <div v-if="newProviderType === 'openai-compatible'" class="grid gap-2">
                <Label for="new_base_url">Base URL</Label>
                <Input
                    id="new_base_url"
                    class="mt-1 block w-full"
                    name="base_url"
                    placeholder="https://llm.example.com/v1"
                />
                <InputError class="mt-2" :message="errors.base_url" />
            </div>

            <div class="grid gap-2">
                <Label for="new_model_text">Model</Label>
                <Input
                    id="new_model_text"
                    class="mt-1 block w-full"
                    name="model_text"
                    placeholder="gpt-4o-mini"
                />
                <InputError class="mt-2" :message="errors.model_text" />
            </div>

            <div class="flex items-center justify-between gap-4">
                <div class="space-y-0.5">
                    <Label>Enabled</Label>
                    <p class="text-sm text-muted-foreground">
                        Include this provider in the failover chain.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Switch v-model:checked="newProviderEnabled" aria-label="Enabled" />
                    <input
                        type="hidden"
                        name="is_enabled"
                        :value="newProviderEnabled ? '1' : '0'"
                    />
                </div>
            </div>
            <InputError class="mt-2" :message="errors.is_enabled" />

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="add-provider-button">
                    Add provider
                </Button>
            </div>
        </Form>

        <div v-if="providers.length === 0" class="text-sm text-muted-foreground">
            No AI providers configured yet.
        </div>

        <div
            v-for="provider in providers"
            :key="provider.id"
            class="space-y-4 rounded-lg border p-4"
        >
            <Form
                v-bind="AiProviderController.update.form(provider.id)"
                class="space-y-4"
                v-slot="{ errors, processing }"
            >
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="font-medium">{{ providerLabel(provider.provider) }}</p>
                        <p class="text-sm text-muted-foreground">{{ provider.provider }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Switch
                            v-model:checked="enabledState[provider.id]"
                            aria-label="Enabled"
                        />
                        <input
                            type="hidden"
                            name="is_enabled"
                            :value="enabledState[provider.id] ? '1' : '0'"
                        />
                    </div>
                </div>
                <InputError class="mt-2" :message="errors.is_enabled" />

                <div class="grid gap-2">
                    <Label :for="`api_key_${provider.id}`">API key</Label>
                    <Input
                        :id="`api_key_${provider.id}`"
                        class="mt-1 block w-full"
                        name="api_key"
                        type="password"
                        autocomplete="new-password"
                        :placeholder="
                            provider.has_api_key
                                ? 'A key is already configured — leave blank to keep it'
                                : 'Paste the provider API key'
                        "
                    />
                    <InputError class="mt-2" :message="errors.api_key" />
                </div>

                <div v-if="provider.provider === 'openai-compatible'" class="grid gap-2">
                    <Label :for="`base_url_${provider.id}`">Base URL</Label>
                    <Input
                        :id="`base_url_${provider.id}`"
                        class="mt-1 block w-full"
                        name="base_url"
                        :default-value="provider.base_url ?? ''"
                        placeholder="https://llm.example.com/v1"
                    />
                    <InputError class="mt-2" :message="errors.base_url" />
                </div>

                <div class="grid gap-2">
                    <Label :for="`model_text_${provider.id}`">Model</Label>
                    <Input
                        :id="`model_text_${provider.id}`"
                        class="mt-1 block w-full"
                        name="model_text"
                        :default-value="provider.model_text ?? ''"
                        placeholder="gpt-4o-mini"
                    />
                    <InputError class="mt-2" :message="errors.model_text" />
                </div>

                <div class="grid gap-2">
                    <Label :for="`failover_order_${provider.id}`">Failover order</Label>
                    <Input
                        :id="`failover_order_${provider.id}`"
                        class="mt-1 block w-full"
                        name="failover_order"
                        type="number"
                        :default-value="provider.failover_order"
                        min="0"
                    />
                    <InputError class="mt-2" :message="errors.failover_order" />
                </div>

                <div class="flex items-center gap-4">
                    <Button :disabled="processing" data-test="update-provider-button">
                        Save
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        @click="testConnection(provider)"
                        data-test="test-connection-button"
                    >
                        Test connection
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        @click="deleteProvider(provider)"
                        data-test="delete-provider-button"
                    >
                        Delete
                    </Button>
                </div>
            </Form>
        </div>
    </div>
</template>
