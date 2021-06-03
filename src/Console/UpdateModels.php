<?php

namespace aclai-lab\piton\Console;

use Illuminate\Console\Command;

use aclai-lab\piton\DBFit\DBFit;
use aclai-lab\piton\Facades\Piton;
use aclai-lab\piton\Learners\PRip;
use aclai-lab\piton\Learners\SklearnLearner;
use aclai-lab\piton\Learners\WittgensteinLearner;
use aclai-lab\piton\ModelVersion;

class UpdateModels extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'piton:update_models {author} {learner} {algorithm?}';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Create rule based models using a specified learner and, eventually, algorithm.' . "\n"
      . 'Arguments: \'author id\', \'learner\', [\'algorithm\']' . "\n"
      . 'Available learners are \'PRip\', \'WittgensteinLearner\' and \'SKLearnLearner\'.' . "\n"
      . 'Available algorithm for the \'SKLearnLearner\' is \'CART\'.' . "\n"
      . 'Available algorithms for the \'WittgensteinLearner\' are \'IREP\' and \'RIPPERk\'.';

  /**
   * Execute the console command.
   *
   * @return void
   */
  public function handle(DBFit $db_fit)
  {
    /* Checks if the piton config file has been published. */
    if (Piton::configNotPublished()) {
      return $this->warn('Please publish the piton config file by running ' . "\n"
        . ' \'php artisan vendor:publish --tag=piton-config\'');
    }

    /* Asks information about the author. */
    $author = $this->argument('author');
    if ($author === 'null') $author = null;

    /* Asks information about the learner. */
    $learner = $this->argument('learner');

    if ($learner === 'PRip') {
      /* Checks if the PRip config file has been published. */
      if (PRip::configNotPublished()) {
        return $this->warn('Please publish the prip config files by running ' . "\n"
          . '\'php artisan vendor:publish --tag=prip-config\'');
      }

      /* Creates an instance of the learner of type PRip. */
      $lr = new PRip();

      /* Set PRip options. */
      $lr->setNumOptimizations(config('prip.numOptimizations'));
      $lr->setNumFolds(config('prip.numFolds'));
      $lr->setMinNo(config('prip.minNo'));
    }
    else if ($learner === 'SKLearnLearner') {
      /* Asks information about the classification algorithm. */
      $algorithm = $this->argument('algorithm');

      if ($algorithm === 'CART') {
        /* Checks if the SKLearnLearner CART config file has been published. */
        if (SklearnLearner::CARTconfigNotPublished()) {
          return $this->warn('Please publish the sklearn_cart config files by running ' . "\n"
            . '\'php artisan vendor:publish --tag=sklearn_cart-config\'');
        }

        /* Create an instance of the Learner, setting CART as the classifier algorithm to be used. */
        $lr = new SklearnLearner('CART');

        /* Set SKLearn CART options. */
        $lr->setCriterion(config('sklearn_cart.criterion'));
        $lr->setSplitter(config('sklearn_cart.splitter'));
        $lr->setMaxDepth(config('sklearn_cart.maxDepth'));
        $lr->setMinSamplesSplit(config('sklearn_cart.minSamplesSplit'));
        $lr->setMinSamplesLeaf(config('sklearn_cart.minSamplesLeaf'));
        $lr->setMinWeightFractionLeaf(config('sklearn_cart.MinWeightFractionLeaf'));
        $lr->setMaxFeatures(config('sklearn_cart.maxFeatures'));
        $lr->setRandomState(config('sklearn_cart.randomState'));
        $lr->setMaxLeafNodes(config('sklearn_cart.maxLeafNodes'));
        $lr->setMinImpurityDecrease(config('sklearn_cart.minImpurityDecrease'));
        $lr->setClassWeight(config('sklearn_cart.classWeight'));
        $lr->setCcpAlpha(config('sklearn_cart.ccpAlpha'));
        $lr->setThreshold((config('sklearn_cart.threshold')));
      }
      else {
        $this->warn('Sorry, the chosen algorithm is not valid. Please choose among one of our algorithms.' . "\n"
          . 'Available algorithm for the \'SKLearnLearner\' is \'CART\'');
      }
    }
    else if ($learner === 'WittgensteinLearner') {
      /* Asks information about the classification algorithm. */
      $algorithm = $this->argument('algorithm');

      if ($algorithm === "IREP") {
        /* Checks if the WittgensteinLearner IREP config file has been published. */
        if (WittgensteinLearner::IREPconfigNotPublished()) {
          return $this->warn('Please publish the wittgenstein_irep config files by running ' . "\n"
            . '\'php artisan vendor:publish --tag=wittgenstein_irep-config\'');
        }

        /* Create an instance of the Learner, setting IREP as the classifier algorithm to be used. */
        $lr = new WittgensteinLearner('IREP');

        /* Set Wittgenstein IREP options. */
        $lr->setPruneSize(config('wittgenstein_irep.pruneSize'));
        $lr->setNDiscretizeBins(config('wittgenstein_irep.nDiscretizeBins'));
        $lr->setMaxRules(config('wittgenstein_irep.maxRules'));
        $lr->setMaxRuleConds(config('wittgenstein_irep.maxRuleConds'));
        $lr->setRandomState(config('wittgenstein_irep.randomState'));
        $lr->setVerbosity(config('wittgenstein_irep.verbosity'));
        $lr->setThreshold((config('wittgenstein_irep.threshold')));
      }
      else if ($algorithm === 'RIPPERk') {
        /* Checks if the WittgensteinLearner RIPPERk config file has been published. */
        if (WittgensteinLearner::RIPPERkconfigNotPublished()) {
          return $this->warn('Please publish the wittgenstein_irep config files by running '
            . '\'php artisan vendor:publish --tag=wittgenstein_ripperk-config\'');
        }

        /* Create an instance of the Learner, setting CART as the classifier algorithm to be used. */
        $lr = new WittgensteinLearner('RIPPERk');

        /* Set Wittgenstein IREP options. */
        $lr->setK(config('wittgenstein_ripperk.k'));
        $lr->setDlAllowance(config('wittgenstein_ripperk.dlAllowance'));
        $lr->setPruneSize(config('wittgenstein_ripperk.pruneSize'));
        $lr->setNDiscretizeBins(config('wittgenstein_ripperk.nDiscretizeBins'));
        $lr->setMaxRules(config('wittgenstein_ripperk.maxRules'));
        $lr->setMaxRuleConds(config('wittgenstein_ripperk.maxRuleConds'));
        $lr->setMaxTotalConds(config('wittgenstein_ripperk.maxTotalConds'));
        $lr->setRandomState(config('wittgenstein_ripperk.randomState'));
        $lr->setVerbosity(config('wittgenstein_ripperk.verbosity'));
        $lr->setThreshold((config('wittgenstein_ripperk.threshold')));
      }
      else {
        $this->warn('Sorry, the chosen algorithm is not valid. Please choose among one of our algorithms.' . "\n"
          . 'Available algorithms for the \'WittgensteinLearner\' are \'IREP\' and \'RIPPERk\'');
      }
    }
    else {
      $this->warn('Sorry, the chosen learner is not valid. Please choose among one of our learners.' . "\n"
        . 'Available learners are \'PRip\', \'WittgensteinLearner\' and \'SKLearnLearner\'');
    }

    /* If the learner is not set, something went wrong, a warn has been printed and execution should finish. */
    if (isset($lr)) {
      /* Set DBFit options */
      $db_fit->setTrainingMode(config('piton.trainingMode'));
      $db_fit->setCutOffValue(config('piton.cutOffValue'));
      $db_fit->setLearner($lr);
      foreach (config('piton.defaultOptions') as $defaultOption) {
        $db_fit->setDefaultOption($defaultOption[0], $defaultOption[1]);
      }
      $db_fit->setInputTables(config('piton.inputTables'));
      $db_fit->setWhereClauses(config('piton.whereClauses'));
      $db_fit->setOrderByClauses(config('piton.orderByClauses'));
      $db_fit->setIdentifierColumnName(config('piton.identifierColumnName'));
      $db_fit->setInputColumns(config('piton.inputColumns'));
      $db_fit->setOutputColumns(config('piton.outputColumns'));
      $db_fit->setGlobalNodeOrder(config('piton.globalNodeOrder'));

      /* Instantiate modelVersion; most of the values will be updated by DBFit::updateModel. */
      $modelVersion = ModelVersion::create([
        'id_author' => $author,
        'learner' => isset($algorithm) ? $learner . "\t" . $algorithm : $learner,
        'training_mode' => '[' . implode(',', $db_fit->getTrainingMode()) . ']',
        'cut_off_value' => $db_fit->getCutOffValue(),
        'experiment_id' => null,
        'date' => date("Y-m-d H:i:s", date_timestamp_get(date_create())),
        'hierarchy' => null,
        'test_results' => null,
        'test_date' => date("Y-m-d H:i:s", date_timestamp_get(date_create())),
      ]);

      /*  Launch training */
      $start = microtime(TRUE);
      $db_fit->updateModel($modelVersion->id);
      $end = microtime(TRUE);
      echo "updateModel took " . ($end - $start) . " seconds to complete." . PHP_EOL;
    }
  }
}