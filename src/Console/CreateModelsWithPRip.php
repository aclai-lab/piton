<?php

namespace aclai-lab\piton\Console;

use Illuminate\Console\Command;

use aclai-lab\piton\DBFit\DBFit;
use aclai-lab\piton\Facades\Piton;
use aclai-lab\piton\Learners\PRip;
use aclai-lab\piton\ModelVersion;

class CreateModelsWithPRip extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'piton:create_models_with_prip';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create rule based models using the PRip classifier.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(DBFit $db_fit, PRip $lr)
    {
        if (Piton::configNotPublished()) {
            return $this->warn('Please publish the piton config file by running '
                . '\'php artisan vendor:publish --tag=piton-config\'');
        }

        if (PRip::configNotPublished()) {
            return $this->warn('Please publish the prip config files by running '
                . '\'php artisan vendor:publish --tag=prip-config\'');
        }

        try {
            /** Set PRip options */
            $lr->setNumOptimizations(config('prip.numOptimizations'));
            $lr->setNumFolds(config('prip.numFolds'));
            $lr->setMinNo(config('prip.minNo'));

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

            /* Asks information about the author */
            $author = $this->ask('Please insert the author id: ');

            /**  Launch training */
            $start = microtime(TRUE);
            ModelVersion::create([
                'id_author' => $author,
                'test_results' => null,
                'test_date' => date("Y-m-d H:i:s", date_timestamp_get(date_create())),
            ]);
            $db_fit->updateModel(1);
            $end = microtime(TRUE);

            echo "updateModel took " . ($end - $start) . " seconds to complete." . PHP_EOL;
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }
}