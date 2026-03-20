<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chat MedIntelligence</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-950 text-stone-100">
    <main class="mx-auto flex min-h-screen max-w-7xl flex-col px-4 py-6 md:px-8">
        <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div class="max-w-3xl">
                <span class="inline-flex rounded-full border border-emerald-400/20 bg-emerald-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-emerald-200">
                    Chat Operacional
                </span>
                <h1 class="mt-3 text-3xl font-semibold tracking-tight text-white md:text-5xl">
                    Texto, arquivo e áudio no mesmo fluxo do chatbot
                </h1>
                <p class="mt-3 text-sm leading-6 text-stone-300 md:text-base">
                    Esta tela usa as rotas reais do sistema para conversar com a IA, enviar planilhas e gravar áudio
                    direto no navegador.
                </p>
            </div>
            <div class="flex gap-3">
                <a
                    href="/chat/audio-demo"
                    class="rounded-full border border-white/10 px-4 py-2 text-sm font-medium text-white/80 transition hover:border-orange-300/40 hover:text-white"
                >
                    Abrir demo de áudio
                </a>
            </div>
        </div>

        <section
            class="grid flex-1 gap-6 lg:grid-cols-[22rem_minmax(0,1fr)]"
            data-chat-client
            data-chat-endpoint="/api/chat/enviar"
            data-confirm-endpoint="/api/chat/confirmar"
            data-history-endpoint="/api/chat/historico"
        >
            <aside class="rounded-[2rem] border border-white/10 bg-black/20 p-5">
                <h2 class="mb-4 text-lg font-semibold text-white">Configuração</h2>

                <div class="space-y-4">
                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-stone-200">Token Bearer</span>
                        <input
                            data-chat-token
                            type="text"
                            placeholder="Cole um token da API"
                            class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white outline-none transition focus:border-orange-300/60 focus:ring-2 focus:ring-orange-400/20"
                        >
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-stone-200">Session ID</span>
                        <input
                            data-chat-session
                            type="text"
                            class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white outline-none transition focus:border-orange-300/60 focus:ring-2 focus:ring-orange-400/20"
                        >
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-stone-200">Tipo de processamento</span>
                        <select
                            data-chat-process
                            class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white outline-none transition focus:border-orange-300/60 focus:ring-2 focus:ring-orange-400/20"
                        >
                            <option value="financeiro">financeiro</option>
                            <option value="clientes">clientes</option>
                            <option value="auto">auto</option>
                        </select>
                    </label>

                    <button
                        type="button"
                        data-chat-history
                        class="w-full rounded-full border border-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:border-emerald-300/40 hover:bg-emerald-400/10"
                    >
                        Carregar histórico
                    </button>
                </div>

                <div class="mt-6 rounded-3xl border border-white/10 bg-white/5 p-4">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-white">Ação pendente</h3>
                        <button
                            type="button"
                            data-chat-confirm
                            class="rounded-full border border-orange-400/30 bg-orange-500/15 px-4 py-2 text-xs font-semibold uppercase tracking-[0.14em] text-orange-100 transition hover:bg-orange-500/25 disabled:cursor-not-allowed disabled:border-white/5 disabled:bg-white/5 disabled:text-stone-500"
                            disabled
                        >
                            Confirmar
                        </button>
                    </div>

                    <div data-chat-pending hidden>
                        <p data-chat-pending-summary class="text-sm leading-6 text-stone-200"></p>
                    </div>
                    <p data-chat-status class="text-sm leading-6 text-stone-400">
                        Pronto para conversar com o chatbot.
                    </p>
                </div>
            </aside>

            <section class="flex min-h-[75vh] flex-col overflow-hidden rounded-[2rem] border border-white/10 bg-gradient-to-br from-stone-900 via-stone-950 to-orange-950/30">
                <div class="border-b border-white/8 px-5 py-4">
                    <h2 class="text-lg font-semibold text-white">Conversa</h2>
                    <p class="text-sm text-stone-400">
                        Use texto, anexe planilhas ou grave um áudio diretamente nesta tela.
                    </p>
                </div>

                <div
                    data-chat-messages
                    class="flex-1 space-y-4 overflow-y-auto px-5 py-5"
                >
                    <article class="rounded-3xl border border-orange-400/15 bg-orange-500/8 p-4 text-sm leading-6 text-orange-50">
                        <div class="mb-2 flex items-center justify-between gap-3 text-[11px] uppercase tracking-[0.22em] text-white/45">
                            <span>Chatbot</span>
                            <span>Agora</span>
                        </div>
                        <div class="whitespace-pre-wrap">
                            Você já pode testar texto, upload de arquivo e gravação de áudio usando a mesma API do chat.
                        </div>
                    </article>
                </div>

                <div class="border-t border-white/8 px-5 py-5">
                    <div class="space-y-4">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-stone-200">Mensagem</span>
                            <textarea
                                data-chat-message
                                rows="4"
                                placeholder="Exemplo: me fale o faturamento dos últimos 30 dias ou gere a fatura da planilha anexada"
                                class="w-full rounded-3xl border border-white/10 bg-black/20 px-4 py-3 text-sm leading-6 text-white outline-none transition focus:border-orange-300/60 focus:ring-2 focus:ring-orange-400/20"
                            ></textarea>
                        </label>

                        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
                            <div class="rounded-3xl border border-white/10 bg-black/20 p-4">
                                <div class="flex flex-wrap items-center gap-3">
                                    <label class="inline-flex cursor-pointer rounded-full border border-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:border-orange-300/40 hover:bg-white/5">
                                        <input data-chat-file type="file" class="hidden">
                                        Selecionar arquivo
                                    </label>

                                    <span data-chat-file-label class="text-sm text-stone-400">
                                        Nenhum arquivo selecionado.
                                    </span>
                                </div>
                            </div>

                            <div class="rounded-3xl border border-white/10 bg-black/20 p-4">
                                <div class="flex flex-wrap gap-3">
                                    <button
                                        type="button"
                                        data-chat-record
                                        class="rounded-full bg-orange-500 px-4 py-3 text-sm font-semibold text-stone-950 transition hover:bg-orange-400 disabled:cursor-not-allowed disabled:bg-stone-700 disabled:text-stone-500"
                                    >
                                        Gravar
                                    </button>
                                    <button
                                        type="button"
                                        data-chat-stop
                                        class="rounded-full border border-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:border-orange-300/40 disabled:cursor-not-allowed disabled:border-white/5 disabled:text-stone-500"
                                        disabled
                                    >
                                        Parar
                                    </button>
                                    <button
                                        type="button"
                                        data-chat-audio-reset
                                        class="rounded-full border border-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:border-white/30 disabled:cursor-not-allowed disabled:border-white/5 disabled:text-stone-500"
                                        disabled
                                    >
                                        Limpar áudio
                                    </button>
                                </div>

                                <audio
                                    data-chat-audio-preview
                                    controls
                                    hidden
                                    class="mt-4 w-full rounded-2xl border border-white/10 bg-stone-950"
                                ></audio>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <p class="text-xs uppercase tracking-[0.18em] text-stone-500">
                                Ctrl/Cmd + Enter para enviar
                            </p>
                            <button
                                type="button"
                                data-chat-send
                                class="rounded-full bg-emerald-400 px-6 py-3 text-sm font-semibold text-stone-950 transition hover:bg-emerald-300 disabled:cursor-not-allowed disabled:bg-stone-700 disabled:text-stone-500"
                            >
                                Enviar para o chatbot
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </section>
    </main>
</body>
</html>
