<?php

namespace Tests\Feature;

use App\Enums\CampaignEvent;
use App\Enums\CampaignSendType;
use App\Enums\EmailTemplateType;
use App\Jobs\DispatchCampaignJob;
use App\Models\Campaign;
use App\Models\EmailTemplate;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CampaignEmailNotification;
use App\Services\CampaignEmailService;
use App\Services\EmailTemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailTemplateAndCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');

        $this->admin = User::factory()->create([
            'email' => 'superadmin@example.com',
            'password' => Hash::make('AdminSecret123!'),
        ]);
        $this->admin->assignRole('super_admin');

        $loginResponse = $this->postJson('/api/auth/admin/login', [
            'email' => 'superadmin@example.com',
            'password' => 'AdminSecret123!',
        ]);

        $this->token = $loginResponse->json('token');
    }

    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ];
    }

    /* -------------------------------------------------------------------------
     * 1. EMAIL TEMPLATE TESTS
     * ------------------------------------------------------------------------- */

    public function test_can_list_email_templates(): void
    {
        EmailTemplate::create([
            'name' => 'Template 1',
            'key' => 'TEMPLATE_1',
            'subject' => 'Subject 1',
            'body' => '<p>Hello 1</p>',
            'type' => 'html',
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/auth/admin/email-templates');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'current_page',
                    'data' => [
                        '*' => ['id', 'name', 'key', 'subject', 'type', 'is_active', 'created_at', 'updated_at'],
                    ],
                ],
            ]);
    }

    public function test_can_create_email_template(): void
    {
        $payload = [
            'name' => 'Welcome Customer',
            'key' => 'WELCOME_CUSTOMER',
            'subject' => 'Welcome to {{CompanyName}}!',
            'body' => '<p>Hello {{UserName}}, welcome to {{CompanyName}}!</p>',
            'type' => 'html',
            'is_active' => true,
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/auth/admin/email-templates', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.key', 'WELCOME_CUSTOMER');

        $this->assertDatabaseHas('email_templates', [
            'key' => 'WELCOME_CUSTOMER',
            'name' => 'Welcome Customer',
        ]);
    }

    public function test_cannot_create_template_with_invalid_key_or_duplicate(): void
    {
        // Invalid key (lowercase)
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/auth/admin/email-templates', [
                'name' => 'Bad Key',
                'key' => 'bad-key-here',
                'subject' => 'Hello',
                'body' => 'Body text',
            ]);
        $response->assertStatus(422);

        // Duplicate key
        EmailTemplate::create([
            'name' => 'Existing',
            'key' => 'EXISTING_KEY',
            'subject' => 'Subject',
            'body' => 'Body',
        ]);

        $response2 = $this->withHeaders($this->authHeaders())
            ->postJson('/api/auth/admin/email-templates', [
                'name' => 'Existing Duplicate',
                'key' => 'EXISTING_KEY',
                'subject' => 'Subject 2',
                'body' => 'Body 2',
            ]);
        $response2->assertStatus(422);
    }

    public function test_can_get_template_by_id_and_by_key(): void
    {
        $template = EmailTemplate::create([
            'name' => 'Test Key Lookup',
            'key' => 'TEST_LOOKUP',
            'subject' => 'Hello {{UserName}}',
            'body' => 'Your code is {{Otp}}',
            'type' => 'plain',
            'is_active' => true,
        ]);

        // By ID
        $resId = $this->withHeaders($this->authHeaders())
            ->getJson("/api/auth/admin/email-templates/{$template->id}");
        $resId->assertStatus(200)
            ->assertJsonPath('data.key', 'TEST_LOOKUP');

        // By Key
        $resKey = $this->withHeaders($this->authHeaders())
            ->getJson('/api/auth/admin/email-templates/by-key/TEST_LOOKUP');
        $resKey->assertStatus(200)
            ->assertJsonPath('data.id', $template->id);
    }

    public function test_can_update_email_template(): void
    {
        $template = EmailTemplate::create([
            'name' => 'Original Name',
            'key' => 'ORIGINAL_KEY',
            'subject' => 'Original Subject',
            'body' => 'Original Body',
            'type' => 'html',
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->putJson("/api/auth/admin/email-templates/{$template->id}", [
                'name' => 'Updated Name',
                'subject' => 'Updated Subject',
                'body' => 'Updated Body',
                'is_active' => false,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('email_templates', [
            'id' => $template->id,
            'name' => 'Updated Name',
            'is_active' => 0,
        ]);
    }

    public function test_can_update_template_status(): void
    {
        $template = EmailTemplate::create([
            'name' => 'Status Test',
            'key' => 'STATUS_TEST',
            'subject' => 'Sub',
            'body' => 'Body',
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->patchJson("/api/auth/admin/email-templates/{$template->id}/status", [
                'is_active' => false,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($template->fresh()->is_active);
    }

    public function test_can_preview_template(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/auth/admin/email-templates/preview', [
                'key' => 'FORGOT_PASSWORD',
                'subject' => 'Reset for {{UserName}}',
                'body' => 'Hello {{UserName}}, OTP: {{Otp}} in {{CompanyName}}',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonStructure(['status', 'message', 'data' => ['subject', 'body']]);

        $this->assertStringContainsString('Demo User', $response->json('data.body'));
    }

    public function test_can_delete_unassigned_template(): void
    {
        $template = EmailTemplate::create([
            'name' => 'To Delete',
            'key' => 'CUSTOM_TO_DELETE',
            'subject' => 'Sub',
            'body' => 'Body',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/auth/admin/email-templates/{$template->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('email_templates', ['id' => $template->id]);
    }

    public function test_cannot_delete_protected_system_templates(): void
    {
        $template = EmailTemplate::create([
            'name' => 'Protected System Template',
            'key' => 'ADMIN_PASSWORD_RESET_OTP',
            'subject' => 'Protected',
            'body' => 'Protected',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/auth/admin/email-templates/{$template->id}");

        $response->assertStatus(422)
            ->assertJsonPath('status', false);

        $this->assertDatabaseHas('email_templates', ['id' => $template->id]);
    }

    public function test_can_bulk_delete_templates(): void
    {
        $t1 = EmailTemplate::create(['name' => 'B1', 'key' => 'B1_KEY', 'subject' => 'S', 'body' => 'B']);
        $t2 = EmailTemplate::create(['name' => 'B2', 'key' => 'B2_KEY', 'subject' => 'S', 'body' => 'B']);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/auth/admin/email-templates/bulk-delete', [
                'ids' => [$t1->id, $t2->id],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.deleted_count', 2);

        $this->assertDatabaseMissing('email_templates', ['id' => $t1->id]);
        $this->assertDatabaseMissing('email_templates', ['id' => $t2->id]);
    }

    /* -------------------------------------------------------------------------
     * 2. CAMPAIGN TESTS
     * ------------------------------------------------------------------------- */

    public function test_can_list_campaign_events_only_supported(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/auth/admin/campaigns/events');

        $response->assertStatus(200)
            ->assertJsonPath('status', true);

        $events = collect($response->json('data'))->pluck('value')->all();

        // Exactly the 3 required events
        $this->assertContains('forgot_password', $events);
        $this->assertContains('reset_password', $events);
        $this->assertContains('otp_verification', $events);

        // Excluded events must NOT exist
        $this->assertNotContains('register', $events);
        $this->assertNotContains('welcome_user', $events);
        $this->assertNotContains('news_publish', $events);
        $this->assertNotContains('ticket_raised', $events);
    }

    public function test_can_create_trigger_campaign(): void
    {
        $template = EmailTemplate::create([
            'name' => 'Trigger Template',
            'key' => 'TRIGGER_TEMPLATE',
            'subject' => 'Trigger Sub',
            'body' => 'Hello {{UserName}}',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/auth/admin/campaigns', [
                'name' => 'Forgot Password Trigger Campaign',
                'email_template_id' => $template->id,
                'send_type' => 'trigger',
                'event' => 'forgot_password',
                'is_active' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.event', 'forgot_password')
            ->assertJsonPath('data.send_type', 'trigger');

        $this->assertDatabaseHas('campaigns', [
            'name' => 'Forgot Password Trigger Campaign',
            'event' => 'forgot_password',
        ]);
    }

    public function test_rejects_unsupported_campaign_events(): void
    {
        $template = EmailTemplate::create([
            'name' => 'T1',
            'key' => 'T1_KEY',
            'subject' => 'S',
            'body' => 'B',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/auth/admin/campaigns', [
                'name' => 'Unsupported Event Campaign',
                'email_template_id' => $template->id,
                'send_type' => 'trigger',
                'event' => 'register', // not allowed!
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['event']);
    }

    public function test_creating_now_campaign_dispatches_job_and_marks_inactive(): void
    {
        Queue::fake();

        $template = EmailTemplate::create([
            'name' => 'Immediate Template',
            'key' => 'IMMEDIATE_TPL',
            'subject' => 'Urgent Notification',
            'body' => 'Body content',
        ]);

        $targetUser = User::factory()->create();

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/auth/admin/campaigns', [
                'name' => 'Send Now Campaign',
                'email_template_id' => $template->id,
                'send_type' => 'now',
                'target_users' => [$targetUser->id],
                'is_active' => true,
            ]);

        $response->assertStatus(201);

        Queue::assertPushed(DispatchCampaignJob::class);

        // Prevent accidental duplicate send: should be inactive after dispatch
        $campaignId = $response->json('data.id');
        $this->assertDatabaseHas('campaigns', [
            'id' => $campaignId,
            'is_active' => 0,
        ]);
    }

    public function test_can_update_and_delete_campaign(): void
    {
        $template = EmailTemplate::create([
            'name' => 'Tpl',
            'key' => 'TPL_FOR_UPDATE',
            'subject' => 'Sub',
            'body' => 'Body',
        ]);

        $campaign = Campaign::create([
            'name' => 'Initial Campaign',
            'email_template_id' => $template->id,
            'send_type' => CampaignSendType::TRIGGER,
            'event' => CampaignEvent::FORGOT_PASSWORD,
            'is_active' => true,
        ]);

        // Update
        $updateRes = $this->withHeaders($this->authHeaders())
            ->putJson("/api/auth/admin/campaigns/{$campaign->id}", [
                'name' => 'Updated Campaign Name',
                'email_template_id' => $template->id,
                'send_type' => 'trigger',
                'event' => 'reset_password',
                'is_active' => false,
            ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Campaign Name')
            ->assertJsonPath('data.event', 'reset_password');

        // Delete
        $delRes = $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/auth/admin/campaigns/{$campaign->id}");
        $delRes->assertStatus(200);

        $this->assertDatabaseMissing('campaigns', ['id' => $campaign->id]);
    }

    /* -------------------------------------------------------------------------
     * 3. AUTHENTICATION & EVENT TRIGGER TESTS
     * ------------------------------------------------------------------------- */

    public function test_admin_password_reset_job_uses_active_email_template(): void
    {
        Notification::fake();

        $template = EmailTemplate::create([
            'name' => 'Admin OTP Template',
            'key' => 'ADMIN_PASSWORD_RESET_OTP',
            'subject' => 'Your OTP for {{CompanyName}}',
            'body' => '<p>OTP: {{Otp}}, Admin: {{AdminName}}</p>',
            'type' => 'html',
            'is_active' => true,
        ]);

        $encryptedOtp = Crypt::encryptString('654321');

        $job = new \App\Jobs\SendAdminPasswordResetOtpJob(
            $this->admin->id,
            $this->admin->email,
            $encryptedOtp,
            10
        );

        $job->handle();

        Notification::assertSentTo(
            $this->admin,
            CampaignEmailNotification::class,
            function (CampaignEmailNotification $notification) {
                return str_contains($notification->subject, 'WhatsApp CRM')
                    && str_contains($notification->body, '654321');
            }
        );
    }

    public function test_admin_password_reset_job_falls_back_if_no_active_template(): void
    {
        Mail::fake();

        // Inactive template
        EmailTemplate::create([
            'name' => 'Admin OTP Template',
            'key' => 'ADMIN_PASSWORD_RESET_OTP',
            'subject' => 'Inactive',
            'body' => 'Inactive',
            'is_active' => false,
        ]);

        $encryptedOtp = Crypt::encryptString('112233');

        $job = new \App\Jobs\SendAdminPasswordResetOtpJob(
            $this->admin->id,
            $this->admin->email,
            $encryptedOtp,
            10
        );

        $job->handle();

        Mail::assertSent(\App\Mail\AdminPasswordResetOtpMail::class, function ($mail) {
            return $mail->hasTo($this->admin->email);
        });
    }

    public function test_campaign_email_service_triggers_correct_event(): void
    {
        Notification::fake();

        $template = EmailTemplate::create([
            'name' => 'Reset Notification',
            'key' => 'RESET_PASSWORD',
            'subject' => 'Password reset for {{UserName}}',
            'body' => 'Password reset on {{CompanyName}} for {{email}}',
            'type' => 'html',
            'is_active' => true,
        ]);

        $campaign = Campaign::create([
            'name' => 'Reset Password Trigger Campaign',
            'email_template_id' => $template->id,
            'send_type' => CampaignSendType::TRIGGER,
            'event' => CampaignEvent::RESET_PASSWORD,
            'is_active' => true,
        ]);

        $user = User::factory()->create(['email' => 'member@example.com']);

        $service = app(CampaignEmailService::class);
        $service->triggerEvent(CampaignEvent::RESET_PASSWORD, $user, [
            'UserName' => 'Member One',
            'email' => 'member@example.com',
        ]);

        Notification::assertSentTo(
            $user,
            CampaignEmailNotification::class,
            function (CampaignEmailNotification $notification) {
                return str_contains($notification->subject, 'Member One')
                    && str_contains($notification->body, 'member@example.com');
            }
        );
    }

    public function test_unauthorized_user_cannot_access_templates_or_campaigns(): void
    {
        $regularUser = User::factory()->create();
        $token = $regularUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/admin/email-templates');

        $response->assertStatus(403);

        $campResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/admin/campaigns');

        $campResponse->assertStatus(403);
    }

    /* -------------------------------------------------------------------------
     * 4. SCHEDULED CAMPAIGN & IST TIMEZONE TESTS
     * ------------------------------------------------------------------------- */

    public function test_scheduled_campaign_due_in_ist_is_dispatched(): void
    {
        Queue::fake();

        $template = EmailTemplate::create([
            'name' => 'Scheduled Promo',
            'key' => 'SCHEDULED_PROMO',
            'subject' => 'Special Promo for {{UserName}}',
            'body' => '<p>Hello {{UserName}}, get 20% off!</p>',
        ]);

        $recipient = User::factory()->create(['email' => 'client@example.com']);

        // Due 5 minutes ago in IST
        $pastTime = now()->subMinutes(5)->format('Y-m-d H:i:s');

        $campaign = Campaign::create([
            'name' => 'Due Campaign',
            'email_template_id' => $template->id,
            'send_type' => CampaignSendType::SCHEDULE,
            'scheduled_at' => $pastTime,
            'target_users' => [$recipient->id],
            'is_active' => true,
        ]);

        $this->artisan('campaigns:dispatch-scheduled')
            ->expectsOutput("Dispatched scheduled campaign [ID: {$campaign->id}, Name: 'Due Campaign']")
            ->assertExitCode(0);

        Queue::assertPushed(DispatchCampaignJob::class, function ($job) use ($campaign) {
            return $job->campaign->id === $campaign->id;
        });

        $campaign->refresh();
        $this->assertFalse($campaign->is_active);
        $this->assertNotNull($campaign->dispatched_at);
    }

    public function test_future_scheduled_campaign_is_not_dispatched(): void
    {
        Queue::fake();

        $template = EmailTemplate::create([
            'name' => 'Future Promo',
            'key' => 'FUTURE_PROMO',
            'subject' => 'Future Subject',
            'body' => '<p>Future Body</p>',
        ]);

        $futureTime = now()->addHours(2)->format('Y-m-d H:i:s');

        $campaign = Campaign::create([
            'name' => 'Future Campaign',
            'email_template_id' => $template->id,
            'send_type' => CampaignSendType::SCHEDULE,
            'scheduled_at' => $futureTime,
            'target_users' => [1],
            'is_active' => true,
        ]);

        $this->artisan('campaigns:dispatch-scheduled')
            ->expectsOutput('No scheduled campaigns due for dispatch.')
            ->assertExitCode(0);

        Queue::assertNotPushed(DispatchCampaignJob::class);

        $campaign->refresh();
        $this->assertTrue($campaign->is_active);
        $this->assertNull($campaign->dispatched_at);
    }

    public function test_inactive_scheduled_campaign_is_not_dispatched(): void
    {
        Queue::fake();

        $template = EmailTemplate::create([
            'name' => 'Inactive Promo',
            'key' => 'INACTIVE_PROMO',
            'subject' => 'Inactive Subject',
            'body' => '<p>Inactive Body</p>',
        ]);

        $pastTime = now()->subMinutes(10)->format('Y-m-d H:i:s');

        $campaign = Campaign::create([
            'name' => 'Inactive Due Campaign',
            'email_template_id' => $template->id,
            'send_type' => CampaignSendType::SCHEDULE,
            'scheduled_at' => $pastTime,
            'target_users' => [1],
            'is_active' => false,
        ]);

        $this->artisan('campaigns:dispatch-scheduled')
            ->expectsOutput('No scheduled campaigns due for dispatch.')
            ->assertExitCode(0);

        Queue::assertNotPushed(DispatchCampaignJob::class);

        $campaign->refresh();
        $this->assertNull($campaign->dispatched_at);
    }

    public function test_already_dispatched_campaign_is_not_dispatched_again(): void
    {
        Queue::fake();

        $template = EmailTemplate::create([
            'name' => 'Already Dispatched Promo',
            'key' => 'ALREADY_DISPATCHED_PROMO',
            'subject' => 'Subject',
            'body' => '<p>Body</p>',
        ]);

        $pastTime = now()->subMinutes(20)->format('Y-m-d H:i:s');

        $campaign = Campaign::create([
            'name' => 'Done Campaign',
            'email_template_id' => $template->id,
            'send_type' => CampaignSendType::SCHEDULE,
            'scheduled_at' => $pastTime,
            'dispatched_at' => now()->subMinutes(19),
            'target_users' => [1],
            'is_active' => true, // Even if somehow active
        ]);

        $this->artisan('campaigns:dispatch-scheduled')
            ->expectsOutput('No scheduled campaigns due for dispatch.')
            ->assertExitCode(0);

        Queue::assertNotPushed(DispatchCampaignJob::class);
    }

    public function test_campaign_dispatch_job_sends_notification_to_target_user(): void
    {
        Notification::fake();

        $template = EmailTemplate::create([
            'name' => 'Delivery Promo',
            'key' => 'DELIVERY_PROMO',
            'subject' => 'Notice for {{UserName}}',
            'body' => '<p>Hello {{UserName}}, email is {{email}}</p>',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'first_name' => 'Rajesh',
            'last_name' => 'Sharma',
            'email' => 'rajesh@example.com',
        ]);

        $campaign = Campaign::create([
            'name' => 'Job Execution Campaign',
            'email_template_id' => $template->id,
            'send_type' => CampaignSendType::SCHEDULE,
            'scheduled_at' => now()->format('Y-m-d H:i:s'),
            'target_users' => [$user->id],
            'is_active' => false,
            'dispatched_at' => now(),
        ]);

        $job = new DispatchCampaignJob($campaign);
        $job->handle(app(EmailTemplateRenderer::class));

        Notification::assertSentTo($user, CampaignEmailNotification::class, function ($notification) {
            return str_contains($notification->subject, 'Rajesh Sharma')
                && str_contains($notification->body, 'rajesh@example.com');
        });
    }

    public function test_campaign_api_response_returns_ist_scheduled_at_and_status(): void
    {
        $template = EmailTemplate::create([
            'name' => 'Api Test Tpl',
            'key' => 'API_TEST_TPL',
            'subject' => 'Sub',
            'body' => 'Body',
        ]);

        $futureTime = now()->addDays(2)->startOfMinute();
        $scheduledTimeString = $futureTime->format('Y-m-d H:i:s');

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/auth/admin/campaigns', [
                'name' => 'Future Scheduled Campaign',
                'email_template_id' => $template->id,
                'send_type' => 'schedule',
                'scheduled_at' => $scheduledTimeString,
                'target_users' => [1],
                'is_active' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.scheduled_at_raw', $scheduledTimeString)
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.dispatched_at', null);
    }
}
