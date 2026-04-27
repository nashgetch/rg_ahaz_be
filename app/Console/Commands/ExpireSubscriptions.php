<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark expired active/trial subscriptions as expired';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $expiredActive = Subscription::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $expiredTrial = Subscription::query()
            ->where('status', 'trial')
            ->whereNotNull('trial_end')
            ->where('trial_end', '<=', now())
            ->update(['status' => 'expired']);

        $this->info('Expired active subscriptions: ' . $expiredActive);
        $this->info('Expired trial subscriptions: ' . $expiredTrial);

        return self::SUCCESS;
    }
}
