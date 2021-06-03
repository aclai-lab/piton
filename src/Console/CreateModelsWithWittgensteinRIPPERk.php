<?php

namespace aclai\piton\Console;

use Illuminate\Console\Command;

use aclai\piton\DBFit\DBFit;
use aclai\piton\Facades\Piton;
use aclai\piton\Learners\WittgensteinLearner;

class CreateModelsWithWittgensteinRIPPERk extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'piton:create_models_with_wittgenstein_ripperk';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create rule based models using the RIPPERk algorithm of the python wittgenstein package.';

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

        if (WittgensteinLearner::RIPPERkconfigNotPublished()) {
            return $this->warn('Please publish the wittgenstein_irep config files by running '
                . '\'php artisan vendor:publish --tag=wittgenstein_ripperk-config\'');
        }

        try {
            /**
             * Create an instance of the Learner, setting CART as the classifier algorithm to be used;
             * couldn't inject here because a classifier is required by the constructor of the Learner.
             */
            $lr = new WittgensteinLearner('RIPPERk');

            /** Set Wittgenstein IREP options */
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