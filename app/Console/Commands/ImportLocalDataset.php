<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportLocalDataset extends Command
{
    protected $signature = 'intellishop:import-local-dataset {--refresh : Replace the existing local dataset}';

    protected $description = 'Import the bundled synthetic dataset once into a local database';

    public function handle(): int
    {
        if (! $this->laravel->environment('local')) {
            $this->error('Local dataset imports are disabled outside APP_ENV=local.');

            return self::FAILURE;
        }

        $dataset = 'intellishop-synthetic-v1';
        $legacyDataset = 'swapno-synthetic-v1';
        $imports = DB::table('local_dataset_imports');
        $alreadyImported = $imports->whereIn('dataset', [$dataset, $legacyDataset])->first();
        if ($alreadyImported && ! $this->option('refresh')) {
            if ($alreadyImported->dataset === $legacyDataset) {
                DB::table('local_dataset_imports')->updateOrInsert(['dataset' => $dataset], [
                    'imported_at' => $alreadyImported->imported_at,
                ]);
                DB::table('local_dataset_imports')->where('dataset', $legacyDataset)->delete();
            }
            $this->info('The bundled dataset is already imported.');

            return self::SUCCESS;
        }

        $result = $this->call('db:seed', [
            '--class' => \Database\Seeders\IntelliShopDatasetSeeder::class,
            '--force' => true,
        ]);
        if ($result !== self::SUCCESS) {
            return $result;
        }

        DB::table('local_dataset_imports')->updateOrInsert(['dataset' => $dataset], [
            'imported_at' => now(),
        ]);
        DB::table('local_dataset_imports')->where('dataset', $legacyDataset)->delete();

        return self::SUCCESS;
    }
}
