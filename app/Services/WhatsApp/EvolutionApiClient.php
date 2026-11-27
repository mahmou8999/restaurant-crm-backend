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
            $message = $this->errorMessage($response);
            $normalizedMessage = strtolower($message);
            $isDisconnected = str_contains($normalizedMessage, 'not connected')
                || str_contains($normalizedMessage, 'connection closed')
                || str_contains($normalizedMessage, 'disconnected');

            if ($response->serverError()
                || in_array($response->status(), [408, 429], true)
                || $isDisconnected) {
                throw new WhatsAppConnectionException($message, $response->status());
            }

            throw new RuntimeException($message, $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * Evolution puts the useful detail in response.message (a string or an
     * array of validation messages), while the top-level message/error fields
     * only carry the HTTP status text (e.g. "Bad Request").
     */
    private function errorMessage(Response $response): string
    {
        $json = $response->json() ?? [];

        $detail = data_get($json, 'response.message')
            ?? data_get($json, 'message')
            ?? data_get($json, 'error')
            ?? 'Evolution API request failed.';

        if (is_array($detail)) {
            $detail = implode('; ', array_map(
                fn ($item) => is_array($item) ? implode(' ', $item) : (string) $item,
                $detail
            ));
        }

        return (string) $detail;
    }

    private function request(): PendingRequest
    {
        $apiKey = (string) config('whatsapp.evolution_key');
        if ($apiKey === '') {
            throw new RuntimeException('EVOLUTION_API_KEY is not configured.');
        }

        return Http::withHeaders(['apikey' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('whatsapp.evolution_url'), '/').'/'.ltrim($path, '/');
    }
}
