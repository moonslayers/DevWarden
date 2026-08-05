<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Bot,
    Brain,
    Check,
    MessageCircle,
    MessagesSquare,
    Radio,
    Send,
    Sparkles,
    User,
    UsersRound,
    X,
    Zap,
} from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import StatChart from '@/components/StatChart.vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useChartPalette } from '@/composables/useChartPalette';
import type { StatDataset } from '@/composables/useChartPalette';
import { dashboard } from '@/routes';

interface ProviderHealth {
    id: number;
    provider: string;
    is_enabled: boolean;
    model_text: string | null;
    has_credentials: boolean;
    failover_order: number;
}

interface TelegramHealth {
    bot_configured: boolean;
    polling_enabled: boolean;
    allowed_users_count: number;
}

interface ActivityProps {
    total_conversations: number;
    total_messages: number;
    linked_chats: number;
    user_messages: number;
    assistant_messages: number;
    messages_by_day: {
        labels: string[];
        user: number[];
        assistant: number[];
    };
}

interface TokenTotals {
    prompt: number;
    completion: number;
    reasoning: number;
}

interface ProviderUsage {
    provider: string;
    prompt_tokens: number;
    completion_tokens: number;
    messages: number;
}

interface ModelUsage {
    model: string;
    prompt_tokens: number;
    completion_tokens: number;
    messages: number;
}

interface UsageProps {
    total_tokens: TokenTotals;
    tokens_by_day: {
        labels: string[];
        prompt: number[];
        completion: number[];
    };
    by_provider: ProviderUsage[];
    by_model: ModelUsage[];
}

