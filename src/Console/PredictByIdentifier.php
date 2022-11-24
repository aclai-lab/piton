<?php

namespace aclai\piton\Console;

use Illuminate\Console\Command;

use aclai\piton\ClassModel;
use aclai\piton\DBFit\DBFit;
use aclai\piton\DiscriminativeModels\RuleBasedModel;
use aclai\piton\Facades\Piton;
use aclai\piton\Learners\Learner;
use aclai\piton\Learners\PRip;
use aclai\piton\Learners\SklearnLearner;
use aclai\piton\Learners\WittgensteinLearner;
use aclai\piton\ModelVersion;
use aclai\piton\Facades\Utils;

class PredictByIdentifier extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'piton:predict_by_identifier {idVal}';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Predict the output values for an instance.';

  /**
   * Execute the console command.
   *
   * @return void
   */
  public function handle(DBFit $db_fit)
  {
    /**
     * The name of the problem to be solved.
     *
     * @var int
     */
    $idVal = $this->argument('idVal');
    // $idVal = $this->ask('Instance on which predict');
    /* Warn: this is just for trying stuff, because phpunit doesn't read config file and it's too big to replicate. */
    $db_fit = new DBFit();
    // Utils::die_error(Utils::get_var_dump(ModelVersion::orderByDesc('id')->count()));
    $modelVersion = ModelVersion::orderByDesc('id')->first(); # Get most recent version
    // dd(ModelVersion::orderByDesc('id'));
    // dd($modelVersion);
    $predictions = $db_fit->predictByIdentifier($idVal,[],$modelVersion->id, false, false);
    dd($predictions);
  }
}
