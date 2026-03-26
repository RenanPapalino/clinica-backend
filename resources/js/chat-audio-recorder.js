const SUPPORTED_AUDIO_MIME_TYPES = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
    'audio/ogg',
    'audio/mp4',
];

function guessExtension(mimeType) {
    const normalized = String(mimeType || '').toLowerCase();

    if (normalized.includes('webm')) return 'webm';
    if (normalized.includes('ogg')) return 'ogg';
    if (normalized.includes('mp4')) return 'mp4';
    if (normalized.includes('mpeg') || normalized.includes('mp3')) return 'mp3';
    if (normalized.includes('wav')) return 'wav';

    return 'webm';
}

function createSessionId() {
    if (window.crypto?.randomUUID) {
        return `session_${window.crypto.randomUUID()}`;
    }

    return `session_${Date.now()}`;
}

class ChatAudioRecorder {
    constructor(root) {
        this.root = root;
        this.endpoint = root.dataset.endpoint || '/api/chat/enviar';
        this.defaultProcessType = root.dataset.processType || 'financeiro';
        this.defaultMirrorDrive = root.dataset.mirrorDrive || '';

        this.recordButton = root.querySelector('[data-audio-record]');
        this.stopButton = root.querySelector('[data-audio-stop]');
        this.sendButton = root.querySelector('[data-audio-send]');
        this.resetButton = root.querySelector('[data-audio-reset]');
        this.messageInput = root.querySelector('[data-chat-message]');
        this.tokenInput = root.querySelector('[data-chat-token]');
        this.sessionInput = root.querySelector('[data-chat-session]');
        this.processInput = root.querySelector('[data-chat-process]');
        this.statusOutput = root.querySelector('[data-audio-status]');
        this.responseOutput = root.querySelector('[data-chat-response]');
        this.audioPreview = root.querySelector('[data-audio-preview]');

        this.mediaRecorder = null;
        this.mediaStream = null;
        this.audioChunks = [];
        this.audioBlob = null;
        this.audioMimeType = '';
        this.audioUrl = null;
        this.isRecording = false;
        this.isSending = false;

        if (this.sessionInput && !this.sessionInput.value) {
            this.sessionInput.value = createSessionId();
        }

        this.bindEvents();
        this.updateControls();
        this.setStatus('Pronto para gravar.');
    }

    bindEvents() {
        this.recordButton?.addEventListener('click', () => {
            void this.startRecording();
        });

        this.stopButton?.addEventListener('click', () => {
            this.stopRecording();
        });

        this.sendButton?.addEventListener('click', () => {
            void this.sendRecording();
        });

        this.resetButton?.addEventListener('click', () => {
            this.resetRecording();
        });
    }

