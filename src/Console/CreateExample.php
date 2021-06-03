<?php

namespace aclai-lab\piton\Console;

use Illuminate\Console\Command;

use aclai-lab\piton\DBFit\DBFit;
use aclai-lab\piton\ModelVersion;

class CreateExample extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'piton:create_example';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Creates and example to try the package. It creates a table in your database' . "\n"
      . ' containing the well known iris dataset.';

  /**
   * Execute the console command.
   *
   * @return void
   */
  public function handle(DBFit $db_fit)
  {
    $command = escapeshellcmd("python3 " . __DIR__ . "/../Examples/createIrisDataset.py "
      . " " . config('database.connections.mysql.host')
      . " " . config('database.connections.mysql.username')
      . " " . config('database.connections.mysql.password')
      . " " . config('database.connections.mysql.database'));
    $output = shell_exec($command);
    #echo $output; #debug
  }
}