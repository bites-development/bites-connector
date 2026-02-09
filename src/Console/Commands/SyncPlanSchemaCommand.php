<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Console\Commands;

use Illuminate\Console\Command;
use Modules\BitesMiddleware\Services\PlanSchemaSyncService;

class SyncPlanSchemaCommand extends Command
{
    protected $signature = 'bites:sync-plan-schema
        {--dry-run : Build payload without sending it}
        {--endpoint= : Override middleware schema-sync endpoint}
        {--app-prefix= : Override app prefix sent to middleware}
        {--table= : Override detected plan table}
        {--max-snippets= : Limit snippet count}
        {--max-snippet-chars= : Max chars per snippet block}';

    protected $description = 'Export plan input schema from this app and sync it to middleware';

    public function handle(PlanSchemaSyncService $service): int
    {
        $options = [
            'dry_run' => (bool) $this->option('dry-run'),
            'endpoint' => (string) ($this->option('endpoint') ?? ''),
            'app_prefix' => (string) ($this->option('app-prefix') ?? ''),
            'table_name' => (string) ($this->option('table') ?? ''),
            'max_snippets' => $this->option('max-snippets') !== null ? (int) $this->option('max-snippets') : null,
            'max_snippet_chars' => $this->option('max-snippet-chars') !== null ? (int) $this->option('max-snippet-chars') : null,
        ];
        $options = array_filter($options, static fn ($value) => $value !== null);

        $result = $service->sync($options);

        if (!empty($result['dry_run'])) {
            $payload = (array) ($result['payload'] ?? []);
            $this->info('Dry run schema payload built.');
            $this->line('app_prefix: ' . (string) ($payload['app_prefix'] ?? ''));
            $this->line('table_name: ' . (string) ($payload['table_name'] ?? ''));
            $this->line('snippets: ' . count((array) ($payload['snippets'] ?? [])));
            return self::SUCCESS;
        }

        if (!($result['success'] ?? false)) {
            $this->error('Schema sync failed.');
            $this->line('status: ' . (string) ($result['status'] ?? 'n/a'));
            $this->line('endpoint: ' . (string) ($result['endpoint'] ?? ''));
            $this->line('response: ' . json_encode($result['response'] ?? []));
            return self::FAILURE;
        }

        $this->info('Schema sync completed.');
        $this->line('status: ' . (string) ($result['status'] ?? 'n/a'));
        $this->line('endpoint: ' . (string) ($result['endpoint'] ?? ''));

        $responseData = (array) ($result['response']['data'] ?? []);
        if (!empty($responseData)) {
            $this->line('app: ' . (string) ($responseData['app_name'] ?? ''));
            $this->line('table: ' . (string) ($responseData['table_name'] ?? ''));
            $this->line('snippets_received: ' . (string) ($responseData['snippets_received'] ?? 0));
            $this->line('select_options_count: ' . (string) ($responseData['select_options_count'] ?? 0));
            $this->line('field_groups_count: ' . (string) ($responseData['field_groups_count'] ?? 0));
        }

        return self::SUCCESS;
    }
}

