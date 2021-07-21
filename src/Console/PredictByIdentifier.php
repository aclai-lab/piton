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

class PredictByIdentifier extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'piton:predict_by_identifier';

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
    $idVal = $this->ask('Instance on which predict');
    /* Warn: this is just for trying stuff, because phpunit doesn't read config file and it's too big to replicate. */
    $db_fit = new DBFit();
    $modelVersion = ModelVersion::orderByDesc('id')->first(); # Get most recent version
    $predictions = $db_fit->predictByIdentifier($idVal,[],$modelVersion->id, false, false);
    //dd("It worked");
    dd($predictions);
  }
}