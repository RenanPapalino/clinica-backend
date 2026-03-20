const STORAGE_KEYS = {
    token: 'medintelligence.chat.token',
    sessionId: 'medintelligence.chat.session_id',
    processType: 'medintelligence.chat.process_type',
};

const AUDIO_MIME_TYPES = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
    'audio/ogg',
    'audio/mp4',
];

function randomSessionId() {
    if (window.crypto?.randomUUID) {
        return `chat_${window.crypto.randomUUID()}`;
    }

    return `chat_${Date.now()}`;
}

function guessAudioExtension(mimeType) {
    const normalized = String(mimeType || '').toLowerCase();

    if (normalized.includes('webm')) return 'webm';
    if (normalized.includes('ogg')) return 'ogg';
    if (normalized.includes('mp4')) return 'mp4';
    if (normalized.includes('mpeg') || normalized.includes('mp3')) return 'mp3';
    if (normalized.includes('wav')) return 'wav';

    return 'webm';
}

function formatTimestamp(date = new Date()) {
    return new Intl.DateTimeFormat('pt-BR', {
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

class ChatClient {
    constructor(root) {
        this.root = root;
        this.chatEndpoint = root.dataset.chatEndpoint || '/api/chat/enviar';
        this.confirmEndpoint = root.dataset.confirmEndpoint || '/api/chat/confirmar';
        this.historyEndpoint = root.dataset.historyEndpoint || '/api/chat/historico';

        this.tokenInput = root.querySelector('[data-chat-token]');
        this.sessionInput = root.querySelector('[data-chat-session]');
        this.processInput = root.querySelector('[data-chat-process]');
        this.messageInput = root.querySelector('[data-chat-message]');
        this.fileInput = root.querySelector('[data-chat-file]');
        this.fileLabel = root.querySelector('[data-chat-file-label]');
        this.sendButton = root.querySelector('[data-chat-send]');
        this.confirmButton = root.querySelector('[data-chat-confirm]');
        this.historyButton = root.querySelector('[data-chat-history]');
        this.recordButton = root.querySelector('[data-chat-record]');
        this.stopButton = root.querySelector('[data-chat-stop]');
        this.resetAudioButton = root.querySelector('[data-chat-audio-reset]');
        this.audioPreview = root.querySelector('[data-chat-audio-preview]');
        this.statusOutput = root.querySelector('[data-chat-status]');
        this.messagesContainer = root.querySelector('[data-chat-messages]');
        this.pendingCard = root.querySelector('[data-chat-pending]');
        this.pendingSummary = root.querySelector('[data-chat-pending-summary]');

        this.isSending = false;
        this.isRecording = false;
        this.pendingAction = null;
        this.audioBlob = null;
        this.audioMimeType = '';
        this.audioUrl = null;
        this.audioChunks = [];
        this.mediaRecorder = null;
        this.mediaStream = null;

        this.restoreState();
        this.bindEvents();
        this.updateControls();
        this.setStatus('Pronto para conversar com o chatbot.');
    }

    restoreState() {
        if (this.tokenInput) {
            this.tokenInput.value = localStorage.getItem(STORAGE_KEYS.token) || '';
        }

        if (this.sessionInput) {
            this.sessionInput.value = localStorage.getItem(STORAGE_KEYS.sessionId) || randomSessionId();
        }

        if (this.processInput) {
            this.processInput.value = localStorage.getItem(STORAGE_KEYS.processType) || this.processInput.value || 'financeiro';
        }
    }

    bindEvents() {
        this.tokenInput?.addEventListener('change', () => {
            localStorage.setItem(STORAGE_KEYS.token, this.tokenInput.value.trim());
        });

        this.sessionInput?.addEventListener('change', () => {
            localStorage.setItem(STORAGE_KEYS.sessionId, this.sessionInput.value.trim());
        });

        this.processInput?.addEventListener('change', () => {
            localStorage.setItem(STORAGE_KEYS.processType, this.processInput.value);
        });

        this.messageInput?.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                event.preventDefault();
                void this.sendMessage();
            }
        });

        this.fileInput?.addEventListener('change', () => {
            const file = this.fileInput?.files?.[0];
            this.fileLabel.textContent = file ? file.name : 'Nenhum arquivo selecionado.';
            this.updateControls();
        });

        this.sendButton?.addEventListener('click', () => {
            void this.sendMessage();
        });

        this.confirmButton?.addEventListener('click', () => {
            void this.confirmPendingAction();
        });

        this.historyButton?.addEventListener('click', () => {
            void this.loadHistory();
        });

        this.recordButton?.addEventListener('click', () => {
            void this.startRecording();
        });

        this.stopButton?.addEventListener('click', () => {
            this.stopRecording();
        });

        this.resetAudioButton?.addEventListener('click', () => {
            this.resetAudio();
        });
    }

    buildHeaders() {
        const headers = {
            Accept: 'application/json',
        };

        const token = this.tokenInput?.value?.trim();
        if (token) {
            headers.Authorization = `Bearer ${token}`;
        }

        return headers;
    }

    sessionId() {
        const current = this.sessionInput?.value?.trim();
        if (current) {
            return current;
        }

        const generated = randomSessionId();
        if (this.sessionInput) {
            this.sessionInput.value = generated;
        }
        localStorage.setItem(STORAGE_KEYS.sessionId, generated);
        return generated;
    }

    async sendMessage() {
        if (this.isSending) {
            return;
        }

        const message = this.messageInput?.value?.trim() || '';
        const file = this.fileInput?.files?.[0] || null;
        const hasAudio = !!this.audioBlob;

        if (!message && !file && !hasAudio) {
            this.setStatus('Digite uma mensagem, selecione um arquivo ou grave um áudio.');
            return;
        }

        this.isSending = true;
        this.updateControls();
        this.setStatus('Enviando para o chatbot...');

        try {
            const formData = new FormData();
            formData.append('mensagem', message);
            formData.append('tipo_processamento', this.processInput?.value || 'financeiro');
            formData.append('session_id', this.sessionId());

            if (file) {
                formData.append('arquivo', file);
                if (file.type) {
                    formData.append('arquivo_mime_type', file.type);
                }
                if (file.name) {
                    formData.append('arquivo_nome', file.name);
                }
            } else if (this.audioBlob) {
                const mimeType = this.audioMimeType || this.audioBlob.type || 'audio/webm';
                const extension = guessAudioExtension(mimeType);
                const fileName = `gravacao.${extension}`;
                const audioFile = new File([this.audioBlob], fileName, { type: mimeType });

                formData.append('arquivo', audioFile);
                formData.append('arquivo_mime_type', mimeType);
                formData.append('arquivo_nome', fileName);
            }

            if (message) {
                this.appendMessage('user', message);
            } else if (file) {
                this.appendMessage('user', `[Arquivo enviado: ${file.name}]`);
            } else {
                this.appendMessage('user', '[Audio gravado enviado]');
            }

            const response = await window.axios.post(this.chatEndpoint, formData, {
                headers: this.buildHeaders(),
            });

            this.handleChatResponse(response.data || {});
            this.messageInput.value = '';
            if (this.fileInput) {
                this.fileInput.value = '';
            }
            this.fileLabel.textContent = 'Nenhum arquivo selecionado.';
            this.resetAudio({ keepStatus: true });
            this.setStatus('Resposta recebida com sucesso.');
        } catch (error) {
            console.error(error);
            const payload = error?.response?.data || {};
            const messageText = payload?.message || error?.message || 'Falha ao enviar mensagem para o chatbot.';
            this.appendMessage('assistant', messageText);
            this.setStatus(messageText);
        } finally {
            this.isSending = false;
            this.updateControls();
        }
    }

    handleChatResponse(data) {
        const content = data?.content || data?.mensagem || data?.message || 'Sem resposta do chatbot.';
        this.appendMessage('assistant', content, {
            action: data?.acao_sugerida || data?.dados_estruturados?.acao_sugerida || null,
        });

        const action = data?.acao_sugerida || data?.dados_estruturados?.acao_sugerida || null;
        const structured = data?.dados_estruturados || null;
        if (action && structured?.dados_mapeados && structured?.metadata) {
            this.pendingAction = {
                acao: action,
                dados: structured.dados_mapeados,
                metadata: structured.metadata,
            };
            this.showPendingAction(data);
        } else {
            this.pendingAction = null;
            this.hidePendingAction();
        }
    }

    async confirmPendingAction() {
        if (!this.pendingAction || this.isSending) {
            return;
        }

        this.isSending = true;
        this.updateControls();
        this.setStatus('Confirmando a ação sugerida...');

        try {
            const payload = {
                acao: this.pendingAction.acao,
                dados: this.pendingAction.dados,
                metadata: this.pendingAction.metadata,
                session_id: this.sessionId(),
            };

            const response = await window.axios.post(this.confirmEndpoint, payload, {
                headers: this.buildHeaders(),
            });

            const data = response.data || {};
            const content = data?.message || data?.mensagem || 'Ação confirmada.';
            this.appendMessage('assistant', content, {
                action: data?.acao_sugerida || null,
            });

            if (data?.requires_more_info || data?.completed === false) {
                this.pendingAction = {
                    acao: payload.acao,
                    dados: payload.dados,
                    metadata: payload.metadata,
                };
            } else {
                this.pendingAction = null;
                this.hidePendingAction();
            }

            this.setStatus('Ação confirmada com sucesso.');
        } catch (error) {
            console.error(error);
            const payload = error?.response?.data || {};
            const messageText = payload?.message || error?.message || 'Falha ao confirmar a ação.';
            this.appendMessage('assistant', messageText);
            this.setStatus(messageText);
        } finally {
            this.isSending = false;
            this.updateControls();
        }
    }

    async loadHistory() {
        if (this.isSending) {
            return;
        }

        this.isSending = true;
        this.updateControls();
        this.setStatus('Carregando histórico...');

        try {
            const response = await window.axios.get(this.historyEndpoint, {
                headers: this.buildHeaders(),
                params: {
                    session_id: this.sessionId(),
                    limit: 50,
                },
            });

            const messages = response.data?.data || [];
            this.messagesContainer.innerHTML = '';

            messages.forEach((message) => {
                this.appendMessage(
                    message.role === 'assistant' ? 'assistant' : 'user',
                    message.content || '',
                    { timestamp: message.created_at ? formatTimestamp(new Date(message.created_at)) : null }
                );
            });

            this.setStatus('Histórico carregado.');
        } catch (error) {
            console.error(error);
            this.setStatus(error?.response?.data?.message || 'Falha ao carregar o histórico.');
        } finally {
            this.isSending = false;
            this.updateControls();
        }
    }

    appendMessage(role, content, meta = {}) {
        if (!this.messagesContainer) {
            return;
        }

        const article = document.createElement('article');
        article.className = role === 'assistant'
            ? 'rounded-3xl border border-orange-400/15 bg-orange-500/8 p-4 text-sm leading-6 text-orange-50'
            : 'ml-auto rounded-3xl border border-white/10 bg-white/8 p-4 text-sm leading-6 text-white';

        const header = document.createElement('div');
        header.className = 'mb-2 flex items-center justify-between gap-3 text-[11px] uppercase tracking-[0.22em] text-white/45';
        header.innerHTML = `
            <span>${role === 'assistant' ? 'Chatbot' : 'Você'}</span>
            <span>${meta.timestamp || formatTimestamp()}</span>
        `;

        const body = document.createElement('div');
        body.className = 'whitespace-pre-wrap';
        body.textContent = content;

        article.appendChild(header);
        article.appendChild(body);

        if (meta.action) {
            const tag = document.createElement('div');
            tag.className = 'mt-3 inline-flex rounded-full border border-white/10 px-2.5 py-1 text-[11px] font-medium uppercase tracking-[0.18em] text-white/60';
            tag.textContent = `Ação sugerida: ${meta.action}`;
            article.appendChild(tag);
        }

        this.messagesContainer.appendChild(article);
        this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
    }

    showPendingAction(data) {
        if (!this.pendingCard || !this.pendingSummary) {
            return;
        }

        const action = data?.acao_sugerida || data?.dados_estruturados?.acao_sugerida || 'acao';
        const total = data?.dados_estruturados?.total_registros || 0;
        this.pendingSummary.textContent = `Há uma ação pendente para confirmação: ${action} (${total} registro(s)).`;
        this.pendingCard.hidden = false;
    }

    hidePendingAction() {
        if (!this.pendingCard || !this.pendingSummary) {
            return;
        }

        this.pendingCard.hidden = true;
        this.pendingSummary.textContent = '';
    }

    resolveAudioMimeType() {
        if (typeof MediaRecorder === 'undefined') {
            return '';
        }

        for (const mimeType of AUDIO_MIME_TYPES) {
            if (typeof MediaRecorder.isTypeSupported !== 'function' || MediaRecorder.isTypeSupported(mimeType)) {
                return mimeType;
            }
        }

        return '';
    }

    async startRecording() {
        if (this.isRecording || this.isSending) {
            return;
        }

        if (!navigator.mediaDevices?.getUserMedia) {
            this.setStatus('Seu navegador nao suporta gravação de áudio.');
            return;
        }

        try {
            this.resetAudio({ keepStatus: true });
            this.mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            this.audioMimeType = this.resolveAudioMimeType();
            const options = this.audioMimeType ? { mimeType: this.audioMimeType } : undefined;
            this.mediaRecorder = new MediaRecorder(this.mediaStream, options);
            this.audioChunks = [];

            this.mediaRecorder.addEventListener('dataavailable', (event) => {
                if (event.data && event.data.size > 0) {
                    this.audioChunks.push(event.data);
                }
            });

            this.mediaRecorder.addEventListener('stop', () => {
                if (!this.audioChunks.length) {
                    this.setStatus('Nenhum áudio foi capturado.');
                    this.updateControls();
                    return;
                }

                this.audioBlob = new Blob(this.audioChunks, {
                    type: this.audioMimeType || this.audioChunks[0].type || 'audio/webm',
                });
                this.audioMimeType = this.audioBlob.type || this.audioMimeType || 'audio/webm';
                this.setAudioPreview(this.audioBlob);
                this.setStatus('Gravação concluída. Você já pode enviar.');
                this.updateControls();
            });

            this.mediaRecorder.start();
            this.isRecording = true;
            this.setStatus('Gravando áudio...');
            this.updateControls();
        } catch (error) {
            console.error(error);
            this.setStatus('Não foi possível iniciar a gravação. Verifique a permissão do microfone.');
            this.cleanupMediaStream();
            this.updateControls();
        }
    }

    stopRecording() {
        if (!this.mediaRecorder || !this.isRecording) {
            return;
        }

        this.isRecording = false;
        this.mediaRecorder.stop();
        this.cleanupMediaStream();
        this.updateControls();
    }

    resetAudio({ keepStatus = false } = {}) {
        this.audioBlob = null;
        this.audioMimeType = '';
        this.audioChunks = [];
        this.mediaRecorder = null;
        this.isRecording = false;
        this.cleanupMediaStream();
        this.clearAudioPreview();

        if (!keepStatus) {
            this.setStatus('Pronto para conversar com o chatbot.');
        }

        this.updateControls();
    }

    setAudioPreview(blob) {
        if (!this.audioPreview) {
            return;
        }

        this.clearAudioPreview();
        this.audioUrl = URL.createObjectURL(blob);
        this.audioPreview.src = this.audioUrl;
        this.audioPreview.hidden = false;
    }

    clearAudioPreview() {
        if (this.audioUrl) {
            URL.revokeObjectURL(this.audioUrl);
            this.audioUrl = null;
        }

        if (this.audioPreview) {
            this.audioPreview.removeAttribute('src');
            this.audioPreview.hidden = true;
        }
    }

    cleanupMediaStream() {
        if (this.mediaStream) {
            this.mediaStream.getTracks().forEach((track) => track.stop());
            this.mediaStream = null;
        }
    }

    setStatus(message) {
        if (this.statusOutput) {
            this.statusOutput.textContent = message;
        }
    }

    updateControls() {
        if (this.sendButton) {
            this.sendButton.disabled = this.isSending || this.isRecording;
        }
        if (this.confirmButton) {
            this.confirmButton.disabled = this.isSending || !this.pendingAction;
        }
        if (this.historyButton) {
            this.historyButton.disabled = this.isSending;
        }
        if (this.recordButton) {
            this.recordButton.disabled = this.isSending || this.isRecording;
        }
        if (this.stopButton) {
            this.stopButton.disabled = this.isSending || !this.isRecording;
        }
        if (this.resetAudioButton) {
            this.resetAudioButton.disabled = this.isSending || (!this.audioBlob && !this.isRecording);
        }
    }
}

export function initChatClients() {
    document.querySelectorAll('[data-chat-client]').forEach((root) => {
        if (root.__chatClient) {
            return;
        }

        root.__chatClient = new ChatClient(root);
    });
}

export { ChatClient };
