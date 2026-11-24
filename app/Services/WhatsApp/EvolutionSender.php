<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppAccount;
use RuntimeException;

class EvolutionSender implements WhatsAppSenderInterface
{
    public function __construct(
        private EvolutionApiClient $client,
        private ?WhatsAppAccount $account = null
    ) {}

    public function sendText(string $phone, string $body): array
    {
        return $this->client->post($this->instancePath('message/sendText'), [
            'number' => ltrim($phone, '+'),
            'text' => $body,
        ]);
    }

    public function sendMedia(string $phone, string $file, ?string $caption = null): array
    {
        $media = $file;
        if (! filter_var($file, FILTER_VALIDATE_URL) && ! str_starts_with($file, 'data:')) {
            if (! is_file($file) || ! is_readable($file)) {
                throw new RuntimeException('The media file is not readable.');
            }

            $mimeType = mime_content_type($file) ?: 'application/octet-stream';
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new RuntimeException('The media file could not be read.');
            }
            $media = 'data:'.$mimeType.';base64,'.base64_encode($contents);
        }

        return $this->client->post($this->instancePath('message/sendMedia'), [
            'number' => ltrim($phone, '+'),
            'mediatype' => $this->mediaType($file),
            'media' => $media,
            'caption' => $caption ?? '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function connect(): array
    {
        $secret = (string) (config('whatsapp.evolution_webhook_secret')
            ?: config('whatsapp.evolution_key'));
        $webhookUrl = config('whatsapp.evolution_webhook_url')
            ?: rtrim((string) config('app.url'), '/').'/api/v1/webhooks/evolution';

        try {
            return $this->client->post('instance/create', [
                'instanceName' => $this->instanceName(),
                'qrcode' => true,
                'integration' => 'WHATSAPP-BAILEYS',
                'webhook' => [
                    'url' => $webhookUrl,
                    'enabled' => true,
                    'byEvents' => true,
                    'events' => ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'CONNECTION_UPDATE'],
                    'headers' => ['x-evolution-webhook-secret' => $secret],
                ],
            ]);
        } catch (RuntimeException $exception) {
            $message = strtolower($exception->getMessage());
            $instanceExists = in_array($exception->getCode(), [400, 409, 422], true)
                && (str_contains($message, 'already') || str_contains($message, 'exists'));
            if ($exception->getCode() !== 409 && ! $instanceExists) {
                throw $exception;
            }
        }

        return $this->qr();
    }

    /**
     * @return array<string, mixed>
     */
    public function connectionState(): array
    {
        try {
            return $this->client->get($this->instancePath('instance/connectionState'));
        } catch (RuntimeException $exception) {
            if ($exception->getCode() !== 404) {
                throw $exception;
            }

            return ['instance' => ['state' => 'close']];
        }
    }

    public function isConnected(): bool
    {
        $connectionState = $this->connectionState();
        $state = data_get($connectionState, 'instance.state')
            ?? data_get($connectionState, 'state');

        return $state === 'open';
    }

    /**
     * @return array<string, mixed>
     */
    public function qr(): array
    {
        try {
            return $this->client->get($this->instancePath('instance/connect'));
        } catch (RuntimeException $exception) {
            if ($exception->getCode() !== 404) {
                throw $exception;
            }

            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnect(): array
    {
        return $this->client->delete($this->instancePath('instance/logout'));
    }

    private function instancePath(string $endpoint): string
    {
        return $endpoint.'/'.rawurlencode($this->instanceName());
    }

    private function instanceName(): string
    {
        return $this->account?->instance_name ?: (string) config('whatsapp.evolution_instance');
    }

    private function mediaType(string $file): string
    {
        $mimeType = $this->mimeType($file);

        return str_starts_with($mimeType, 'image/')
            ? 'image'
            : (str_starts_with($mimeType, 'video/')
                ? 'video'
                : (str_starts_with($mimeType, 'audio/') ? 'audio' : 'document'));
    }

    private function mimeType(string $file): string
    {
        if (str_starts_with($file, 'data:')) {
            $separator = strpos($file, ';');

            return $separator === false ? 'application/octet-stream' : substr($file, 5, $separator - 5);
        }

        if (is_file($file)) {
            return mime_content_type($file) ?: 'application/octet-stream';
        }

        $path = filter_var($file, FILTER_VALIDATE_URL)
            ? (string) parse_url($file, PHP_URL_PATH)
            : $file;
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
