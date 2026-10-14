<?php

use App\Http\Controllers\Api\ChannelController;
use App\Models\Channel;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sync every connected channel that has API credentials, following each channel's sync frequency.
// Run `php artisan schedule:work` (or a cron calling `schedule:run`) to keep channels syncing in the background.
Artisan::command('channels:sync {--force : Sync every channel now, ignoring its frequency}', function () {
    $channels = Channel::where('status', 'connected')->whereNotNull('credentials')->get()
        ->filter(fn (Channel $channel) => $this->option('force') || $channel->isDueForSync());

    foreach ($channels as $channel) {
        $logs = ChannelController::runSync($channel);
        $failed = collect($logs)->firstWhere('status', 'failed');
        $this->line("{$channel->name} ({$channel->handle}): ".($failed ? "failed: {$failed->error}" : collect($logs)->map(fn ($l) => "{$l->job_type} {$l->records}")->implode(', ')));
    }

    $this->info("Synced {$channels->count()} channel(s).");
})->purpose('Pull orders and inventory from connected marketplaces');

Schedule::command('channels:sync')->everyFiveMinutes()->withoutOverlapping();
