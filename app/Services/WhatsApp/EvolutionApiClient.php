<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EvolutionApiClient
{
    /**
     * @return array<string, mixed>
     */
    public function get(string $path): array
    {
        return $this->perform(fn (PendingRequest $request): Response => $request->get($this->url($path)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = []): array
    {
        return $this->perform(
            fn (PendingRequest $request): Response => $request->post($this->url($path), $payload)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $path): array
    {
        return $this->perform(fn (PendingRequest $request): Response => $request->delete($this->url($path)));
    }

    /**
     * @param  callable(PendingRequest): Response  $callback
     * @return array<string, mixed>
     */
    private function perform(callable $callback): array
    {
        try {
            $response = $callback($this->request());
        } catch (ConnectionException $exception) {
            throw new WhatsAppConnectionException('Evolution API is unreachable.', previous: $exception);
        }

        if (! $response->successful()) {
            $message = data_get($response->json(), 'message')
                ?? data_get($response->json(), 'error')
                ?? 'Evolution API request failed.';
            $normalizedMessage = strtolower((string) $message);
            $isDisconnected = str_contains($normalizedMessage, 'not connected')
                || str_contains($normalizedMessage, 'connection closed')
                || str_contains($normalizedMessage, 'disconnected');

            if ($response->serverError()
                || in_array($response->status(), [408, 429], true)
                || $isDisconnected) {
                throw new WhatsAppConnectionException((string) $message, $response->status());
            }

            throw new RuntimeException((string) $message, $response->status());
        }

        return $response->json() ?? [];
    }

    private function request(): PendingRequest
    {
        $apiKey = (string) config('whatsapp.evolution_key');
        if ($apiKey === '') {
            throw new RuntimeException('EVOLUTION_API_KEY is not configured.');
        }

        return Http::withHeaders(['apikey' => $apiKey])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('whatsapp.evolution_url'), '/').'/'.ltrim($path, '/');
    }
}
