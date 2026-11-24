<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MetaSender implements WhatsAppSenderInterface
{
    public function __construct(private WhatsAppAccount $account) {}

    public function sendText(string $phone, string $body): array
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => ltrim($phone, '+'),
            'type' => 'text',
            'text' => ['body' => $body, 'preview_url' => false],
        ]);
    }

    public function sendTemplate(
        string $phone,
        string $templateName,
        string $language = 'ar',
        array $components = []
    ): array {
        $template = [
            'name' => $templateName,
            'language' => ['code' => $language],
        ];
        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => ltrim($phone, '+'),
            'type' => 'template',
            'template' => $template,
        ]);
    }

    public function sendMedia(string $phone, string $file, ?string $caption = null): array
    {
        if (! is_file($file) || ! is_readable($file)) {
            throw new RuntimeException('The media file is not readable.');
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('The media file could not be read.');
        }
        $mimeType = mime_content_type($file) ?: 'application/octet-stream';
        $upload = $this->request()
            ->attach('file', $contents, basename($file), ['Content-Type' => $mimeType])
            ->post($this->baseUrl().'/media', ['messaging_product' => 'whatsapp']);

        $this->throwIfFailed($upload);
        $mediaId = $upload->json('id');
        if (! is_string($mediaId) || $mediaId === '') {
            throw new RuntimeException('WhatsApp did not return a media ID.');
        }

        $type = str_starts_with($mimeType, 'image/')
            ? 'image'
            : (str_starts_with($mimeType, 'video/')
                ? 'video'
                : (str_starts_with($mimeType, 'audio/') ? 'audio' : 'document'));
        $media = ['id' => $mediaId];
        if ($caption !== null) {
            $media['caption'] = $caption;
        }
        if ($type === 'document') {
            $media['filename'] = basename($file);
        }

        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => ltrim($phone, '+'),
            'type' => $type,
            $type => $media,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(array $payload): array
    {
        $response = $this->request()->post($this->baseUrl().'/messages', $payload);
        $this->throwIfFailed($response);

        return $response->json() ?? [];
    }

    private function request(): PendingRequest
    {
        if (! $this->account->access_token || ! $this->account->phone_number_id) {
            throw new RuntimeException('The active Meta WhatsApp account is incomplete.');
        }

        return Http::withToken($this->account->access_token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    private function baseUrl(): string
    {
        $version = config('services.whatsapp.graph_version', 'v21.0');

        return "https://graph.facebook.com/{$version}/{$this->account->phone_number_id}";
    }

    private function throwIfFailed(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('error.message') ?? 'WhatsApp API request failed';
        if ($response->serverError() || $response->status() === 408) {
            throw new WhatsAppConnectionException($message, $response->status());
        }

        throw new RuntimeException($message, $response->status());
    }
}
