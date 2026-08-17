<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="DeployOps AI — AI deployment and integration workspace for Forward Deployed Engineers.">

        <title>DeployOps AI</title>

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-svh bg-slate-950 text-slate-300 antialiased">
        <a
            href="#main"
            class="fixed left-4 top-4 z-50 -translate-y-16 rounded-md bg-indigo-500 px-4 py-2 text-sm font-medium text-white transition focus:translate-y-0 focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:ring-offset-2 focus:ring-offset-slate-950"
        >
            Skip to content
        </a>

        <div class="relative isolate min-h-svh overflow-hidden">
            <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
                <div class="absolute inset-0 bg-[radial-gradient(ellipse_80%_50%_at_50%_-20%,rgba(99,102,241,0.25),transparent)]"></div>
                <div class="absolute inset-0 bg-[linear-gradient(to_bottom,transparent,rgba(2,6,23,0.8))]"></div>
            </div>

            <header class="mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-6 sm:px-8">
                <div class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-lg bg-indigo-500/15 ring-1 ring-indigo-400/30" aria-hidden="true">
                        <svg class="size-5 text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5" />
                        </svg>
                    </span>
                    <span class="text-sm font-semibold tracking-wide text-slate-100">DeployOps AI</span>
                </div>

                <a
                    href="{{ rtrim(config('app.frontend_url'), '/') }}"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-slate-300 ring-1 ring-slate-700 transition hover:bg-slate-900 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950"
                >
                    Sign in
                </a>
            </header>

            <main id="main" class="mx-auto w-full max-w-6xl px-6 pb-16 pt-4 sm:px-8 sm:pb-24 sm:pt-8">
                <section class="mx-auto max-w-3xl text-center">
                    <p class="mb-4 text-sm font-medium uppercase tracking-widest text-indigo-400">
                        Forward Deployed Engineering
                    </p>
                    <h1 class="text-4xl font-semibold tracking-tight text-white sm:text-5xl sm:leading-tight">
                        DeployOps AI
                    </h1>
                    <p class="mt-5 text-lg leading-relaxed text-slate-400 sm:text-xl">
                        AI deployment &amp; integration workspace for Forward Deployed Engineers
                    </p>
                    <div class="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
                        <a
                            href="{{ rtrim(config('app.frontend_url'), '/') }}"
                            class="inline-flex min-w-[12rem] items-center justify-center rounded-lg bg-indigo-500 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:bg-indigo-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950"
                        >
                            Open workspace
                        </a>
                        <a
                            href="{{ rtrim(config('app.frontend_url'), '/') }}"
                            class="inline-flex min-w-[12rem] items-center justify-center rounded-lg px-6 py-3 text-sm font-semibold text-slate-300 ring-1 ring-slate-700 transition hover:bg-slate-900 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950"
                        >
                            Sign in to dashboard
                        </a>
                    </div>
                </section>

                <section class="mt-20" aria-labelledby="features-heading">
                    <h2 id="features-heading" class="text-center text-sm font-semibold uppercase tracking-widest text-slate-500">
                        Platform capabilities
                    </h2>
                    <ul class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ([
                            [
                                'title' => 'Integrations',
                                'description' => 'Connect customer systems with REST APIs and webhooks, encrypted secrets, and connection testing.',
                            ],
                            [
                                'title' => 'AI Copilot + Tool Calling',
                                'description' => 'Operate deployments with an agent that calls tools against live integration and deployment context.',
                            ],
                            [
                                'title' => 'RAG Knowledge Base',
                                'description' => 'Upload runbooks and docs, then ground copilot answers with retrieval over customer knowledge.',
                            ],
                            [
                                'title' => 'Human Approval',
                                'description' => 'Review and approve sensitive AI-proposed actions before they execute in customer environments.',
                            ],
                            [
                                'title' => 'Observability',
                                'description' => 'Track AI health, request traces, and incidents from a single operational view.',
                            ],
                        ] as $feature)
                            <li class="rounded-xl bg-slate-900/60 p-5 ring-1 ring-slate-800 transition hover:ring-slate-700">
                                <h3 class="text-base font-semibold text-slate-100">{{ $feature['title'] }}</h3>
                                <p class="mt-2 text-sm leading-relaxed text-slate-400">{{ $feature['description'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                </section>
            </main>

            <footer class="mx-auto w-full max-w-6xl px-6 pb-8 text-center text-sm text-slate-500 sm:px-8">
                <p>DeployOps AI — built for FDE teams shipping AI in production.</p>
            </footer>
        </div>
    </body>
</html>
