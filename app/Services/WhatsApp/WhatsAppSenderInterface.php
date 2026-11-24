<?php

namespace App\Services\WhatsApp;

interface WhatsAppSenderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function sendText(string $phone, string $body): array;

    /**
     * @return array<string, mixed>
     */
    public function sendMedia(string $phone, string $file, ?string $caption = null): array;
}
