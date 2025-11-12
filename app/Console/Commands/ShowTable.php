<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShowTable extends Command
{
  protected $signature = 'db:show
                            {table : The table name to display}
                            {--limit=10 : Number of rows to display}
                            {--offset=0 : Offset for pagination}
                            {--order-by=id : Column to order by}
                            {--order=desc : Order direction (asc or desc)}';

  protected $description = 'Display data from a database table';

  public function handle()
  {
    $table = $this->argument('table');
    $limit = (int) $this->option('limit');
    $offset = (int) $this->option('offset');
    $orderBy = $this->option('order-by');
    $order = strtolower($this->option('order'));

    // Validate order direction
    if (!in_array($order, ['asc', 'desc'])) {
      $order = 'desc';
    }

    // Check if table exists
    if (!Schema::hasTable($table)) {
      $this->error("Table '{$table}' does not exist!");
      $this->newLine();
      $this->info("Available tables:");
      $tables = DB::select('SHOW TABLES');
      $dbName = 'Tables_in_' . env('DB_DATABASE');
      foreach ($tables as $t) {
        $this->line("  • " . $t->$dbName);
      }
      return 1;
    }

    // Get total count
    $total = DB::table($table)->count();

    // Get columns
    $columns = Schema::getColumnListing($table);

    // Check if order-by column exists
    if (!in_array($orderBy, $columns)) {
      $this->warn("Column '{$orderBy}' doesn't exist. Using first column.");
      $orderBy = $columns[0];
    }

    // Get data
    $data = DB::table($table)
      ->orderBy($orderBy, $order)
      ->offset($offset)
      ->limit($limit)
      ->get();

    // Display info
    $this->info("═══════════════════════════════════════");
    $this->info("TABLE: {$table}");
    $this->info("═══════════════════════════════════════");
    $this->info("Total rows: {$total}");
    $this->info("Showing: {$limit} rows (offset: {$offset})");
    $this->info("Order by: {$orderBy} {$order}");
    $this->newLine();

    if ($data->isEmpty()) {
      $this->warn("No data found in table '{$table}'");
      return 0;
    }

    // Convert to array for table display
    $rows = $data->map(function ($row) {
      $array = (array) $row;
      // Truncate long values
      return array_map(function ($value) {
        if (is_string($value) && strlen($value) > 50) {
          return substr($value, 0, 47) . '...';
        }
        return $value;
      }, $array);
    })->toArray();

    // Display table
    $this->table($columns, $rows);

    // Show pagination info
    if ($total > $limit) {
      $nextOffset = $offset + $limit;
      $this->newLine();
      $this->info("To see more rows:");
      if ($nextOffset < $total) {
        $this->line("  php artisan db:show {$table} --limit={$limit} --offset={$nextOffset}");
      } else {
        $this->warn("  (No more rows)");
      }
    }

    return 0;
  }
}
