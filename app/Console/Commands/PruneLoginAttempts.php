<?php

namespace App\Console\Commands;

use App\Models\LoginAttempt;
use Illuminate\Console\Command;

class PruneLoginAttempts extends Command
{
    protected $signature = 'security:prune-login-attempts {--dry-run : Cuenta los registros antiguos sin eliminarlos}';

    protected $description = 'Elimina intentos de acceso anteriores al periodo de retención configurado';

    public function handle(): int
    {
        $days = max(1, (int) config('security.login_attempt_retention_days', 90));
        $cutoff = now()->subDays($days);
        $query = LoginAttempt::query()->where('created_at', '<', $cutoff);
        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->components->info("Registros que se eliminarían (retención: {$days} días): {$count}");

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->components->info("Registros eliminados (retención: {$days} días): {$deleted}");

        return self::SUCCESS;
    }
}