const props = defineProps<{
    health: {
        providers: ProviderHealth[];
        telegram: TelegramHealth;
        owner: { name: string } | null;
    };
    activity: ActivityProps;
    usage: UsageProps;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const palette = useChartPalette();

function formatNumber(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}

function dotColor(index: number): string {
    return palette.value[index % palette.value.length];
}

function statusClass(active: boolean): string {
    return active
        ? 'flex items-center gap-1 text-emerald-600 dark:text-emerald-400'
        : 'flex items-center gap-1 text-red-600 dark:text-red-400';
}

const activityStats = computed(() => [
    {
        label: 'Conversations',
        value: props.activity.total_conversations,
        Icon: MessagesSquare,
    },
    {
        label: 'Messages',
        value: props.activity.total_messages,
        Icon: MessageCircle,
    },
    {
        label: 'Linked chats',
        value: props.activity.linked_chats,
        Icon: UsersRound,
    },
    { label: 'User messages', value: props.activity.user_messages, Icon: User },
    {
        label: 'Assistant messages',
        value: props.activity.assistant_messages,
        Icon: Bot,
    },
]);

const usageStats = computed(() => [
    {
        label: 'Prompt tokens',
        value: props.usage.total_tokens.prompt,
        Icon: Zap,
    },
    {
        label: 'Completion tokens',
        value: props.usage.total_tokens.completion,
        Icon: Sparkles,
    },
    {
        label: 'Reasoning tokens',
        value: props.usage.total_tokens.reasoning,
        Icon: Brain,
    },
]);

const messageDatasets = computed<StatDataset[]>(() => [
    { label: 'User', data: props.activity.messages_by_day.user },
    { label: 'Assistant', data: props.activity.messages_by_day.assistant },
]);

const tokenDatasets = computed<StatDataset[]>(() => [
    { label: 'Prompt', data: props.usage.tokens_by_day.prompt },
    { label: 'Completion', data: props.usage.tokens_by_day.completion },
]);

const hasDailyActivity = computed(
    () =>
        props.activity.messages_by_day.user.some((value) => value > 0) ||
        props.activity.messages_by_day.assistant.some((value) => value > 0),
);

const hasTokenActivity = computed(
    () =>
        props.usage.tokens_by_day.prompt.some((value) => value > 0) ||
        props.usage.tokens_by_day.completion.some((value) => value > 0),
);

const providerUsage = computed(() =>
    props.usage.by_provider.map((row) => ({
        label: row.provider,
        tokens: row.prompt_tokens + row.completion_tokens,
    })),
);

const modelUsage = computed(() =>
    props.usage.by_model.map((row) => ({
        label: row.model,
        tokens: row.prompt_tokens + row.completion_tokens,
    })),
);

const providerTotalTokens = computed(() =>
    providerUsage.value.reduce((sum, row) => sum + row.tokens, 0),
);

const modelTotalTokens = computed(() =>
    modelUsage.value.reduce((sum, row) => sum + row.tokens, 0),
);

const providerDonutLabels = computed(() =>
    providerUsage.value.map((row) => row.label),
);

const modelDonutLabels = computed(() =>
    modelUsage.value.map((row) => row.label),
);

const providerDonutDatasets = computed<StatDataset[]>(() => [
    { label: 'Tokens', data: providerUsage.value.map((row) => row.tokens) },
]);

const modelDonutDatasets = computed<StatDataset[]>(() => [
    { label: 'Tokens', data: modelUsage.value.map((row) => row.tokens) },
]);

function shareOf(tokens: number, total: number): string {
    return total > 0 ? `${Math.round((tokens / total) * 100)}%` : '0%';
}
</script>

<template>
    <Head title="Dashboard" />

    <h1 class="sr-only">Dashboard</h1>

    <div class="flex flex-col space-y-8">
        <section class="flex flex-col space-y-4">
            <Heading
                variant="small"
                title="Health"
                description="AI providers, Telegram connection and bot owner"
            />

            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Card v-for="provider in health.providers" :key="provider.id">
                    <CardHeader class="pb-3">
                        <div class="flex items-center justify-between gap-2">
                            <CardTitle class="flex items-center gap-2 text-sm">
                                <Bot class="h-4 w-4 text-muted-foreground" />
                                {{ provider.provider }}
                            </CardTitle>
                            <Badge
                                :variant="
                                    provider.is_enabled
                                        ? 'default'
                                        : 'secondary'
                                "
                            >
                                {{
                                    provider.is_enabled ? 'Enabled' : 'Disabled'
                                }}
                            </Badge>
                        </div>
                        <CardDescription>
                            {{ provider.model_text ?? 'No model set' }}
                        </CardDescription>
                    </CardHeader>
                    <CardContent
                        class="flex items-center justify-between gap-2 text-sm"
                    >
                        <span class="flex items-center gap-2">
                            <span
                                :class="[
                                    'h-2 w-2 rounded-full',
                                    provider.has_credentials
                                        ? 'bg-emerald-500'
                                        : 'bg-red-500',
                                ]"
                            />
                            {{
                                provider.has_credentials
                                    ? 'Credentials set'
                                    : 'Missing credentials'
                            }}
                        </span>
                        <span class="text-muted-foreground">
                            Order {{ provider.failover_order }}
                        </span>
                    </CardContent>
                </Card>

                <div
                    v-if="health.providers.length === 0"
                    class="flex items-center justify-center rounded-xl border border-dashed p-6 text-sm text-muted-foreground"
                >
                    No AI providers configured yet.
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="flex items-center gap-2 text-sm">
                            <Send class="h-4 w-4 text-muted-foreground" />
                            Telegram
                        </CardTitle>
                        <CardDescription>Bot connection status</CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-3 text-sm">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-muted-foreground"
                                >Bot configured</span
                            >
                            <span
                                :class="
                                    statusClass(health.telegram.bot_configured)
                                "
                            >
                                <Check
                                    v-if="health.telegram.bot_configured"
                                    class="h-4 w-4"
                                />
                                <X v-else class="h-4 w-4" />
                                {{
                                    health.telegram.bot_configured
                                        ? 'Yes'
                                        : 'No'
                                }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-muted-foreground"
                                >Polling enabled</span
                            >
                            <span
                                :class="
                                    statusClass(health.telegram.polling_enabled)
                                "
                            >
                                <Radio class="h-4 w-4" />
                                {{
                                    health.telegram.polling_enabled
                                        ? 'Yes'
                                        : 'No'
                                }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-muted-foreground"
                                >Allowed users</span
                            >
                            <span class="flex items-center gap-1">
                                <UsersRound class="h-4 w-4" />
                                {{ health.telegram.allowed_users_count }}
                            </span>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="health.owner">
                    <CardHeader class="pb-3">
                        <CardTitle class="flex items-center gap-2 text-sm">
                            <User class="h-4 w-4 text-muted-foreground" />
                            Bot owner
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm">{{
                        health.owner.name
                    }}</CardContent>
                </Card>
            </div>
        </section>

        <section class="flex flex-col space-y-4">
            <Heading
                variant="small"
                title="Activity"
                description="Conversation and message volume"
            />

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <Card
                    v-for="{ label, value, Icon } in activityStats"
                    :key="label"
                >
                    <CardContent
                        class="flex items-center justify-between gap-3"
                    >
                        <div class="space-y-1">
                            <p class="text-sm text-muted-foreground">
                                {{ label }}
                            </p>
                            <p class="text-2xl font-semibold tracking-tight">
                                {{ formatNumber(value) }}
                            </p>
                        </div>
                        <component
                            :is="Icon"
                            class="h-5 w-5 text-muted-foreground"
                        />
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Messages per day</CardTitle>
                    <CardDescription>
                        User vs assistant messages over the last 14 days
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <StatChart
                        v-if="hasDailyActivity"
                        type="bar"
                        :labels="activity.messages_by_day.labels"
                        :datasets="messageDatasets"
                        :height="280"
                    />
                    <p
                        v-else
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        No message activity recorded in the last 14 days.
                    </p>
                </CardContent>
            </Card>
        </section>

        <section class="flex flex-col space-y-4">
            <Heading
                variant="small"
                title="Usage"
                description="Token consumption across providers and models"
            />

            <div class="grid gap-4 sm:grid-cols-3">
                <Card v-for="{ label, value, Icon } in usageStats" :key="label">
                    <CardContent
                        class="flex items-center justify-between gap-3"
                    >
                        <div class="space-y-1">
                            <p class="text-sm text-muted-foreground">
                                {{ label }}
                            </p>
                            <p class="text-2xl font-semibold tracking-tight">
                                {{ formatNumber(value) }}
                            </p>
                        </div>
                        <component
                            :is="Icon"
                            class="h-5 w-5 text-muted-foreground"
                        />
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Tokens per day</CardTitle>
                    <CardDescription>
                        Prompt vs completion tokens over the last 14 days
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <StatChart
                        v-if="hasTokenActivity"
                        type="line"
                        :labels="usage.tokens_by_day.labels"
                        :datasets="tokenDatasets"
                        :height="280"
                    />
                    <p
                        v-else
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        No token usage recorded in the last 14 days.
                    </p>
                </CardContent>
            </Card>

            <div class="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Tokens by provider</CardTitle>
                        <CardDescription
                            >Share of total tokens per provider</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <template v-if="usage.by_provider.length > 0">
                            <StatChart
                                type="doughnut"
                                :labels="providerDonutLabels"
                                :datasets="providerDonutDatasets"
                                :height="220"
                            />
                            <ul class="mt-4 space-y-2 text-sm">
                                <li
                                    v-for="(row, index) in providerUsage"
                                    :key="row.label"
                                    class="flex items-center justify-between gap-2"
                                >
                                    <span class="flex items-center gap-2">
                                        <span
                                            class="h-2.5 w-2.5 rounded-full"
                                            :style="{
                                                backgroundColor:
                                                    dotColor(index),
                                            }"
                                        />
                                        {{ row.label }}
                                    </span>
                                    <span class="text-muted-foreground">
                                        {{ formatNumber(row.tokens) }} tokens ·
                                        {{
                                            shareOf(
                                                row.tokens,
                                                providerTotalTokens,
                                            )
                                        }}
                                    </span>
                                </li>
                            </ul>
                        </template>
                        <p
                            v-else
                            class="py-8 text-center text-sm text-muted-foreground"
                        >
                            No provider usage recorded yet.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Tokens by model</CardTitle>
                        <CardDescription
                            >Share of total tokens per model</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <template v-if="usage.by_model.length > 0">
                            <StatChart
                                type="doughnut"
                                :labels="modelDonutLabels"
                                :datasets="modelDonutDatasets"
                                :height="220"
                            />
                            <ul class="mt-4 space-y-2 text-sm">
                                <li
                                    v-for="(row, index) in modelUsage"
                                    :key="row.label"
                                    class="flex items-center justify-between gap-2"
                                >
                                    <span class="flex items-center gap-2">
                                        <span
                                            class="h-2.5 w-2.5 rounded-full"
                                            :style="{
                                                backgroundColor:
                                                    dotColor(index),
                                            }"
                                        />
                                        {{ row.label }}
                                    </span>
                                    <span class="text-muted-foreground">
                                        {{ formatNumber(row.tokens) }} tokens ·
                                        {{
                                            shareOf(
                                                row.tokens,
                                                modelTotalTokens,
                                            )
                                        }}
                                    </span>
                                </li>
                            </ul>
                        </template>
                        <p
                            v-else
                            class="py-8 text-center text-sm text-muted-foreground"
                        >
                            No model usage recorded yet.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>
    </div>
</template>
