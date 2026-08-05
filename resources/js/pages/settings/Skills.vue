<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import SkillController from '@/actions/App/Http/Controllers/Settings/SkillController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
import { Switch } from '@/components/ui/switch';
import { index } from '@/routes/settings/skills';

interface Skill {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    content: string;
    trigger_keywords: string[] | null;
    active: boolean;
    sort_order: number;
}

const props = defineProps<{
    skills: Skill[];
}>();

const newSkillActive = ref(true);

const activeState = reactive<Record<number, boolean>>(
    Object.fromEntries(props.skills.map((skill) => [skill.id, skill.active])),
);

function keywordsToText(keywords: string[] | null): string {
    return (keywords ?? []).join(', ');
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Skills',
                href: index(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Skills" />

    <h1 class="sr-only">Skills</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Skills"
            description="Instruction blocks the bot injects when trigger keywords match"
        />

        <Form
            v-bind="SkillController.store.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="new_name">Name</Label>
                <Input
                    id="new_name"
                    class="mt-1 block w-full"
                    name="name"
                    placeholder="e.g. Opencode Session Orchestration"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="new_slug">Slug</Label>
                <Input
                    id="new_slug"
                    class="mt-1 block w-full"
                    name="slug"
                    placeholder="opencode-session-orchestration"
                />
                <InputError class="mt-2" :message="errors.slug" />
            </div>

            <div class="grid gap-2">
                <Label for="new_description">Description</Label>
                <Input
                    id="new_description"
                    class="mt-1 block w-full"
                    name="description"
                    placeholder="What this skill does"
                />
                <InputError class="mt-2" :message="errors.description" />
            </div>

            <div class="grid gap-2">
                <Label for="new_content">Content</Label>
                <textarea
                    id="new_content"
                    name="content"
                    class="mt-1 block min-h-32 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    rows="6"
                    placeholder="Instructions injected when this skill triggers"
                />
                <InputError class="mt-2" :message="errors.content" />
            </div>

            <div class="grid gap-2">
                <Label for="new_trigger_keywords">Trigger keywords</Label>
                <Input
                    id="new_trigger_keywords"
                    class="mt-1 block w-full"
                    name="trigger_keywords"
                    placeholder="opencode, session, workflow"
                />
                <p class="text-sm text-muted-foreground">
                    Comma-separated keywords that activate this skill.
                </p>
                <InputError class="mt-2" :message="errors.trigger_keywords" />
            </div>

            <div class="grid gap-2">
                <Label for="new_sort_order">Sort order</Label>
                <Input
                    id="new_sort_order"
                    class="mt-1 block w-full"
                    name="sort_order"
                    type="number"
                    :default-value="0"
                    min="0"
                />
                <InputError class="mt-2" :message="errors.sort_order" />
            </div>

            <div class="flex items-center justify-between gap-4">
                <div class="space-y-0.5">
                    <Label>Active</Label>
                    <p class="text-sm text-muted-foreground">
                        Inject this skill when its triggers match.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Switch
                        v-model:checked="newSkillActive"
                        aria-label="Active"
                    />
                    <input
                        type="hidden"
                        name="active"
                        :value="newSkillActive ? '1' : '0'"
                    />
                </div>
            </div>
            <InputError class="mt-2" :message="errors.active" />

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="add-skill-button">
                    Add skill
                </Button>
            </div>
        </Form>

        <div v-if="skills.length === 0" class="text-sm text-muted-foreground">
            No skills configured yet.
        </div>

        <div
            v-for="skill in skills"
            :key="skill.id"
            class="space-y-4 rounded-lg border p-4"
        >
            <Form
                v-bind="SkillController.update.form(skill.id)"
                class="space-y-4"
                v-slot="{ errors, processing }"
            >
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="font-medium">{{ skill.name }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ skill.slug }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Switch
                            v-model:checked="activeState[skill.id]"
                            aria-label="Active"
                        />
                        <input
                            type="hidden"
                            name="active"
                            :value="activeState[skill.id] ? '1' : '0'"
                        />
                    </div>
                </div>
                <InputError class="mt-2" :message="errors.active" />

                <div class="grid gap-2">
                    <Label :for="`name_${skill.id}`">Name</Label>
                    <Input
                        :id="`name_${skill.id}`"
                        class="mt-1 block w-full"
                        name="name"
                        :default-value="skill.name"
                    />
                    <InputError class="mt-2" :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label :for="`slug_${skill.id}`">Slug</Label>
                    <Input
                        :id="`slug_${skill.id}`"
                        class="mt-1 block w-full"
                        name="slug"
                        :default-value="skill.slug"
                    />
                    <InputError class="mt-2" :message="errors.slug" />
                </div>

                <div class="grid gap-2">
                    <Label :for="`description_${skill.id}`">Description</Label>
                    <Input
                        :id="`description_${skill.id}`"
                        class="mt-1 block w-full"
                        name="description"
                        :default-value="skill.description ?? ''"
                    />
                    <InputError class="mt-2" :message="errors.description" />
                </div>

                <div class="grid gap-2">
                    <Label :for="`content_${skill.id}`">Content</Label>
                    <textarea
                        :id="`content_${skill.id}`"
                        name="content"
                        :value="skill.content"
                        class="mt-1 block min-h-32 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                        rows="6"
                    />
                    <InputError class="mt-2" :message="errors.content" />
                </div>

                <div class="grid gap-2">
                    <Label :for="`trigger_keywords_${skill.id}`">
                        Trigger keywords
                    </Label>
                    <Input
                        :id="`trigger_keywords_${skill.id}`"
                        class="mt-1 block w-full"
                        name="trigger_keywords"
                        :default-value="keywordsToText(skill.trigger_keywords)"
                    />
                    <p class="text-sm text-muted-foreground">
                        Comma-separated keywords that activate this skill.
                    </p>
                    <InputError
                        class="mt-2"
                        :message="errors.trigger_keywords"
                    />
                </div>

                <div class="grid gap-2">
                    <Label :for="`sort_order_${skill.id}`">Sort order</Label>
                    <Input
                        :id="`sort_order_${skill.id}`"
                        class="mt-1 block w-full"
                        name="sort_order"
                        type="number"
                        :default-value="skill.sort_order"
                        min="0"
                    />
                    <InputError class="mt-2" :message="errors.sort_order" />
                </div>

                <div class="flex items-center gap-4">
                    <Button
                        :disabled="processing"
                        data-test="update-skill-button"
                    >
                        Save
                    </Button>
                    <Dialog>
                        <DialogTrigger as-child>
                            <Button
                                type="button"
                                variant="destructive"
                                data-test="delete-skill-button"
                            >
                                Delete
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <Form
                                v-bind="SkillController.destroy.form(skill.id)"
                                :options="{ preserveScroll: true }"
                                class="space-y-6"
                                v-slot="{ processing: deleting }"
                            >
                                <DialogHeader class="space-y-3">
                                    <DialogTitle
                                        >Delete this skill?</DialogTitle
                                    >
                                    <DialogDescription>
                                        This skill will be permanently removed
                                        and the bot will stop injecting it.
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
                                        data-test="confirm-delete-skill-button"
                                    >
                                        Delete skill
                                    </Button>
                                </DialogFooter>
                            </Form>
                        </DialogContent>
                    </Dialog>
                </div>
            </Form>
        </div>
    </div>
</template>
