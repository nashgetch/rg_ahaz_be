<?php

namespace App\Console\Commands;

use App\Models\Round;
use App\Services\HistoricalLeaderboardService;
use Illuminate\Console\Command;

class RebuildLeaderboards extends Command
{
    protected $signature = 'leaderboards:rebuild';
    protected $description = 'Rebuild leaderboards from existing rounds';

    public function handle()
    {
        $service = app(HistoricalLeaderboardService::class);
        $rounds = Round::where('status', 'completed')->with(['user', 'game'])->get();

        $this->info('Processing ' . $rounds->count() . ' rounds...');

        $bar = $this->output->createProgressBar($rounds->count());
        $bar->start();

        foreach ($rounds as $round) {
            $service->updateLeaderboards($round->user, $round->game, $round->score);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Leaderboards rebuilt successfully!');

        return 0;
    }
} 