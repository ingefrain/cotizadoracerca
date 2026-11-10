<?php

namespace App\Support;

use App\Models\AdminAuditEvent;

class AdminAuditLogger
{
    private const REDACTED_KEYS = [
        'password', 'password_confirmation', 'remember_token', 'session_version',
        'token', 'secret', 'cookie', 'hash',
    ];

    public function record(
        string $action,
        string $entityType,
        int|string|null $entityId,
        ?array $before = null,
        ?array $after = null,
        ?array $context = null,
        ?int $actorId = null,
    ): AdminAuditEvent {
        $impersonation = app(ImpersonationManager::class);
        $actor = $impersonation->actor();
        $context = $context ?? [];
        if ($impersonation->active()) {
            $context['impersonation'] = array_merge(
                $impersonation->auditContext(),
                $context['impersonation'] ?? [],
            );
        }

        return AdminAuditEvent::query()->create([
            'actor_id' => $impersonation->active() ? $actor?->id : ($actorId ?? $actor?->id),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'before' => $this->sanitize($before),
            'after' => $this->sanitize($after),
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'context' => $this->sanitize($context === [] ? null : $context),
            'created_at' => now(),
        ]);
    }

    private function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $safe = [];
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                continue;
            }

            $safe[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $safe;
    }
}
