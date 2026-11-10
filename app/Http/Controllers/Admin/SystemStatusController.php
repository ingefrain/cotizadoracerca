<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class SystemStatusController extends Controller
{
    public function __invoke(): View
    {
        $unavailable = 'No disponible';
        $databaseConnection = $unavailable;
        $outboxPending = $unavailable;
        $outboxFailed = $unavailable;

        try {
            DB::connection()->getPdo();
            $databaseConnection = 'Conectada';

            try {
                $outboxPending = OutboxMessage::query()->where('status', OutboxMessage::PENDING)->count();
                $outboxFailed = OutboxMessage::query()->where('status', OutboxMessage::FAILED)->count();
            } catch (Throwable) {
                // Keep the application status page useful when the outbox is not installed.
            }
        } catch (Throwable) {
            // Do not expose connection exceptions or their configuration details.
        }

        $queue = config('queue.default');
        $cache = config('cache.default');
        try {
            $maintenanceMode = app()->isDownForMaintenance() ? 'Activo' : 'Inactivo';
        } catch (Throwable) {
            $maintenanceMode = $unavailable;
        }

        $indicators = [
            'Entorno' => app()->environment() ?: $unavailable,
            'Versión de Laravel' => app()->version() ?: $unavailable,
            'Versión de PHP' => PHP_VERSION ?: $unavailable,
            'Driver de base de datos' => config('database.connections.'.config('database.default').'.driver') ?: $unavailable,
            'Conexión a base de datos' => $databaseConnection,
            'Driver de colas' => config("queue.connections.{$queue}.driver") ?: $unavailable,
            'Driver de caché' => config("cache.stores.{$cache}.driver") ?: $unavailable,
            'Mailer configurado' => config('mail.default') ?: $unavailable,
            'Modo mantenimiento' => $maintenanceMode,
            'Mensajes outbox pendientes' => $outboxPending,
            'Mensajes outbox fallidos' => $outboxFailed,
            'Última ejecución del scheduler' => $unavailable,
            'Versión de la aplicación' => config('app.version') ?: $unavailable,
        ];

        return view('admin.system-status.index', compact('indicators'));
    }
}
