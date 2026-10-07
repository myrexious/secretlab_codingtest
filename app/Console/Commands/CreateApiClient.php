<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;

class CreateApiClient extends Command
{
    protected $signature = 'kv:client:create
                            {name : A label for whoever owns this key}
                            {--limit= : Requests per minute, defaults to the configured client limit}';

    protected $description = 'Mint an API key. The key is printed once and cannot be recovered.';

    public function handle(): int
    {
        $plainKey = ApiClient::generateKey();

        $client = ApiClient::create([
            'name' => $this->argument('name'),
            'key_hash' => ApiClient::hash($plainKey),
            'rate_limit_per_minute' => (int) ($this->option('limit') ?: config('kv.rate_limit.client')),
        ]);

        $this->newLine();
        $this->info("API key created for \"{$client->name}\".");
        $this->newLine();
        $this->line('  '.$plainKey);
        $this->newLine();
        $this->comment('Copy it now. Only its SHA-256 hash is stored, so this cannot be shown again.');
        $this->line("  Rate limit: {$client->rate_limit_per_minute} requests per minute");
        $this->line('  Send it as the X-API-Key header.');
        $this->newLine();

        return self::SUCCESS;
    }
}
