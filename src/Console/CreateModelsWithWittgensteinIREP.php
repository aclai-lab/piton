<?php

namespace aclai\piton\Console;

use Illuminate\Console\Command;

use aclai\piton\DBFit\DBFit;
use aclai\piton\Facades\Piton;
use aclai\piton\Learners\WittgensteinLearner;

class CreateModelsWithWittgensteinIREP extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'piton:create_models_with_wittgenstein_irep';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create rule based models using the IREP algorithm of the python wittgenstein package.';


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

        if (WittgensteinLearner::IREPconfigNotPublished()) {
            return $this->warn('Please publish the wittgenstein_irep config files by running '
                . '\'php artisan vendor:publish --tag=wittgenstein_irep-config\'');
        }

        try {
            /**
             * Create an instance of the Learner, setting IREP as the classifier algorithm to be used;
             * couldn't inject here because a classifier is required by the constructor of the Learner.
             */
            $lr = new WittgensteinLearner('IREP');

            /** Set Wittgenstein IREP options */
            $lr->setPruneSize(config('wittgenstein_irep.pruneSize'));
            $lr->setNDiscretizeBins(config('wittgenstein_irep.nDiscretizeBins'));
            $lr->setMaxRules(config('wittgenstein_irep.maxRules'));
            $lr->setMaxRuleConds(config('wittgenstein_irep.maxRuleConds'));
            $lr->setRandomState(config('wittgenstein_irep.randomState'));
            $lr->setVerbosity(config('wittgenstein_irep.verbosity'));
            $lr->setThreshold((config('wittgenstein_irep.threshold')));

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