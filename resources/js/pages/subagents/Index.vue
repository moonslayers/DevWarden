<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import SubAgentController from '@/actions/App/Http/Controllers/SubAgentController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import StatChart from '@/components/StatChart.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
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
import type { StatDataset } from '@/composables/useChartPalette';
import { index } from '@/routes/subagents';

const NO_PROVIDER = 'none';

interface SubAgent {
    id: number;
    name: string;
    slug: string;
    type: 'vision' | 'general';
    description: string | null;
    system_prompt: string | null;
    ai_provider_id: number | null;
    model: string | null;
    is_active: boolean;
    is_system: boolean;
    uses_system_provider: boolean;
    sort_order: number;
    invocations: number;
    tokens: number;
}

interface ProviderOption {
    id: number;
    label: string;
    base_url: string | null;
    is_main: boolean;
}

interface TypeOption {
    value: string;
    label: string;
}

interface DailyPoint {
    date: string;
    total: number;
}

interface Stats {
    total: number;
    active: number;
    visionActive: boolean;
    generalCount: number;
    totalInvocations: number;
    totalTokens: number;
    invocationsByKind: { describe: number; ask: number };
    invocationsLast14d: DailyPoint[];
    tokensLast14d: DailyPoint[];
}

const props = defineProps<{
    subAgents: SubAgent[];
    providers: ProviderOption[];
    types: TypeOption[];
    stats: Stats;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Sub-agents',
                href: index(),
            },
        ],
    },
});

const newProvider = ref<string>(NO_PROVIDER);
const newActive = ref(false);

const providerState = reactive<Record<number, string>>(
    Object.fromEntries(
        props.subAgents.map((subAgent) => [
            subAgent.id,
            subAgent.ai_provider_id !== null
                ? String(subAgent.ai_provider_id)
                : NO_PROVIDER,
        ]),
    ),
);

const activeState = reactive<Record<number, boolean>>(
    Object.fromEntries(
        props.subAgents.map((subAgent) => [subAgent.id, subAgent.is_active]),
    ),
);

const editingState = reactive<Record<number, boolean>>({});

const typeFilter = ref<string>('all');

const filteredSubAgents = computed(() => {
    if (typeFilter.value === 'all') {
        return props.subAgents;
    }

    return props.subAgents.filter(
        (subAgent) => subAgent.type === typeFilter.value,
    );
});

function providerValue(subAgentId: number): string {
    const value = providerState[subAgentId];

    if (value === undefined || value === NO_PROVIDER) {
        return '';
    }

    return value;
}

function toggleEdit(subAgentId: number): void {
    if (editingState[subAgentId]) {
        editingState[subAgentId] = false;

        return;
    }

    Object.keys(editingState).forEach((id) => {
        editingState[Number(id)] = false;
    });

    editingState[subAgentId] = true;
}

function closeEdit(subAgentId: number): void {
    editingState[subAgentId] = false;
}

function typeLabel(value: string): string {
    return props.types.find((type) => type.value === value)?.label ?? value;
}

function formatNumber(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}

function formatShortDate(value: string): string {
    return new Date(`${value}T00:00:00`).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
    });
}

const invocationLabels = computed(() =>
    props.stats.invocationsLast14d.map((point) => formatShortDate(point.date)),
);

const invocationDatasets = computed<StatDataset[]>(() => [
    {
        label: 'Invocations',
        data: props.stats.invocationsLast14d.map((point) => point.total),
    },
]);

const tokenLabels = computed(() =>
    props.stats.tokensLast14d.map((point) => formatShortDate(point.date)),
);

const tokenDatasets = computed<StatDataset[]>(() => [
    {
        label: 'Tokens',
        data: props.stats.tokensLast14d.map((point) => point.total),
    },
]);

const hasInvocationActivity = computed(() =>
    props.stats.invocationsLast14d.some((point) => point.total > 0),
);

const hasTokenActivity = computed(() =>
    props.stats.tokensLast14d.some((point) => point.total > 0),
);

const invocations14d = computed(() =>
    props.stats.invocationsLast14d.reduce((sum, point) => sum + point.total, 0),
);

const tokens14d = computed(() =>
    props.stats.tokensLast14d.reduce((sum, point) => sum + point.total, 0),
);

