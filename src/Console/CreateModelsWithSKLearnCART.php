<?php

namespace aclai-lab\piton\Console;

use Illuminate\Console\Command;

use aclai-lab\piton\DBFit\DBFit;
use aclai-lab\piton\Facades\Piton;
use aclai-lab\piton\Learners\SklearnLearner;

class CreateModelsWithSKLearnCART extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'piton:create_models_with_sklearn_cart';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create rule based models using the CART algorithm of the python scikit-learn package.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(DBFit $db_fit)
    {
        if (Piton::configNotPublished()) {
            return $this->warn('Please publish the piton config file by running '
                . '\'php artisan vendor:publish --tag=piton-config\'');
        }

        if (SklearnLearner::CARTconfigNotPublished()) {
            return $this->warn('Please publish the sklearn_cart config files by running '
                . '\'php artisan vendor:publish --tag=sklearn_cart-config\'');
        }

        try {
            /**
             * Create an instance of the Learner, setting CART as the classifier algorithm to be used;
             * couldn't inject here because a classifier is required by the constructor of the Learner.
             */
            $lr = new SklearnLearner('CART');

            /** Set SKLearn CART options */
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

            /** Set DBFit options */
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

            /**  Launch training */
            $start = microtime(TRUE);
            $db_fit->updateModel(1);
            $end = microtime(TRUE);

            echo "updateModel took " . ($end - $start) . " seconds to complete." . PHP_EOL;
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }
}