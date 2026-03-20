import './bootstrap';
import { initChatAudioRecorders } from './chat-audio-recorder';
import { initChatClients } from './chat-client';

window.addEventListener('DOMContentLoaded', () => {
    initChatClients();
    initChatAudioRecorders();
});