const kpiStats = computed(() => [
    { label: 'Total sub-agents', value: props.stats.total },
    { label: 'Active', value: props.stats.active },
    {
        label: 'Invocations (14d)',
        value: invocations14d.value,
        subtitle: `describe ${props.stats.invocationsByKind.describe} · ask ${props.stats.invocationsByKind.ask}`,
    },
    { label: 'Tokens (14d)', value: tokens14d.value },
]);
</script>

<template>
    <Head title="Sub-agents" />

    <h1 class="sr-only">Sub-agents</h1>

    <div class="flex flex-col space-y-8">
        <section class="flex flex-col space-y-4">
            <Heading
                variant="small"
                title="Sub-agents"
                description="Specialized AI agents the bot delegates tasks to"
            />

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card
                    v-for="{ label, value, subtitle } in kpiStats"
                    :key="label"
                    data-test="subagents-kpi-card"
                >
                    <CardContent class="space-y-1">
                        <p class="text-sm text-muted-foreground">{{ label }}</p>
                        <p class="text-2xl font-semibold tracking-tight">
                            {{ formatNumber(value) }}
                        </p>
                        <p
                            v-if="subtitle"
                            class="text-xs text-muted-foreground"
                        >
                            {{ subtitle }}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <section class="flex flex-col space-y-4">
            <div class="grid gap-4 lg:grid-cols-2">
                <Card data-test="subagents-invocations-chart-card">
                    <CardHeader>
                        <CardTitle>Invocations per day</CardTitle>
                        <CardDescription>
                            Sub-agent calls over the last 14 days
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <StatChart
                            v-if="hasInvocationActivity"
                            type="bar"
                            :labels="invocationLabels"
                            :datasets="invocationDatasets"
                            :height="240"
                        />
                        <p
                            v-else
                            class="py-8 text-center text-sm text-muted-foreground"
                        >
                            No invocations recorded in the last 14 days.
                        </p>
                    </CardContent>
                </Card>

                <Card data-test="subagents-tokens-chart-card">
                    <CardHeader>
                        <CardTitle>Tokens per day</CardTitle>
                        <CardDescription>
                            Token consumption over the last 14 days
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <StatChart
                            v-if="hasTokenActivity"
                            type="line"
                            :labels="tokenLabels"
                            :datasets="tokenDatasets"
                            :height="240"
                        />
                        <p
                            v-else
                            class="py-8 text-center text-sm text-muted-foreground"
                        >
                            No token usage recorded in the last 14 days.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <section class="flex flex-col space-y-4">
            <Heading
                variant="small"
                title="New sub-agent"
                description="Create a general-purpose sub-agent the bot can delegate to"
            />

            <Card>
                <CardContent class="pt-6">
                    <Form
                        v-bind="SubAgentController.store.form()"
                        class="space-y-6"
                        v-slot="{ errors, processing }"
                    >
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="new_name">Name</Label>
                                <Input
                                    id="new_name"
                                    class="mt-1 block w-full"
                                    name="name"
                                    placeholder="e.g. Research assistant"
                                />
                                <InputError
                                    class="mt-2"
                                    :message="errors.name"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="new_slug">Slug</Label>
                                <Input
                                    id="new_slug"
                                    class="mt-1 block w-full"
                                    name="slug"
                                    placeholder="research-assistant"
                                />
                                <InputError
                                    class="mt-2"
                                    :message="errors.slug"
                                />
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label for="new_description">Description</Label>
                            <Input
                                id="new_description"
                                class="mt-1 block w-full"
                                name="description"
                                placeholder="What this sub-agent does"
                            />
                            <InputError
                                class="mt-2"
                                :message="errors.description"
                            />
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label>Provider</Label>
                                <p class="text-sm text-muted-foreground">
                                    System (provider principal) usa las
                                    credenciales del proveedor principal (el
                                    primero habilitado en el failover) con el
                                    Model que definas. Para visión usa un
                                    modelo con soporte de imágenes.
                                </p>
                                <Select v-model="newProvider">
                                    <SelectTrigger class="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem :value="NO_PROVIDER">
                                            System (provider principal)
                                        </SelectItem>
                                        <SelectItem
                                            v-for="provider in providers"
                                            :key="provider.id"
                                            :value="String(provider.id)"
                                        >
                                            {{ provider.label
                                            }}{{
                                                provider.is_main
                                                    ? ' · Principal'
                                                    : ''
                                            }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <input
                                    type="hidden"
                                    name="ai_provider_id"
                                    :value="
                                        newProvider === NO_PROVIDER
                                            ? ''
                                            : newProvider
                                    "
                                />
                                <InputError
                                    class="mt-2"
                                    :message="errors.ai_provider_id"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="new_model">Model</Label>
                                <Input
                                    id="new_model"
                                    class="mt-1 block w-full"
                                    name="model"
                                    placeholder="gpt-4o-mini"
                                />
                                <InputError
                                    class="mt-2"
                                    :message="errors.model"
                                />
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label for="new_system_prompt">System prompt</Label>
                            <textarea
                                id="new_system_prompt"
                                name="system_prompt"
                                class="mt-1 block min-h-28 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                                rows="4"
                                placeholder="Instructions this sub-agent follows"
                            />
                            <InputError
                                class="mt-2"
                                :message="errors.system_prompt"
                            />
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <div class="space-y-0.5">
                                <Label>Active</Label>
                                <p class="text-sm text-muted-foreground">
                                    Make this sub-agent available to the bot.
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <Switch
                                    v-model="newActive"
                                    aria-label="Active"
                                />
                                <input
                                    type="hidden"
                                    name="is_active"
                                    :value="newActive ? '1' : '0'"
                                />
                            </div>
                        </div>
                        <InputError class="mt-2" :message="errors.is_active" />

                        <div class="flex items-center gap-4">
                            <Button
                                :disabled="processing"
                                data-test="add-subagent-button"
                            >
                                Create sub-agent
                            </Button>
                        </div>
                    </Form>
                </CardContent>
            </Card>
        </section>

        <section class="flex flex-col space-y-4">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <Heading
                    variant="small"
                    title="Sub-agents"
                    description="Edit configuration and monitor usage per sub-agent"
                />

                <div class="grid gap-2">
                    <Label>Type</Label>
                    <Select v-model="typeFilter" class="w-40">
                        <SelectTrigger class="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All types</SelectItem>
                            <SelectItem
                                v-for="type in types"
                                :key="type.value"
                                :value="type.value"
                            >
                                {{ type.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <div
                v-if="filteredSubAgents.length === 0"
                class="text-sm text-muted-foreground"
            >
                No sub-agents match this type.
            </div>

            <Card
                v-for="subAgent in filteredSubAgents"
                :key="subAgent.id"
                data-test="subagent-card"
            >
                <CardHeader class="pb-3">
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <CardTitle class="flex items-center gap-2 text-sm">
                                {{ subAgent.name }}
                            </CardTitle>
                            <Badge
                                v-if="subAgent.is_system"
                                variant="secondary"
                            >
                                System / Default
                            </Badge>
                            <Badge variant="outline">
                                {{ typeLabel(subAgent.type) }}
                            </Badge>
                            <Badge
                                :variant="
                                    subAgent.is_active ? 'default' : 'outline'
                                "
                            >
                                {{ subAgent.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </div>
                        <div
                            class="flex flex-wrap items-center gap-4 text-sm text-muted-foreground"
                        >
                            <span>
                                {{ formatNumber(subAgent.invocations) }}
                                {{
                                    subAgent.invocations === 1
                                        ? 'call'
                                        : 'calls'
                                }}
                            </span>
                            <span>
                                {{ formatNumber(subAgent.tokens) }} tokens
                            </span>
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                data-test="edit-subagent-button"
                                @click="toggleEdit(subAgent.id)"
                            >
                                {{ editingState[subAgent.id] ? 'Cancelar' : 'Editar' }}
                            </Button>
                        </div>
                    </div>
                    <CardDescription v-if="subAgent.description">
                        {{ subAgent.description }}
                    </CardDescription>
                </CardHeader>

                <CardContent
                    v-if="editingState[subAgent.id]"
                    class="space-y-6"
                >
                    <Form
                        v-bind="SubAgentController.update.form(subAgent.id)"
                        class="space-y-6"
                        :on-success="() => closeEdit(subAgent.id)"
                        v-slot="{ errors, processing }"
                    >
                        <input
                            type="hidden"
                            name="sort_order"
                            :value="subAgent.sort_order"
                        />

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label :for="`name_${subAgent.id}`">Name</Label>
                                <Input
                                    :id="`name_${subAgent.id}`"
                                    class="mt-1 block w-full"
                                    name="name"
                                    :default-value="subAgent.name"
                                    :readonly="subAgent.is_system"
                                />
                                <InputError
                                    class="mt-2"
                                    :message="errors.name"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label :for="`slug_${subAgent.id}`">Slug</Label>
                                <Input
                                    :id="`slug_${subAgent.id}`"
                                    class="mt-1 block w-full"
                                    name="slug"
                                    :default-value="subAgent.slug"
                                    :readonly="subAgent.is_system"
                                />
                                <InputError
                                    class="mt-2"
                                    :message="errors.slug"
                                />
                            </div>
                        </div>

                        <div v-if="!subAgent.is_system" class="grid gap-2">
                            <Label :for="`description_${subAgent.id}`">
                                Description
                            </Label>
                            <Input
                                :id="`description_${subAgent.id}`"
                                class="mt-1 block w-full"
                                name="description"
                                :default-value="subAgent.description ?? ''"
                            />
                            <InputError
                                class="mt-2"
                                :message="errors.description"
                            />
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label>Provider</Label>
                                <p class="text-sm text-muted-foreground">
                                    System (provider principal) usa las
                                    credenciales del proveedor principal (el
                                    primero habilitado en el failover) con el
                                    Model que definas. Para visión usa un
                                    modelo con soporte de imágenes.
                                </p>
                                <Select v-model="providerState[subAgent.id]">
                                    <SelectTrigger class="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem :value="NO_PROVIDER">
                                            System (provider principal)
                                        </SelectItem>
                                        <SelectItem
                                            v-for="provider in providers"
                                            :key="provider.id"
                                            :value="String(provider.id)"
                                        >
                                            {{ provider.label
                                            }}{{
                                                provider.is_main
                                                    ? ' · Principal'
                                                    : ''
                                            }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <input
                                    type="hidden"
                                    name="ai_provider_id"
                                    :value="providerValue(subAgent.id)"
                                />
                                <InputError
                                    class="mt-2"
                                    :message="errors.ai_provider_id"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label :for="`model_${subAgent.id}`"
                                    >Model</Label
                                >
                                <Input
                                    :id="`model_${subAgent.id}`"
                                    class="mt-1 block w-full"
                                    name="model"
                                    :default-value="subAgent.model ?? ''"
                                />
                                <InputError
                                    class="mt-2"
                                    :message="errors.model"
                                />
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label :for="`system_prompt_${subAgent.id}`">
                                System prompt
                            </Label>
                            <textarea
                                :id="`system_prompt_${subAgent.id}`"
                                name="system_prompt"
                                :value="subAgent.system_prompt ?? ''"
                                class="mt-1 block min-h-28 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                                rows="4"
                            />
                            <InputError
                                class="mt-2"
                                :message="errors.system_prompt"
                            />
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <div class="space-y-0.5">
                                <Label>Active</Label>
                                <p class="text-sm text-muted-foreground">
                                    Enable this sub-agent for delegation.
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <Switch
                                    v-model="activeState[subAgent.id]"
                                    aria-label="Active"
                                />
                                <input
                                    type="hidden"
                                    name="is_active"
                                    :value="
                                        (activeState[subAgent.id] ?? false)
                                            ? '1'
                                            : '0'
                                    "
                                />
                            </div>
                        </div>
                        <InputError class="mt-2" :message="errors.is_active" />

                        <div class="flex items-center gap-4">
                            <Button
                                :disabled="processing"
                                data-test="update-subagent-button"
                            >
                                Save
                            </Button>
                        </div>
                    </Form>

                    <Dialog v-if="!subAgent.is_system">
                        <DialogTrigger as-child>
                            <Button
                                type="button"
                                variant="destructive"
                                data-test="delete-subagent-button"
                            >
                                Delete
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <Form
                                v-bind="
                                    SubAgentController.destroy.form(
                                        subAgent.id,
                                    )
                                "
                                :options="{ preserveScroll: true }"
                                class="space-y-6"
                                v-slot="{ processing: deleting }"
                            >
                                <DialogHeader class="space-y-3">
                                    <DialogTitle>
                                        Delete this sub-agent?
                                    </DialogTitle>
                                    <DialogDescription>
                                        This sub-agent will be
                                        permanently removed and the bot
                                        will stop delegating to it.
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter class="gap-2">
                                    <DialogClose as-child>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                        >
                                            Cancel
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        :disabled="deleting"
                                        data-test="confirm-delete-subagent-button"
                                    >
                                        Delete sub-agent
                                    </Button>
                                </DialogFooter>
                            </Form>
                        </DialogContent>
                    </Dialog>
                </CardContent>
            </Card>
        </section>
    </div>
</template>
