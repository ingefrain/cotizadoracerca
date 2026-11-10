<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OutboxMessage;
use App\Services\Outbox\OutboxDispatcher;
use App\Services\Outbox\OutboxTransportResolver;
use App\Support\AdminAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request, OutboxTransportResolver $transports): View
    {
        $channels = $transports->enabledChannels();
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(OutboxMessage::STATUSES)],
            'channel' => ['nullable', Rule::in(array_keys($channels))],
            'q' => ['nullable', 'string', 'max:320'],
        ]);
        $term = trim((string) ($filters['q'] ?? ''));
        $query = OutboxMessage::query()
            ->when($filters['status'] ?? null, fn ($builder, string $status) => $builder->where('status', $status))
            ->when($filters['channel'] ?? null, fn ($builder, string $channel) => $builder->where('channel', $channel));

        if ($term !== '') {
            $recipientHashes = [];
            foreach (array_keys($channels) as $channel) {
                try {
                    $normalizedRecipient = $transports->resolve($channel)->normalizeRecipient($term);
                    $recipientHashes[] = hash('sha256', $normalizedRecipient);
                } catch (\InvalidArgumentException) {
                    // The term is not a valid recipient for this enabled channel.
                }
            }
            $query->where(function ($builder) use ($term, $recipientHashes): void {
                $builder->where(function ($ids) use ($term): void {
                    $ids->where('uuid', $term)->orWhere('correlation_id', $term);
                });
                if ($recipientHashes !== []) {
                    $builder->orWhereIn('recipient_hash', $recipientHashes);
                }
            });
        }

        $messages = $query->latest('created_at')->paginate(20)->withQueryString();
        $messages->getCollection()->each(function (OutboxMessage $message) use ($transports): void {
            $message->setAttribute('masked_recipient', $this->maskedRecipientFor($transports, $message->channel, $message->recipient));
        });

        return view('admin.notifications.index', compact('messages', 'filters', 'channels'));
    }

    public function show(OutboxMessage $outboxMessage, OutboxTransportResolver $transports): View
    {
        $outboxMessage->load('attemptsHistory');
        $maskedRecipient = $this->maskedRecipientFor($transports, $outboxMessage->channel, $outboxMessage->recipient);

        return view('admin.notifications.show', compact('outboxMessage', 'maskedRecipient'));
    }

    public function retry(
        OutboxMessage $outboxMessage,
        OutboxDispatcher $dispatcher,
        AdminAuditLogger $audit,
    ): RedirectResponse {
        $retried = DB::transaction(function () use ($outboxMessage, $dispatcher, $audit): bool {
            $updated = OutboxMessage::query()->whereKey($outboxMessage->id)
                ->where('status', OutboxMessage::FAILED)
                ->update([
                    'status' => OutboxMessage::PENDING,
                    'attempts' => 0,
                    'retry_generation' => DB::raw('retry_generation + 1'),
                    'available_at' => now(),
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'processed_at' => null,
                    'last_error_type' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]);
            if ($updated !== 1) {
                return false;
            }

            $audit->record('notification.retried', 'outbox_message', $outboxMessage->id, null, [
                'uuid' => $outboxMessage->uuid,
                'retry_generation' => $outboxMessage->retry_generation + 1,
            ]);
            $dispatcher->dispatchAfterCommit($outboxMessage->id);

            return true;
        });

        if (! $retried) {
            return back()->withErrors(['status' => 'Solo se pueden reintentar mensajes fallidos.']);
        }

        return redirect()->route('admin.notifications.show', $outboxMessage)->with('status', 'Se solicitó el reintento del mensaje.');
    }

    public static function maskedRecipient(string $recipient): string
    {
        return app(OutboxTransportResolver::class)->resolve('email')->maskRecipient($recipient);
    }

    private function maskedRecipientFor(OutboxTransportResolver $transports, string $channel, string $recipient): string
    {
        try {
            return $transports->resolve($channel)->maskRecipient($recipient);
        } catch (\InvalidArgumentException) {
            // Unknown or disabled channels never reveal their stored recipient.
            return '—';
        }
    }
}
