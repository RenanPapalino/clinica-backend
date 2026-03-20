<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SendGeneratedCredentialsNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $login,
        private readonly string $generatedPassword,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');

        return (new MailMessage)
            ->subject('Seu acesso ao MedIntelligence foi criado')
            ->greeting("Olá, {$notifiable->name}!")
            ->line('Seu cadastro foi criado com sucesso no MedIntelligence.')
            ->line("Login: {$this->login}")
            ->line("Senha temporária: {$this->generatedPassword}")
            ->line('Por segurança, recomendamos alterar a senha após o primeiro acesso.')
            ->action('Acessar plataforma', $frontendUrl . '/login')
            ->line('Se você não solicitou este cadastro, entre em contato com o suporte.');
    }
}
