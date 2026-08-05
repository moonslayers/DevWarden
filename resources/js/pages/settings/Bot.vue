<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import BotController from '@/actions/App/Http/Controllers/Settings/BotController';
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
import { edit } from '@/routes/bot';

interface UserOption {
    id: number;
    name: string;
}

const props = defineProps<{
    system_prompt: string | null;
    max_history_messages: number;
    owner_user_id: number | null;
    users: UserOption[];
}>();

const systemPromptValue = ref<string>(props.system_prompt ?? '');

watch(
    () => props.system_prompt,
    (value) => {
        systemPromptValue.value = value ?? '';
    },
);

const ownerUserValue = ref<string>(
    props.owner_user_id != null ? String(props.owner_user_id) : 'none',
);

watch(
    () => props.owner_user_id,
    (value) => {
        ownerUserValue.value = value != null ? String(value) : 'none';
    },
);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Bot settings',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Bot settings" />

    <h1 class="sr-only">Bot settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Bot"
            description="Configure how the bot answers and remembers"
        />

        <Form
            v-bind="BotController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="system_prompt">System prompt</Label>
                <textarea
                    id="system_prompt"
                    name="system_prompt"
                    class="mt-1 block min-h-32 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    v-model="systemPromptValue"
                    rows="6"
                    placeholder="Instructions the bot follows when answering"
                />
                <p class="text-sm text-muted-foreground">
                    System instructions prepended to every conversation.
                </p>
                <InputError class="mt-2" :message="errors.system_prompt" />
            </div>

            <div class="grid gap-2">
                <Label for="max_history_messages">Max history messages</Label>
                <Input
                    id="max_history_messages"
                    class="mt-1 block w-full"
                    name="max_history_messages"
                    type="number"
                    :default-value="max_history_messages"
                    min="1"
                    max="200"
                />
                <p class="text-sm text-muted-foreground">
                    How many past messages the bot remembers per chat (1-200).
                </p>
                <InputError
                    class="mt-2"
                    :message="errors.max_history_messages"
                />
            </div>

            <div class="grid gap-2">
                <Label>Owner user</Label>
                <Select v-model="ownerUserValue">
                    <SelectTrigger class="w-full">
                        <SelectValue placeholder="No owner" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="none">No owner</SelectItem>
                        <SelectItem
                            v-for="user in users"
                            :key="user.id"
                            :value="String(user.id)"
                        >
                            {{ user.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <input
                    type="hidden"
                    name="owner_user_id"
                    :value="ownerUserValue === 'none' ? '' : ownerUserValue"
                />
                <p class="text-sm text-muted-foreground">
                    The user the bot answers for.
                </p>
                <InputError class="mt-2" :message="errors.owner_user_id" />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-bot-settings-button"
                >
                    Save
                </Button>
            </div>
        </Form>
    </div>
</template>
