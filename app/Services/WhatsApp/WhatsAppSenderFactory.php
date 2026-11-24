<?php

namespace App\Services\WhatsApp;

use App\Models\MessageTemplate;
use App\Models\SystemSetting;
use App\Models\WhatsAppAccount;
use RuntimeException;

class WhatsAppSenderFactory
{
    public function __construct(private EvolutionApiClient $evolutionClient) {}

    public function make(?WhatsAppAccount $account = null): WhatsAppSenderInterface
    {
        return match ($this->driver($account)) {
            'meta' => new MetaSender($this->requireMetaAccount($account)),
            'evolution' => $this->evolution($account),
            default => throw new RuntimeException('Unsupported WhatsApp sender driver.'),
        };
    }

    public function driver(?WhatsAppAccount $account = null): string
    {
        $account ??= WhatsAppAccount::query()->where('is_active', true)->first();
        $configuredDriver = config('whatsapp.driver', 'meta');
        $driver = $account?->driver
            ?: SystemSetting::query()->where('key', 'sender_driver')->value('value')
            ?: $configuredDriver;

        if (! in_array($driver, ['meta', 'evolution'], true)) {
            throw new RuntimeException('The configured WhatsApp sender driver is invalid.');
        }

        return $driver;
    }

    public function evolution(?WhatsAppAccount $account = null): EvolutionSender
    {
        return new EvolutionSender($this->evolutionClient, $account);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendTemplate(
        ?WhatsAppAccount $account,
        string $phone,
        MessageTemplate $template,
        string $customerName = ''
    ): array {
        $sender = $this->make($account);
        if ($sender instanceof MetaSender) {
            if (! $account) {
                throw new RuntimeException('No active Meta WhatsApp account is configured.');
            }

            return $sender->sendTemplate(
                $phone,
                $template->name,
                $template->language,
                $template->components ?? []
            );
        }

        $body = $template->body;
        if (! $body) {
            $bodyComponent = collect($template->components ?? [])
                ->first(fn (array $component): bool => strtoupper($component['type'] ?? '') === 'BODY');
            $body = is_array($bodyComponent) ? ($bodyComponent['text'] ?? null) : null;
        }

        if (! is_string($body) || trim($body) === '') {
            throw new RuntimeException('Evolution templates must include a local text body.');
        }

        return $sender->sendText($phone, str_replace('{{name}}', $customerName, $body));
    }

    private function requireMetaAccount(?WhatsAppAccount $account): WhatsAppAccount
    {
        $account ??= WhatsAppAccount::query()->where('is_active', true)->first();
        if (! $account) {
            throw new RuntimeException('No active Meta WhatsApp account is configured.');
        }

        return $account;
    }
}
