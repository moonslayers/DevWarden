<?php

namespace App\Console\Commands;

use App\Models\SubAgentUsageLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('subagents:prune-usage {--days=90 : Delete sub-agent usage logs older than the given number of days}')]
#[Description('Delete sub-agent usage logs older than the configured retention window')]
class PruneSubAgentUsageLogs extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = Carbon::now()->subDays($days);

        $deleted = SubAgentUsageLog::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->components->info(sprintf('Deleted %d sub-agent usage log(s) older than %d day(s).', $deleted, $days));

        return self::SUCCESS;
    }
}
