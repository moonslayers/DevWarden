<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import TelegramController from '@/actions/App/Http/Controllers/Settings/TelegramController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { edit } from '@/routes/telegram';

const props = defineProps<{
    has_bot_token: boolean;
    allowed_user_ids: number[];
    polling_enabled: boolean;
}>();

const pollingEnabled = ref(props.polling_enabled);

watch(
    () => props.polling_enabled,
    (value) => {
        pollingEnabled.value = value;
    },
);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Telegram settings',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Telegram settings" />

    <h1 class="sr-only">Telegram settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Telegram"
            description="Configure the Telegram bot connection"
        />

        <Form
            v-bind="TelegramController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="bot_token">Bot token</Label>
                <Input
                    id="bot_token"
                    type="password"
                    class="mt-1 block w-full"
                    name="bot_token"
                    :placeholder="
                        has_bot_token
                            ? 'A token is already configured — leave blank to keep it'
                            : 'Paste your bot token'
                    "
                    autocomplete="new-password"
                />
                <InputError class="mt-2" :message="errors.bot_token" />
            </div>

            <div class="grid gap-2">
                <Label for="allowed_user_ids">Allowed user IDs</Label>
                <Input
                    id="allowed_user_ids"
                    class="mt-1 block w-full"
                    name="allowed_user_ids"
                    :default-value="allowed_user_ids.join(', ')"
                    placeholder="123456789, 987654321"
                />
                <p class="text-sm text-muted-foreground">
                    Comma-separated Telegram user IDs allowed to talk to the bot.
                </p>
                <InputError class="mt-2" :message="errors.allowed_user_ids" />
            </div>

            <div class="flex items-center justify-between gap-4">
                <div class="space-y-0.5">
                    <Label>Polling enabled</Label>
                    <p class="text-sm text-muted-foreground">
                        Poll Telegram for new updates on a schedule.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Switch
                        v-model:checked="pollingEnabled"
                        aria-label="Polling enabled"
                    />
                    <input
                        type="hidden"
                        name="polling_enabled"
                        :value="pollingEnabled ? '1' : '0'"
                    />
                </div>
            </div>
            <InputError class="mt-2" :message="errors.polling_enabled" />

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-telegram-settings-button">
                    Save
                </Button>
            </div>
        </Form>
    </div>
</template>
