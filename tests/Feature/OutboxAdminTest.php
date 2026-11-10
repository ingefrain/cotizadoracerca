<?php

namespace Tests\Feature;

use App\Models\AdminAuditEvent;
use App\Models\OutboxMessage;
use App\Services\Outbox\OutboxDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class OutboxAdminTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    public function test_notification_list_and_detail_mask_recipient_and_hide_message_payload(): void
    {
        $admin = $this->adminUser();
        $message = $this->failedMessage('Hidden sensitive message body');

        $this->actingAsAdmin($admin)->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertSee('p***@e***.test')
            ->assertDontSee('persona@example.test')
            ->assertDontSee('Hidden sensitive message body');

        $this->actingAsAdmin($admin)->get(route('admin.notifications.index', ['q' => 'PERSONA@example.test']))
            ->assertOk()->assertSee($message->uuid);
        $this->actingAsAdmin($admin)->get(route('admin.notifications.show', $message))
            ->assertOk()->assertSee('Contenido de la notificación')->assertSee('Se omite por seguridad.')
            ->assertDontSee('Hidden sensitive message body')
            ->assertDontSee('persona@example.test')
            ->assertSee('Error de entrega; detalles redactados.');
    }

    public function test_notification_routes_require_their_specific_permissions(): void
    {
        $this->seedAccessCatalog();
        $message = $this->failedMessage('Cuerpo interno');
        $withoutView = $this->userWithPermissions(['dashboard.view']);
        $this->actingAs($withoutView)->withSession(['auth_session_version' => $withoutView->session_version])
            ->get(route('admin.notifications.index'))->assertForbidden();

        $viewer = $this->userWithPermissions(['notifications.view']);
        $this->actingAs($viewer)->withSession(['auth_session_version' => $viewer->session_version])
            ->post(route('admin.notifications.retry', $message))->assertForbidden();
    }

    public function test_manual_retry_is_conditional_audited_and_resets_only_the_retry_cycle(): void
    {
        $this->seedAccessCatalog();
        $admin = $this->userWithPermissions(['notifications.view', 'notifications.retry']);
        $message = $this->failedMessage('Mensaje no visible');
        $this->actingAs($admin)->withSession(['auth_session_version' => $admin->session_version])
            ->post(route('admin.notifications.retry', $message))->assertRedirect(route('admin.notifications.show', $message));

        $message->refresh();
        $this->assertSame(OutboxMessage::PENDING, $message->status);
        $this->assertSame(0, $message->attempts);
        $this->assertSame(1, $message->retry_generation);
        $this->assertDatabaseHas('admin_audit_events', [
            'actor_id' => $admin->id,
            'action' => 'notification.retried',
            'entity_type' => 'outbox_message',
            'entity_id' => (string) $message->id,
        ]);

        $this->actingAs($admin)->withSession(['auth_session_version' => $admin->session_version])
            ->post(route('admin.notifications.retry', $message))->assertSessionHasErrors('status');
        $this->assertSame(1, AdminAuditEvent::query()->where('action', 'notification.retried')->count());
    }

    private function failedMessage(string $body): OutboxMessage
    {
        $message = app(OutboxDispatcher::class)->email('persona@example.test', [
            'subject' => 'Mensaje privado',
            'message' => $body,
        ], correlationId: (string) Str::uuid());
        $message->forceFill([
            'status' => OutboxMessage::FAILED,
            'attempts' => 6,
            'last_error_type' => 'delivery',
            'last_error_message' => 'Error de entrega; detalles redactados.',
            'processed_at' => now(),
        ])->save();
        $message->attemptsHistory()->create([
            'attempt_number' => 1,
            'lease_token' => (string) Str::uuid(),
            'result' => 'failed',
            'error_type' => 'delivery',
            'error_message' => 'Error de entrega; detalles redactados.',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        return $message;
    }
}
