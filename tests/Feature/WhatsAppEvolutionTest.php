<?php

namespace Tests\Feature;

use App\Jobs\SendCampaignMessage;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ConsentRecord;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\CampaignPacing;
use App\Services\WhatsApp\WhatsAppSenderFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WhatsAppEvolutionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_send_uses_evolution_and_persists_the_provider_and_message_id(): void
    {
        config([
            'whatsapp.evolution_url' => 'http://evolution.test',
            'whatsapp.evolution_key' => 'api-secret',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'evolution.test/message/sendText/restaurant' => Http::response([
                'key' => ['id' => 'evolution-message-1'],
            ]),
        ]);
        $account = $this->createEvolutionAccount();
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $response = $this->postJson('/api/v1/whatsapp/send', [
            'phone' => '0555123456',
            'body' => 'Hello from Evolution',
        ]);

        $response->assertCreated()
            ->assertJsonPath('provider', 'evolution')
            ->assertJsonPath('wamid', 'evolution-message-1');
        $this->assertDatabaseHas('whatsapp_messages', [
            'customer_id' => Customer::query()->where('phone', '+966555123456')->value('id'),
            'wamid' => 'evolution-message-1',
            'provider' => 'evolution',
            'body' => 'Hello from Evolution',
        ]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://evolution.test/message/sendText/restaurant'
            && $request->data()['number'] === '966555123456'
            && $request->data()['text'] === 'Hello from Evolution');
        $this->assertSame('evolution', $account->fresh()->driver);
    }

    public function test_evolution_webhook_authenticates_and_stores_an_inbound_message_once(): void
    {
        config(['whatsapp.evolution_webhook_secret' => 'webhook-secret']);
        $this->createEvolutionAccount();
        $payload = [
            'event' => 'messages.upsert',
            'instance' => 'restaurant',
            'data' => [
                'key' => [
                    'id' => 'inbound-message-1',
                    'remoteJid' => '966512345678@s.whatsapp.net',
                    'fromMe' => false,
                ],
                'pushName' => 'Customer One',
                'message' => ['conversation' => 'I need help'],
            ],
        ];

        $this->postJson('/api/v1/webhooks/evolution', $payload)
            ->assertUnauthorized();

        $response = $this->postJson('/api/v1/webhooks/evolution', $payload, [
            'x-evolution-webhook-secret' => 'webhook-secret',
        ]);

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('whatsapp_messages', [
            'wamid' => 'inbound-message-1',
            'provider' => 'evolution',
            'direction' => 'inbound',
            'body' => 'I need help',
        ]);
        $this->assertDatabaseHas('conversation_messages', [
            'sender_type' => 'customer',
            'body' => 'I need help',
        ]);

        $this->postJson('/api/v1/webhooks/evolution', $payload, [
            'x-evolution-webhook-secret' => 'webhook-secret',
        ])->assertOk();

        $this->assertSame(1, WhatsAppMessage::query()->where('wamid', 'inbound-message-1')->count());
        $this->assertSame(1, ConversationMessage::query()->where('body', 'I need help')->count());
    }

    public function test_evolution_connect_returns_the_qr_and_persists_driver_selection(): void
    {
        config([
            'whatsapp.evolution_url' => 'http://evolution.test',
            'whatsapp.evolution_key' => 'api-secret',
            'whatsapp.evolution_webhook_secret' => 'webhook-secret',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'evolution.test/instance/create' => Http::response([
                'qrcode' => ['base64' => 'data:image/png;base64,qr-data'],
            ]),
            'evolution.test/instance/connectionState/restaurant' => Http::response([
                'instance' => ['state' => 'close'],
            ]),
        ]);
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $response = $this->postJson('/api/v1/whatsapp/evolution/connect');

        $response->assertOk()
            ->assertJsonPath('driver', 'evolution')
            ->assertJsonPath('connected', false)
            ->assertJsonPath('qr', 'data:image/png;base64,qr-data')
            ->assertJsonPath('instance', 'restaurant');
        $this->assertDatabaseHas('whatsapp_accounts', [
            'driver' => 'evolution',
            'instance_name' => 'restaurant',
            'session_status' => 'disconnected',
        ]);
        $this->assertDatabaseHas('system_settings', [
            'key' => 'sender_driver',
            'value' => 'evolution',
        ]);
    }

    public function test_send_keeps_using_meta_when_the_meta_account_is_active(): void
    {
        config(['whatsapp.driver' => 'meta']);
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v21.0/phone-id/messages' => Http::response([
                'messages' => [['id' => 'wamid.meta-1']],
            ]),
        ]);
        WhatsAppAccount::create([
            'name' => 'Meta',
            'phone_number_id' => 'phone-id',
            'access_token' => 'meta-token',
            'driver' => 'meta',
            'is_active' => true,
        ]);
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $response = $this->postJson('/api/v1/whatsapp/send', [
            'phone' => '+966512345678',
            'body' => 'Hello from Meta',
        ]);

        $response->assertCreated()
            ->assertJsonPath('provider', 'meta')
            ->assertJsonPath('wamid', 'wamid.meta-1');
        $this->assertDatabaseHas('whatsapp_messages', [
            'wamid' => 'wamid.meta-1',
            'provider' => 'meta',
            'body' => 'Hello from Meta',
        ]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v21.0/phone-id/messages'
            && $request->hasHeader('Authorization', 'Bearer meta-token'));
    }

    public function test_evolution_uses_local_templates_and_rejects_meta_template_sync(): void
    {
        Http::preventStrayRequests();
        $this->createEvolutionAccount();
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/v1/whatsapp/templates/sync')
            ->assertStatus(409);

        $response = $this->postJson('/api/v1/whatsapp/templates', [
            'name' => 'greeting',
            'language' => 'ar',
            'body' => 'Hello {{name}}',
        ]);

        $response->assertCreated()
            ->assertJsonPath('meta_status', 'LOCAL')
            ->assertJsonPath('body', 'Hello {{name}}');
        $this->assertDatabaseHas('message_templates', [
            'name' => 'greeting',
            'body' => 'Hello {{name}}',
            'meta_status' => 'LOCAL',
        ]);
    }

    public function test_sender_setting_can_switch_an_evolution_account_back_to_meta(): void
    {
        $account = $this->createEvolutionAccount();
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $response = $this->putJson('/api/v1/settings', [
            'settings' => [
                ['key' => 'sender_driver', 'value' => 'meta'],
            ],
        ]);

        $response->assertOk();
        $this->assertSame('meta', $account->fresh()->driver);
        $this->assertDatabaseHas('system_settings', [
            'key' => 'sender_driver',
            'value' => 'meta',
        ]);
    }

    public function test_evolution_webhook_updates_connection_and_delivery_status(): void
    {
        config(['whatsapp.evolution_webhook_secret' => 'webhook-secret']);
        $account = $this->createEvolutionAccount();

        $this->postJson('/api/v1/webhooks/evolution', [
            'event' => 'CONNECTION_UPDATE',
            'instance' => 'restaurant',
            'data' => ['state' => 'open'],
        ], ['x-evolution-webhook-secret' => 'webhook-secret'])->assertOk();

        $this->assertSame('connected', $account->fresh()->session_status);
        $this->assertNotNull($account->fresh()->last_connected_at);

        $customer = Customer::create([
            'name' => 'Customer One',
            'phone' => '+966512345678',
        ]);
        WhatsAppMessage::create([
            'customer_id' => $customer->id,
            'wamid' => 'outbound-message-1',
            'direction' => 'outbound',
            'status' => 'Sent',
            'provider' => 'evolution',
        ]);

        $this->postJson('/api/v1/webhooks/evolution', [
            'event' => 'MESSAGES_UPDATE',
            'instance' => 'restaurant',
            'data' => [
                'key' => ['id' => 'outbound-message-1'],
                'update' => ['status' => 'READ'],
            ],
        ], ['x-evolution-webhook-secret' => 'webhook-secret'])->assertOk();

        $this->assertDatabaseHas('whatsapp_messages', [
            'wamid' => 'outbound-message-1',
            'status' => 'Read',
        ]);
    }

    public function test_campaign_is_blocked_and_pending_recipients_are_postponed_when_evolution_disconnects(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 09:00:00', 'UTC'));
        config([
            'whatsapp.evolution_url' => 'http://evolution.test',
            'whatsapp.evolution_key' => 'api-secret',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'evolution.test/instance/connectionState/restaurant' => Http::response([
                'instance' => ['state' => 'close'],
            ]),
        ]);
        $this->createEvolutionAccount();
        $customer = Customer::create([
            'name' => 'Customer One',
            'phone' => '+966512345678',
        ]);
        ConsentRecord::create([
            'customer_id' => $customer->id,
            'consent_type' => 'marketing',
            'source' => 'test',
            'consented_at' => now(),
        ]);
        $template = MessageTemplate::create([
            'name' => 'local-greeting',
            'language' => 'ar',
            'meta_status' => 'LOCAL',
            'body' => 'Hello {{name}}',
        ]);
        $campaign = Campaign::create([
            'name' => 'Test campaign',
            'template_id' => $template->id,
            'status' => 'Running',
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'status' => 'Pending',
        ]);

        (new SendCampaignMessage($recipient->id))->handle(
            app(WhatsAppSenderFactory::class),
            app(CampaignPacing::class)
        );

        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'status' => 'Blocked',
        ]);
        $this->assertDatabaseHas('campaign_recipients', [
            'id' => $recipient->id,
            'status' => 'Postponed',
        ]);
        $this->assertSame(
            '2026-11-25 06:00:00',
            $recipient->fresh()->available_at->toDateTimeString()
        );
        $this->assertDatabaseCount('whatsapp_messages', 0);
    }

    public function test_campaign_sends_a_personalized_local_evolution_template(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 09:00:00', 'UTC'));
        config([
            'whatsapp.evolution_url' => 'http://evolution.test',
            'whatsapp.evolution_key' => 'api-secret',
        ]);
        Cache::forget('whatsapp-campaign-next-allowed-at');
        Http::preventStrayRequests();
        Http::fake([
            'evolution.test/instance/connectionState/restaurant' => Http::response([
                'instance' => ['state' => 'open'],
            ]),
            'evolution.test/message/sendText/restaurant' => Http::response([
                'key' => ['id' => 'campaign-message-1'],
            ]),
        ]);
        $this->createEvolutionAccount();
        $customer = Customer::create([
            'name' => 'Customer One',
            'phone' => '+966512345678',
        ]);
        ConsentRecord::create([
            'customer_id' => $customer->id,
            'consent_type' => 'marketing',
            'source' => 'test',
            'consented_at' => now(),
        ]);
        $template = MessageTemplate::create([
            'name' => 'local-greeting',
            'language' => 'ar',
            'meta_status' => 'LOCAL',
            'body' => 'Hello {{name}}',
        ]);
        $campaign = Campaign::create([
            'name' => 'Test campaign',
            'template_id' => $template->id,
            'status' => 'Running',
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'status' => 'Pending',
        ]);

        (new SendCampaignMessage($recipient->id))->handle(
            app(WhatsAppSenderFactory::class),
            app(CampaignPacing::class)
        );

        $this->assertDatabaseHas('whatsapp_messages', [
            'campaign_id' => $campaign->id,
            'wamid' => 'campaign-message-1',
            'provider' => 'evolution',
            'body' => 'Hello Customer One',
        ]);
        $this->assertDatabaseHas('campaign_recipients', [
            'id' => $recipient->id,
            'status' => 'Sent',
        ]);
        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'status' => 'Completed',
        ]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://evolution.test/message/sendText/restaurant'
            && $request->data()['text'] === 'Hello Customer One');
    }

    public function test_campaign_does_not_send_after_the_daily_limit_is_reached(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 09:00:00', 'UTC'));
        config([
            'whatsapp.evolution_url' => 'http://evolution.test',
            'whatsapp.evolution_key' => 'api-secret',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'evolution.test/instance/connectionState/restaurant' => Http::response([
                'instance' => ['state' => 'open'],
            ]),
        ]);
        $this->createEvolutionAccount();
        $customer = Customer::create([
            'name' => 'Customer One',
            'phone' => '+966512345678',
        ]);
        ConsentRecord::create([
            'customer_id' => $customer->id,
            'consent_type' => 'marketing',
            'source' => 'test',
            'consented_at' => now(),
        ]);
        $template = MessageTemplate::create([
            'name' => 'local-greeting',
            'language' => 'ar',
            'meta_status' => 'LOCAL',
            'body' => 'Hello {{name}}',
        ]);
        $campaign = Campaign::create([
            'name' => 'Test campaign',
            'template_id' => $template->id,
            'status' => 'Running',
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'status' => 'Pending',
        ]);
        for ($index = 1; $index <= 40; $index++) {
            WhatsAppMessage::create([
                'customer_id' => $customer->id,
                'campaign_id' => $campaign->id,
                'wamid' => 'sent-message-'.$index,
                'direction' => 'outbound',
                'status' => 'Sent',
                'provider' => 'evolution',
            ]);
        }

        (new SendCampaignMessage($recipient->id))->handle(
            app(WhatsAppSenderFactory::class),
            app(CampaignPacing::class)
        );

        $this->assertDatabaseHas('campaign_recipients', [
            'id' => $recipient->id,
            'status' => 'Pending',
        ]);
        $this->assertSame(40, WhatsAppMessage::query()->where('campaign_id', $campaign->id)->count());
    }

    public function test_campaign_pacing_uses_the_saudi_window_and_breaks_after_twenty_messages(): void
    {
        $pacing = app(CampaignPacing::class);
        $this->travelTo(Carbon::parse('2026-11-24 08:59:00', 'UTC'));

        $this->assertSame(60, $pacing->secondsUntilAllowedWindow());

        $this->travelTo(Carbon::parse('2026-11-24 18:00:00', 'UTC'));
        $this->assertSame(54000, $pacing->secondsUntilAllowedWindow());

        $this->travelTo(Carbon::parse('2026-11-24 09:00:00', 'UTC'));
        Cache::forget('whatsapp-campaign-next-allowed-at');
        $start = now()->timestamp;
        $pacing->scheduleNextMessage(19);
        $randomDelay = (int) Cache::get('whatsapp-campaign-next-allowed-at') - $start;
        $this->assertGreaterThanOrEqual(30, $randomDelay);
        $this->assertLessThanOrEqual(60, $randomDelay);

        $pacing->scheduleNextMessage(20);

        $this->assertSame(600, (int) Cache::get('whatsapp-campaign-next-allowed-at') - $start);
    }

    private function createEvolutionAccount(): WhatsAppAccount
    {
        return WhatsAppAccount::create([
            'name' => 'Evolution',
            'phone_number_id' => 'restaurant',
            'access_token' => '',
            'driver' => 'evolution',
            'instance_name' => 'restaurant',
            'session_status' => 'disconnected',
            'is_active' => true,
        ]);
    }
}
