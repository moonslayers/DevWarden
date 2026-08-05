<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard, home, login, register } from '@/routes';

const page = usePage();

const isAuthenticated = computed(() => page.props.auth.user !== null);
</script>

<template>
    <Head title="DevWarden" />

    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <header
            class="sticky top-0 z-40 border-b bg-background/80 backdrop-blur"
        >
            <div
                class="mx-auto flex h-16 w-full max-w-6xl items-center justify-between px-4 md:px-6"
            >
                <Link
                    :href="home()"
                    class="flex items-center gap-2 font-medium"
                >
                    <AppLogoIcon class="size-8 fill-current text-primary" />
                    <span class="text-lg font-semibold">DevWarden</span>
                </Link>

                <nav class="flex items-center gap-2">
                    <Button v-if="isAuthenticated" as-child size="sm">
                        <Link :href="dashboard()">Dashboard</Link>
                    </Button>
                    <template v-else>
                        <Button as-child variant="ghost" size="sm">
                            <Link :href="login()">Log in</Link>
                        </Button>
                        <Button as-child size="sm">
                            <Link :href="register()">Register</Link>
                        </Button>
                    </template>
                </nav>
            </div>
        </header>

        <main class="flex flex-1 flex-col">
            <section
                class="flex flex-col items-center gap-6 px-4 py-20 text-center md:py-28"
            >
                <Badge variant="secondary">Local-first · Telegram · AI</Badge>
                <h1
                    class="max-w-3xl text-4xl font-bold tracking-tight text-balance md:text-6xl"
                >
                    Your personal development assistant, where you already chat
                </h1>
                <p
                    class="max-w-2xl text-lg text-pretty text-muted-foreground"
                >
                    DevWarden is a local-first assistant that lives on your
                    machine: configure it from the web, connect your AI providers
                    and chat with it directly from Telegram.
                </p>
                <div class="flex flex-wrap items-center justify-center gap-3">
                    <Button as-child size="lg">
                        <Link :href="isAuthenticated ? dashboard() : register()">
                            Get started
                        </Link>
                    </Button>
                    <Button
                        v-if="!isAuthenticated"
                        as-child
                        variant="outline"
                        size="lg"
                    >
                        <Link :href="login()">Log in</Link>
                    </Button>
                </div>
            </section>

            <section
                class="mx-auto w-full max-w-6xl px-4 pb-20 md:px-6"
            >
                <div class="mb-10 flex flex-col items-center gap-2 text-center">
                    <h2 class="text-2xl font-semibold md:text-3xl">
                        The whole MVP in three pillars
                    </h2>
                    <p class="text-muted-foreground">
                        Designed to be simple to operate and private by default.
                    </p>
                </div>

                <div class="grid gap-6 md:grid-cols-3">
                    <Card class="gap-4">
                        <CardHeader>
                            <Badge variant="secondary" class="w-fit">
                                Telegram
                            </Badge>
                            <CardTitle>Long-polling bot</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <CardDescription>
                                Your own Telegram bot listens to your messages
                                with no webhooks or public servers needed:
                                just the local scheduler.
                            </CardDescription>
                        </CardContent>
                    </Card>

                    <Card class="gap-4">
                        <CardHeader>
                            <Badge variant="secondary" class="w-fit">
                                IA
                            </Badge>
                            <CardTitle>Providers with failover</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <CardDescription>
                                Connect multiple AI providers (OpenAI, Anthropic,
                                DeepSeek and compatible ones). If one fails, the
                                conversation continues with the next.
                            </CardDescription>
                        </CardContent>
                    </Card>

                    <Card class="gap-4">
                        <CardHeader>
                            <Badge variant="secondary" class="w-fit">
                                Configuration
                            </Badge>
                            <CardTitle>100% web UI configuration</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <CardDescription>
                                No .env editing or file tweaks: bot token,
                                providers and system prompt are managed from the
                                panel and persisted in the database.
                            </CardDescription>
                        </CardContent>
                    </Card>
                </div>
            </section>

            <section
                class="border-t bg-muted/30 px-4 py-16 md:px-6"
            >
                <div class="mx-auto w-full max-w-6xl">
                    <div
                        class="mb-10 flex flex-col items-center gap-2 text-center"
                    >
                        <h2 class="text-2xl font-semibold md:text-3xl">
                            How it works
                        </h2>
                        <p class="text-muted-foreground">
                            Your assistant is up and running in under five minutes.
                        </p>
                    </div>

                    <div class="grid gap-6 md:grid-cols-3">
                        <div
                            v-for="(step, index) in [
                                {
                                    title: 'Create your account',
                                    description:
                                        'Sign up for DevWarden and access the configuration panel.',
                                },
                                {
                                    title: 'Connect Telegram and your providers',
                                    description:
                                        'Paste your bot token from BotFather, add your allowed IDs and add your AI providers.',
                                },
                                {
                                    title: 'Chat with your assistant',
                                    description:
                                        'Message it from Telegram: it replies instantly using your providers, with automatic failover.',
                                },
                            ]"
                            :key="index"
                            class="flex flex-col gap-2"
                        >
                            <span
                                class="flex h-9 w-9 items-center justify-center rounded-full border bg-background text-sm font-semibold"
                            >
                                {{ index + 1 }}
                            </span>
                            <h3 class="font-semibold">{{ step.title }}</h3>
                            <p class="text-sm text-muted-foreground">
                                {{ step.description }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t py-6">
            <div
                class="mx-auto flex w-full max-w-6xl flex-col items-center justify-between gap-2 px-4 text-sm text-muted-foreground md:flex-row md:px-6"
            >
                <span>© {{ new Date().getFullYear() }} DevWarden</span>
                <span>Your local-first development assistant</span>
            </div>
        </footer>
    </div>
</template>
