<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\TenantWhatsAppAccount;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WhatsAppAdminAndWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $regularAdmin;
    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected TenantWhatsAppAccount $accountA;
    protected TenantWhatsAppAccount $accountB;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Super Admin', 'web');
        Role::findOrCreate('super_admin', 'web');

        Permission::findOrCreate('integrations.read', 'web');
        Permission::findOrCreate('integrations.manage', 'web');
        Permission::findOrCreate('whatsapp.read', 'web');
        Permission::findOrCreate('whatsapp.sync', 'web');
        Permission::findOrCreate('whatsapp.webhooks.read', 'web');
        Permission::findOrCreate('whatsapp.webhooks.retry', 'web');

        $this->superAdmin = User::factory()->create([
            'email' => 'superadmin@example.com',
            'password' => Hash::make('AdminPass123!'),
        ]);
        $this->superAdmin->assignRole('Super Admin');

        $this->regularAdmin = User::factory()->create([
            'email' => 'staff@example.com',
            'password' => Hash::make('StaffPass123!'),
        ]);

        $this->tenantA = Tenant::create([
            'company_name' => 'Company A',
            'slug' => 'company-a',
            'status' => 'active',
            'email' => 'contact@company-a.com',
        ]);

        $this->tenantB = Tenant::create([
            'company_name' => 'Company B',
            'slug' => 'company-b',
            'status' => 'active',
            'email' => 'contact@company-b.com',
        ]);

        $this->accountA = TenantWhatsAppAccount::create([
            'tenant_id' => $this->tenantA->id,
            'phone_number_id' => '100000000000001',
            'waba_id' => 'waba_001',
            'business_name' => 'Acme Support',
            'display_phone_number' => '+15550001',
            'access_token' => 'EAAG_dummy_token_company_a_test',
            'status' => 'active',
        ]);

        $this->accountB = TenantWhatsAppAccount::create([
            'tenant_id' => $this->tenantB->id,
            'phone_number_id' => '100000000000002',
            'waba_id' => 'waba_002',
            'business_name' => 'Beta Sales',
            'display_phone_number' => '+15550002',
            'access_token' => 'EAAG_dummy_token_company_b_test',
            'status' => 'active',
        ]);

        Config::set('services.whatsapp.app_secret', 'secret_meta_app_key');
        Config::set('services.whatsapp.verify_token', 'verify_token_123');
    }

    private function signPayload(array $payload): string
    {
        $content = json_encode($payload);
        return 'sha256=' . hash_hmac('sha256', $content, config('services.whatsapp.app_secret'));
    }

    /**
     * WA-001: Valid Meta signature is accepted and event is durably accepted.
     */
    public function test_wa_001_valid_signature_is_accepted_and_event_is_durably_stored(): void
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'waba_001',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '+15550001',
                                    'phone_number_id' => '100000000000001',
                                ],
                                'contacts' => [
                                    ['profile' => ['name' => 'Alice'], 'wa_id' => '15559998888'],
                                ],
                                'messages' => [
                                    [
                                        'from' => '15559998888',
                                        'id' => 'wamid.HBgLMTU1NTk5OTg4ODgVAgASGBQzQTkyMz',
                                        'timestamp' => '1670000000',
                                        'text' => ['body' => 'Hello support!'],
                                        'type' => 'text',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $signature = $this->signPayload($payload);

        $response = $this->withHeader('X-Hub-Signature-256', $signature)
            ->postJson('/api/v1/webhooks/meta/whatsapp', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        // Verify event is durably recorded in whatsapp_webhook_events
        $this->assertDatabaseHas('whatsapp_webhook_events', [
            'event_id' => 'wamid.HBgLMTU1NTk5OTg4ODgVAgASGBQzQTkyMz',
            'tenant_id' => $this->tenantA->id,
            'processing_status' => 'processed',
        ]);

        // Verify message created
        $this->assertDatabaseHas('whatsapp_messages', [
            'provider_message_id' => 'wamid.HBgLMTU1NTk5OTg4ODgVAgASGBQzQTkyMz',
            'direction' => 'inbound',
            'body' => 'Hello support!',
        ]);
    }

    /**
     * WA-002: Invalid or missing signature is rejected and no business records are mutated.
     */
    public function test_wa_002_invalid_or_missing_signature_is_rejected(): void
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [],
        ];

        // 1. Missing signature
        $resMissing = $this->postJson('/api/v1/webhooks/meta/whatsapp', $payload);
        $resMissing->assertStatus(401);

        // 2. Invalid signature
        $resInvalid = $this->withHeader('X-Hub-Signature-256', 'sha256=invalidhash')
            ->postJson('/api/v1/webhooks/meta/whatsapp', $payload);
        $resInvalid->assertStatus(401);

        $this->assertDatabaseCount('whatsapp_webhook_events', 0);
        $this->assertDatabaseCount('whatsapp_messages', 0);
    }

    /**
     * WA-003: Unknown phone-number ID is handled safely without arbitrary tenant writes.
     */
    public function test_wa_003_unknown_phone_number_id_handled_safely_without_tenant_mutation(): void
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'waba_unknown',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '+19999999',
                                    'phone_number_id' => '999999999999999',
                                ],
                                'messages' => [
                                    [
                                        'from' => '15557776666',
                                        'id' => 'wamid.unknown001',
                                        'text' => ['body' => 'Test unknown'],
                                        'type' => 'text',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $signature = $this->signPayload($payload);

        $response = $this->withHeader('X-Hub-Signature-256', $signature)
            ->postJson('/api/v1/webhooks/meta/whatsapp', $payload);

        $response->assertStatus(200);

        // Webhook event is recorded with failed status and no tenant mutation
        $this->assertDatabaseHas('whatsapp_webhook_events', [
            'event_id' => 'wamid.unknown001',
            'tenant_id' => null,
            'processing_status' => 'failed',
        ]);

        $this->assertDatabaseCount('whatsapp_messages', 0);
        $this->assertDatabaseCount('conversations', 0);
    }

    /**
     * WA-004: Duplicate delivery does not create duplicate business records.
     */
    public function test_wa_004_duplicate_delivery_does_not_create_duplicate_records(): void
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'waba_001',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'metadata' => [
                                    'phone_number_id' => '100000000000001',
                                ],
                                'messages' => [
                                    [
                                        'from' => '15559998888',
                                        'id' => 'wamid.duplicate_test_123',
                                        'text' => ['body' => 'Duplicate body test'],
                                        'type' => 'text',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $signature = $this->signPayload($payload);

        // First delivery
        $this->withHeader('X-Hub-Signature-256', $signature)
            ->postJson('/api/v1/webhooks/meta/whatsapp', $payload)
            ->assertStatus(200);

        // Second delivery (duplicate delivery retry from Meta)
        $this->withHeader('X-Hub-Signature-256', $signature)
            ->postJson('/api/v1/webhooks/meta/whatsapp', $payload)
            ->assertStatus(200);

        $this->assertEquals(
            1,
            WhatsAppMessage::where('provider_message_id', 'wamid.duplicate_test_123')->count()
        );
        $this->assertEquals(
            1,
            WhatsAppWebhookEvent::where('event_id', 'wamid.duplicate_test_123')->count()
        );
    }

    /**
     * WA-005 & WA-006: Failed webhook retry is authorized, queued and idempotent.
     */
    public function test_wa_005_and_006_failed_webhook_retry_is_authorized_and_idempotent(): void
    {
        $event = WhatsAppWebhookEvent::create([
            'event_id' => 'wamid.failed_event_1',
            'idempotency_key' => 'wamid.failed_event_1',
            'event_type' => 'messages',
            'tenant_id' => $this->tenantA->id,
            'phone_number_id' => '100000000000001',
            'payload' => [
                'metadata' => ['phone_number_id' => '100000000000001'],
                'messages' => [
                    [
                        'from' => '15551112222',
                        'id' => 'wamid.failed_event_1',
                        'text' => ['body' => 'Retried message!'],
                        'type' => 'text',
                    ],
                ],
            ],
            'processing_status' => 'failed',
            'attempt_count' => 1,
            'last_error' => 'Simulated previous temporary error',
        ]);

        $adminToken = $this->superAdmin->createToken('admin-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson("/api/auth/admin/whatsapp/webhooks/{$event->id}/retry");

        $response->assertStatus(200);
        $event->refresh();
        $this->assertEquals('processed', $event->processing_status);

        $this->assertDatabaseHas('whatsapp_messages', [
            'provider_message_id' => 'wamid.failed_event_1',
            'body' => 'Retried message!',
        ]);

        // Attempting to retry an already-processed event returns 409 conflict
        $conflictRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson("/api/auth/admin/whatsapp/webhooks/{$event->id}/retry");
        $conflictRes->assertStatus(409);
        $conflictRes->assertJsonFragment(['error' => 'already_processed']);
    }

    /**
     * WA-007: Tenant A's webhook cannot mutate Tenant B's contacts, conversations, or accounts.
     */
    public function test_wa_007_tenant_isolation_is_strictly_enforced(): void
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'waba_001',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'metadata' => [
                                    'phone_number_id' => '100000000000001', // Tenant A's phone number
                                ],
                                'messages' => [
                                    [
                                        'from' => '15553334444',
                                        'id' => 'wamid.isolation_check_1',
                                        'text' => ['body' => 'Tenant A message'],
                                        'type' => 'text',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $signature = $this->signPayload($payload);

        $this->withHeader('X-Hub-Signature-256', $signature)
            ->postJson('/api/v1/webhooks/meta/whatsapp', $payload)
            ->assertStatus(200);

        // Created conversation must belong ONLY to Tenant A
        $this->assertDatabaseHas('conversations', [
            'tenant_id' => $this->tenantA->id,
            'customer_phone' => '15553334444',
        ]);

        $this->assertDatabaseMissing('conversations', [
            'tenant_id' => $this->tenantB->id,
            'customer_phone' => '15553334444',
        ]);
    }

    /**
     * WA-008: Admin APIs and logs do not expose sensitive access tokens or secret values.
     */
    public function test_wa_008_admin_apis_do_not_expose_secrets(): void
    {
        $adminToken = $this->superAdmin->createToken('admin-token')->plainTextToken;

        // 1. Integration Settings Status
        $statusRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/auth/admin/integrations/whatsapp/status');

        $statusRes->assertStatus(200);
        $statusJson = $statusRes->getContent();
        $this->assertStringNotContainsString('secret_meta_app_key', $statusJson);
        $this->assertStringNotContainsString('verify_token_123', $statusJson);

        // 2. Accounts List
        $accountsRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/auth/admin/whatsapp/accounts');

        $accountsRes->assertStatus(200);
        $accountsJson = $accountsRes->getContent();
        $this->assertStringNotContainsString('EAAG_dummy_token_company_a_test', $accountsJson);
        $this->assertStringNotContainsString('EAAG_dummy_token_company_b_test', $accountsJson);

        // Phone numbers are masked
        $accountsRes->assertJsonFragment([
            'display_phone_number' => '+15****01',
        ]);

        // 3. Phone Numbers endpoint
        $numbersRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/auth/admin/whatsapp/numbers');
        $numbersRes->assertStatus(200);
        $this->assertStringNotContainsString('EAAG_dummy_token', $numbersRes->getContent());
    }

    public function test_get_webhook_verification_challenge(): void
    {
        $response = $this->get('/api/v1/webhooks/meta/whatsapp?hub.mode=subscribe&hub.verify_token=verify_token_123&hub.challenge=test_challenge_code_987');

        $response->assertStatus(200);
        $this->assertSame('test_challenge_code_987', $response->getContent());

        // Invalid token returns 403
        $invalid = $this->get('/api/v1/webhooks/meta/whatsapp?hub.mode=subscribe&hub.verify_token=wrong_token&hub.challenge=test');
        $invalid->assertStatus(403);
    }

    public function test_sync_account_details_with_fake_meta_api(): void
    {
        Http::fake([
            'https://graph.facebook.com/v23.0/100000000000001*' => Http::response([
                'display_phone_number' => '+1 555-0001',
                'verified_name' => 'Acme Official Support',
                'quality_rating' => 'GREEN',
            ], 200),
            'https://graph.facebook.com/v23.0/waba_001*' => Http::response([
                'name' => 'Acme Global Inc',
            ], 200),
        ]);

        $adminToken = $this->superAdmin->createToken('admin-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson("/api/auth/admin/whatsapp/accounts/{$this->accountA->id}/sync");

        $response->assertStatus(200);
        $response->assertJsonPath('data.business_name', 'Acme Global Inc');
        $response->assertJsonPath('data.quality_rating', 'GREEN');

        $this->accountA->refresh();
        $this->assertEquals('Acme Global Inc', $this->accountA->business_name);
        $this->assertEquals('GREEN', $this->accountA->quality_rating);
        $this->assertNotNull($this->accountA->last_synced_at);
    }
}
