<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

class IntelliShopDatasetSeeder extends Seeder
{
    private const ARCHIVE = __DIR__.'/../datasets/intellishop_dataset_csv.zip';

    /** Tables are cleared before import; rows load with FK checks disabled for circular references. */
    private const TABLES = [
        'roles', 'branches', 'users', 'units', 'categories', 'brands', 'products', 'suppliers',
        'product_suppliers', 'customers', 'purchase_orders', 'purchase_order_items', 'purchases',
        'inventory_batches', 'inventory', 'inventory_transactions', 'stock_transfers', 'sales',
        'sale_items', 'sale_item_batches', 'discounts', 'product_promotions', 'system_settings',
        'model_runs', 'demand_forecasts', 'stock_predictions', 'sales_anomalies',
        'market_basket_rules', 'ai_recommendations', 'cycle_counts', 'markdown_recommendations',
        'audit_logs',
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('The bundled dataset can only be imported in the local environment.');
        }

        if (! is_file(self::ARCHIVE)) {
            throw new RuntimeException('The local dataset archive is missing: '.self::ARCHIVE);
        }

        $zip = new ZipArchive();
        if ($zip->open(self::ARCHIVE) !== true) {
            throw new RuntimeException('Unable to open the local dataset archive.');
        }

        foreach (self::TABLES as $table) {
            if ($zip->locateName($table.'.csv') === false) {
                $zip->close();
                throw new RuntimeException("Dataset archive is missing {$table}.csv.");
            }
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        } else {
            Schema::disableForeignKeyConstraints();
        }

        try {
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    throw new RuntimeException("Required table {$table} is missing; run migrations first.");
                }
            }

            foreach (self::TABLES as $table) {
                DB::table($table)->delete();
            }
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->delete();
            }

            foreach (self::TABLES as $table) {
                $this->importCsv($zip, $table);
            }
        } finally {
            $zip->close();
            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            } else {
                Schema::enableForeignKeyConstraints();
            }
        }
    }

    private function importCsv(ZipArchive $zip, string $table): void
    {
        $index = $zip->locateName($table.'.csv');
        if ($index === false) {
            throw new RuntimeException("Dataset archive is missing {$table}.csv.");
        }

        $stream = $zip->getStream($table.'.csv');
        if ($stream === false) {
            throw new RuntimeException("Unable to read {$table}.csv from the dataset archive.");
        }

        $header = fgetcsv($stream, 0, ',', '"', '');
        if (! is_array($header)) {
            fclose($stream);
            throw new RuntimeException("{$table}.csv has no header row.");
        }

        // The dataset contains this legacy users column, removed by this app's RBAC migration.
        $columns = array_values(array_filter($header, fn (string $column) =>
            $column !== 'email_verified_at' && Schema::hasColumn($table, $column)
        ));
        if (count($columns) !== count($header) - (in_array('email_verified_at', $header, true) ? 1 : 0)) {
            fclose($stream);
            throw new RuntimeException("Unexpected columns in {$table}.csv; check the dataset schema.");
        }

        $batch = [];
        $imported = 0;
        $batchNumbers = [];
        $batchSize = DB::getDriverName() === 'sqlite' ? max(1, intdiv(900, count($columns))) : 500;
        while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if ($values === [null]) {
                continue;
            }
            if (count($values) !== count($header)) {
                fclose($stream);
                throw new RuntimeException("Malformed CSV row {$imported} in {$table}.csv.");
            }

            $row = [];
            foreach ($header as $offset => $column) {
                if ($column === 'email_verified_at' || ! in_array($column, $columns, true)) {
                    continue;
                }
                $row[$column] = $values[$offset] === '' ? null : $values[$offset];
            }

            // The source can contain multiple receipts for a product, branch, and date.
            // Keep every receipt as its own inventory batch while honoring the app's unique key.
            if ($table === 'inventory_batches') {
                $key = $row['product_id'].'|'.$row['branch_id'].'|'.$row['batch_no'];
                if (isset($batchNumbers[$key])) {
                    $originalBatchNo = $row['batch_no'];
                    $suffix = '-'.$row['id'];
                    $row['batch_no'] = substr($originalBatchNo, 0, 50 - strlen($suffix)).$suffix;
                    $key = $row['product_id'].'|'.$row['branch_id'].'|'.$row['batch_no'];

                    $collision = 1;
                    while (isset($batchNumbers[$key])) {
                        $suffix = '-'.$row['id'].'-'.$collision++;
                        $row['batch_no'] = substr($originalBatchNo, 0, 50 - strlen($suffix)).$suffix;
                        $key = $row['product_id'].'|'.$row['branch_id'].'|'.$row['batch_no'];
                    }
                }
                $batchNumbers[$key] = true;
            }

            $batch[] = $row;

            if (count($batch) >= $batchSize) {
                DB::table($table)->insert($batch);
                $imported += count($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            DB::table($table)->insert($batch);
            $imported += count($batch);
        }
        fclose($stream);

        $this->command?->info("Imported {$table}: {$imported} rows");
    }
}
