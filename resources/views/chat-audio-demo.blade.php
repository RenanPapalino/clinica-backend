<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demo Audio Chat</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-950 text-stone-100">
    <main class="mx-auto flex min-h-screen max-w-5xl items-center justify-center px-6 py-12">
        <section
            class="w-full rounded-[2rem] border border-white/10 bg-gradient-to-br from-stone-900 via-stone-950 to-orange-950/40 p-8 shadow-2xl shadow-black/40"
            data-chat-audio-recorder
            data-endpoint="/api/chat/enviar"
            data-process-type="financeiro"
        >
            <div class="mb-8 flex flex-col gap-3">
                <span class="inline-flex w-fit rounded-full border border-orange-400/30 bg-orange-500/10 px-3 py-1 text-xs font-semibold tracking-[0.18em] text-orange-200 uppercase">
                    Demo de Gravação
                </span>
                <h1 class="max-w-3xl text-3xl font-semibold tracking-tight text-white md:text-5xl">
                    Grave um áudio e envie direto para o chatbot financeiro
                </h1>
                <p class="max-w-2xl text-sm leading-6 text-stone-300 md:text-base">
                    Esta tela grava com <code class="rounded bg-white/5 px-1.5 py-0.5 text-orange-200">MediaRecorder</code>,
                    envia o blob para <code class="rounded bg-white/5 px-1.5 py-0.5 text-orange-200">/api/chat/enviar</code>
                    e já usa o mesmo contrato do backend para <code class="rounded bg-white/5 px-1.5 py-0.5 text-orange-200">arquivo_mime_type</code>
                    e <code class="rounded bg-white/5 px-1.5 py-0.5 text-orange-200">arquivo_nome</code>.
                </p>
            </div>

            <div class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
                <div class="space-y-5">
                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-stone-200">Token Bearer</span>
                        <input
                            data-chat-token
                            type="text"
                            placeholder="Cole aqui o token da API se nao estiver autenticado na sessao"
                            class="w-full rounded-2xl border border-white/10 bg-black/20 px-4 py-3 text-sm text-white outline-none transition focus:border-orange-300/60 focus:ring-2 focus:ring-orange-400/20"
                        >
                    </label>

                    <div class="grid gap-5 md:grid-cols-2">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-stone-200">Session ID</span>
                            <input
                                data-chat-session
                                type="text"
                                class="w-full rounded-2xl border border-white/10 bg-black/20 px-4 py-3 text-sm text-white outline-none transition focus:border-orange-300/60 focus:ring-2 focus:ring-orange-400/20"
                            >
                        </label>

                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-stone-200">Tipo de processamento</span>
                            <select
                                data-chat-process
                                class="w-full rounded-2xl border border-white/10 bg-black/20 px-4 py-3 text-sm text-white outline-none transition focus:border-orange-300/60 focus:ring-2 focus:ring-orange-400/20"
                            >
                                <option value="financeiro">financeiro</option>
                                <option value="clientes">clientes</option>
                                <option value="auto">auto</option>
                            </select>
                        </label>
                    </div>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-stone-200">Mensagem complementar</span>
                        <textarea
                            data-chat-message
                            rows="4"
                            placeholder="Opcional. Exemplo: gere a fatura desta gravacao"
                            class="w-full rounded-3xl border border-white/10 bg-black/20 px-4 py-3 text-sm leading-6 text-white outline-none transition focus:border-orange-300/60 focus:ring-2 focus:ring-orange-400/20"
                        ></textarea>
                    </label>

                    <div class="rounded-3xl border border-white/10 bg-black/20 p-5">
                        <div class="mb-4 flex flex-wrap gap-3">
                            <button
                                type="button"
                                data-audio-record
                                class="rounded-full bg-orange-500 px-5 py-3 text-sm font-semibold text-stone-950 transition hover:bg-orange-400 disabled:cursor-not-allowed disabled:bg-stone-700 disabled:text-stone-400"
                            >
                                Gravar
                            </button>
                            <button
                                type="button"
                                data-audio-stop
                                class="rounded-full border border-white/15 px-5 py-3 text-sm font-semibold text-white transition hover:border-orange-300/50 hover:text-orange-100 disabled:cursor-not-allowed disabled:border-white/5 disabled:text-stone-500"
                            >
                                Parar
                            </button>
                            <button
                                type="button"
                                data-audio-send
                                class="rounded-full border border-emerald-400/30 bg-emerald-400/10 px-5 py-3 text-sm font-semibold text-emerald-100 transition hover:bg-emerald-400/20 disabled:cursor-not-allowed disabled:border-white/5 disabled:bg-white/5 disabled:text-stone-500"
                            >
                                Enviar
                            </button>
                            <button
                                type="button"
                                data-audio-reset
                                class="rounded-full border border-white/15 px-5 py-3 text-sm font-semibold text-white transition hover:border-white/30 disabled:cursor-not-allowed disabled:border-white/5 disabled:text-stone-500"
                            >
                                Limpar
                            </button>
                        </div>

                        <p data-audio-status class="mb-4 text-sm text-stone-300">
                            Pronto para gravar.
                        </p>

                        <audio
                            data-audio-preview
                            controls
                            hidden
                            class="w-full rounded-2xl border border-white/10 bg-stone-900"
                        ></audio>
                    </div>
                </div>

                <div class="rounded-[1.75rem] border border-white/10 bg-black/25 p-6">
                    <h2 class="mb-4 text-lg font-semibold text-white">Resposta do chatbot</h2>
                    <pre
                        data-chat-response
                        class="min-h-[20rem] whitespace-pre-wrap rounded-2xl border border-white/8 bg-black/30 p-4 text-sm leading-6 text-stone-200"
                    >Nenhuma resposta ainda.</pre>

                    <div class="mt-5 rounded-2xl border border-orange-400/15 bg-orange-500/8 p-4 text-sm leading-6 text-orange-100">
                        <p class="font-semibold">Como usar</p>
                        <ol class="mt-2 list-decimal space-y-1 pl-5 text-orange-50/90">
                            <li>Se precisar, cole um token Bearer válido da API.</li>
                            <li>Clique em <strong>Gravar</strong>, fale o comando e depois <strong>Parar</strong>.</li>
                            <li>Opcionalmente escreva uma mensagem complementar.</li>
                            <li>Clique em <strong>Enviar</strong> para mandar o áudio ao chat.</li>
                        </ol>
                    </div>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