    resolveMimeType() {
        if (typeof MediaRecorder === 'undefined') {
            return '';
        }

        for (const mimeType of SUPPORTED_AUDIO_MIME_TYPES) {
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
            this.setStatus('Seu navegador nao suporta gravacao de audio.');
            return;
        }

        try {
            this.resetRecording({ keepStatus: true });

            this.mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            this.audioMimeType = this.resolveMimeType();
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
                    this.setStatus('Nenhum audio foi capturado.');
                    this.updateControls();
                    return;
                }

                this.audioBlob = new Blob(this.audioChunks, {
                    type: this.audioMimeType || this.audioChunks[0].type || 'audio/webm',
                });
                this.audioMimeType = this.audioBlob.type || this.audioMimeType || 'audio/webm';
                this.setPreview(this.audioBlob);
                this.setStatus('Gravacao concluida. Agora voce pode enviar.');
                this.updateControls();
            });

            this.mediaRecorder.start();
            this.isRecording = true;
            this.setStatus('Gravando audio...');
            this.updateControls();
        } catch (error) {
            console.error(error);
            this.setStatus('Nao foi possivel iniciar a gravacao. Verifique a permissao do microfone.');
            this.cleanupStream();
            this.updateControls();
        }
    }

    stopRecording() {
        if (!this.mediaRecorder || !this.isRecording) {
            return;
        }

        this.isRecording = false;
        this.mediaRecorder.stop();
        this.cleanupStream();
        this.updateControls();
    }

    resetRecording({ keepStatus = false } = {}) {
        this.audioBlob = null;
        this.audioChunks = [];
        this.audioMimeType = '';
        this.revokePreview();
        this.cleanupStream();
        this.mediaRecorder = null;
        this.isRecording = false;
        this.isSending = false;

        if (!keepStatus) {
            this.setStatus('Pronto para gravar.');
        }

        this.updateControls();
    }

    async sendRecording() {
        if (!this.audioBlob || this.isRecording || this.isSending) {
            return;
        }

        this.isSending = true;
        this.updateControls();
        this.setStatus('Enviando audio para a MedIA...');

        try {
            const mimeType = this.audioMimeType || this.audioBlob.type || 'audio/webm';
            const extension = guessExtension(mimeType);
            const fileName = `gravacao.${extension}`;
            const file = new File([this.audioBlob], fileName, { type: mimeType });

            const formData = new FormData();
            formData.append('mensagem', this.messageInput?.value?.trim() || '');
            formData.append('tipo_processamento', this.processInput?.value || this.defaultProcessType);
            formData.append('session_id', this.sessionInput?.value || createSessionId());
            formData.append('arquivo', file);
            formData.append('arquivo_nome', fileName);
            formData.append('arquivo_mime_type', mimeType);

            if (this.defaultMirrorDrive !== '') {
                formData.append('espelhar_no_drive', this.defaultMirrorDrive);
            }

            const headers = {
                Accept: 'application/json',
                'Content-Type': 'multipart/form-data',
            };

            const bearerToken = this.tokenInput?.value?.trim();
            if (bearerToken) {
                headers.Authorization = `Bearer ${bearerToken}`;
            }

            const response = await window.axios.post(this.endpoint, formData, { headers });
            const data = response.data || {};
            this.renderResponse(data);
            this.setStatus('Audio enviado com sucesso.');
        } catch (error) {
            console.error(error);
            const message =
                error?.response?.data?.message ||
                error?.message ||
                'Falha ao enviar o audio para a MedIA.';
            this.setStatus(message);
            this.renderResponse(error?.response?.data || { success: false, message });
        } finally {
            this.isSending = false;
            this.updateControls();
        }
    }

    setPreview(blob) {
        if (!this.audioPreview) {
            return;
        }

        this.revokePreview();
        this.audioUrl = URL.createObjectURL(blob);
        this.audioPreview.src = this.audioUrl;
        this.audioPreview.hidden = false;
    }

    revokePreview() {
        if (this.audioUrl) {
            URL.revokeObjectURL(this.audioUrl);
            this.audioUrl = null;
        }

        if (this.audioPreview) {
            this.audioPreview.removeAttribute('src');
            this.audioPreview.hidden = true;
        }
    }

    cleanupStream() {
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

    renderResponse(data) {
        if (!this.responseOutput) {
            return;
        }

        const content = data?.content || data?.mensagem || data?.message || 'Sem resposta.';
        const action = data?.acao_sugerida || data?.dados_estruturados?.acao_sugerida || null;
        const ingestion = data?.arquivo_ingestao?.success ? 'Drive: ok' : null;

        const lines = [content];
        if (action) {
            lines.push(`Acao sugerida: ${action}`);
        }
        if (ingestion) {
            lines.push(ingestion);
        }

        this.responseOutput.textContent = lines.join('\n');
    }

    updateControls() {
        if (this.recordButton) {
            this.recordButton.disabled = this.isRecording || this.isSending;
        }

        if (this.stopButton) {
            this.stopButton.disabled = !this.isRecording || this.isSending;
        }

        if (this.sendButton) {
            this.sendButton.disabled = !this.audioBlob || this.isRecording || this.isSending;
        }

        if (this.resetButton) {
            this.resetButton.disabled = (!this.audioBlob && !this.isRecording) || this.isSending;
        }
    }
}

export function initChatAudioRecorders() {
    document
        .querySelectorAll('[data-chat-audio-recorder]')
        .forEach((root) => {
            if (root.__chatAudioRecorder) {
                return;
            }

            root.__chatAudioRecorder = new ChatAudioRecorder(root);
        });
}

export { ChatAudioRecorder };
