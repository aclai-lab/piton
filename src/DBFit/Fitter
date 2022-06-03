<?php

namespace aclai\piton\DBFit;

/**
 * This class groups the old DBFit Logic which concerns the teaching (fitting)
 * of the models for the problems in the hierarchy using a specified learner.
 * 
 * TODO explain better the purpose of this class and how it works.
 */

class Fitter
{
	/**
   * Optimizer in use for training the models.
   * This can be set via ->setLearningMethod(string) (only "PRip" available atm),
   * or ->setLearner($learner)
   */
  private $learner;

  /**
   * Array storing all the hierarchy of discriminative models trained (or loaded).
   */
  private $models;

  /**
   * Array storing the prediction results for each hierarchy node.
   * TODO merge with $models.
   */
  private $predictionResults;

  /**
   * Training mode.
   * Available values:
   * - "FullTraining" (trains and test onto the same 100% of data)
   * - [train_w, test_w] (train/test split according to these two weights)
   */
  private $trainingMode;

  /**
   * The cut off value is the value between 0 and 1 representing the minimum percentage
   * of any of the two classes (in the binary classification case) that is needed
   * for telling whether a dataset is too unbalanced to be good, or not.
   */
  private $cutOffValue;

  /**
   * The ID of the current run.
   */
  private $experimentID;

  function __construct()
  {
    $this->models = [
      "name" => "root",
      "subtree" => []
    ];
    $this->learner = NULL;
    $this->trainingMode = NULL;
    $this->cutOffValue = NULL;
    $this->predictionResults = [];
  }

  /* Train and test all the model tree on the available data, and save to database */
    function updateModel(int $idModelVersion, array $recursionPath = [])
    {
        echo "DBFit->updateModel(" . Utils::toString($recursionPath) . ")" . PHP_EOL;

        $recursionLevel = count($recursionPath);

        if ($recursionLevel === 0) {
            $this->experimentID = date('Y-m-d H:i:s');
        }

        if (!($this->learner instanceof Learner)) {
            Utils::die_error("Learner is not initialized. Please, use ->setLearner() or ->setLearningMethod()");
        }

        /* Read the dataframes specific to this recursion path */
        $rawDataframe = $this->readData(NULL, $recursionPath, $numDataframes);

        // if($recursionLevel === 0) {
        //   $this->models["rawDataframe"] = $rawDataframe;
        // }

        /* Check: if no data available stop recursion */
        if ($rawDataframe === NULL || !$numDataframes) {
            echo "Train-time recursion stops here due to lack of data (recursionPath = " . Utils::toString($recursionPath)
                . "). " . PHP_EOL;
            if ($recursionLevel == 0) {
                Utils::die_error("Training failed! Couldn't find data.");
            }
            return;
        }

        /* Obtain output attributes */
        // $outputAttributes = $this->getColumnAttributes($this->outputColumns[$recursionLevel], $recursionPath);

        /* Prepare child recursion paths, in order to train the models in a breadth-first fashion */
        $childPaths = [];

        /* For each attribute, train subtree */
        foreach ($this->generateDataframes($rawDataframe, $idModelVersion) as $i_prob => $dataframe) {
            echo "Problem $i_prob/" . $numDataframes . PHP_EOL;
            // $outputAttribute = $outputAttributes[$i_prob];
            $outputAttribute = $dataframe->getClassAttribute();

            /* If no data available, skip training */
            if (!$dataframe->numInstances()) {
                echo "Skipping node due to lack of data." . PHP_EOL;
                if ($recursionLevel == 0) {
                    Utils::die_error("Training failed! No data instance found.");
                }
                continue;
            }

            /* If data is too unbalanced, skip training */
            if ($this->getCutOffValue() !== NULL &&
                !$dataframe->checkCutOff($this->getCutOffValue())) {
                echo "Skipping node due to unbalanced dataset found"
                    // . "("
                    // . $dataframe->checkCutOff($this->getCutOffValue())
                    // . " > "
                    // . $this->getCutOffValue()
                    // . ")";
                    . "." . PHP_EOL;
                continue;
            }

            //$dataframe->saveToCSV("datasets/data-" . $this->getModelName($recursionPath, $i_prob) . ".csv");
            //$dataframe->save_ARFF("datasets/arff/data-" . $this->getModelName($recursionPath, $i_prob) . ".arff");
            //$dataframe->saveToDB($this->getTableNickname("data-" . $this->getModelName($recursionPath, $i_prob)));

            /* Obtain and train, test set */
            list($trainData, $testData) = $this->getDataSplit($dataframe);

            // echo "TRAIN" . PHP_EOL . $trainData->toString(DEBUGMODE <= 0) . PHP_EOL;
            // echo "TEST" . PHP_EOL . $testData->toString(DEBUGMODE <= 0) . PHP_EOL;

            echo "TRAIN: " . $trainData->numInstances() . " instances" . PHP_EOL;
            echo "TEST: " . $testData->numInstances() . " instances" . PHP_EOL;

            // $trainData->save_CSV("datasets/data-" . $this->getModelName($recursionPath, $i_prob) . "-TRAIN.csv");
            // $testData->save_CSV("datasets/data-" . $this->getModelName($recursionPath, $i_prob) . "-TEST.csv");

            /*if ($i_prob == 0) {
                $trainData->save_CSV("datasets/data-" . $this->getModelName($recursionPath, $i_prob) . "-TRAIN.csv"); // , false);
                $testData->save_CSV("datasets/data-" . $this->getModelName($recursionPath, $i_prob) . "-TEST.csv"); // , false);
                $trainData->save_ARFF("datasets/arff/data-" . $this->getModelName($recursionPath, $i_prob) . "-TRAIN.arff");
                $testData->save_ARFF("datasets/arff/data-" . $this->getModelName($recursionPath, $i_prob) . "-TEST.arff");
                $trainData->saveToDB($this->outputDB, $this->getTableNickname("data-" . $this->getModelName($recursionPath, $i_prob) . "-TRAIN")); // trainData
                $testData->saveToDB($this->outputDB, $this->getTableNickname("data-" . $this->getModelName($recursionPath, $i_prob) . "-TEST"));  // testData
            }*/

            /* Train */
            $model_name = $this->getModelName($recursionPath, $i_prob);
            //$model_id = $this->getModelName($recursionPath, $i_prob, true);
            $model = $this->learner->initModel();

            $model->fit($trainData, $this->learner);
            // "python", "sklearn/CART"
            // "python", "wittengstein/RIPPER"

            echo "Trained model '$model_name'." . PHP_EOL;

            // die_error(strval(DEBUGMODE) . strval(DEBUGMODE_DATA) . strval(DEBUGMODE & DEBUGMODE_DATA));

            echo $model . PHP_EOL;

            /* Save model */

            //$model->save(Utils::join_paths(MODELS_FOLDER, $model_name));
            // $model->save(join_paths(MODELS_FOLDER, date("Y-m-d_H:i:s") . $model_name));

            $fatherNode = null;
            if (!empty($recursionPath)) {
                $fatherNode = $recursionPath[array_key_last($recursionPath)][2];
            }
            $model->saveToDB($idModelVersion, $recursionLevel, $fatherNode,
                $this->learner->getName(), $testData, $trainData);
            //$model->dumpToDB($this->outputDB, $model_id);
            // . "_" . join("", array_map([$this, "getColumnName"], ...).);

            $this->setHierarchyModel($recursionPath, $i_prob, clone $model);
            $prob_name = $this->getHierarchyName($recursionPath, $i_prob);
            $subRecursionPath = array_merge($recursionPath, [[$i_prob, $prob_name]]);

            /* Test */
            /** TODO Not working with sklearn :( */
            /* $testResults = $model->test($testData);

            if ($this->identifierColumnName !== NULL) {
                $testResTuplesGtprrt = Utils::zip_assoc($testResults["ground_truths"], $testResults["predictions"], $testResults["rule_types"]);
                // var_dump($testResults["rule_types"]);
                // var_dump($testResTuplesGtprrt);
                foreach ($testResTuplesGtprrt as $instance_id => $gtprrt) {
                    $res = [$testData->reprClassVal($gtprrt[0]), $testData->reprClassVal($gtprrt[1]), $gtprrt[2]];

                    $classRecursionPath = array_column($subRecursionPath, 1);
                    $classRecursionPath = array_merge($classRecursionPath, ["res"]);
                    Utils::arr_set_value($this->predictionResults, array_merge($classRecursionPath, [$instance_id]), $res, true);
                    // echo "changed" . PHP_EOL . toString($this->predictionResults) . PHP_EOL . toString(array_merge($classRecursionPath, [$instance_id])) . PHP_EOL;
                }
            }
            */

            /* Prepare recursion */
            if ($recursionLevel + 1 == $this->getHierarchyDepth()) {
                /* Recursion base case */
                echo "Train-time recursion stops here (recursionPath = " . Utils::toString($recursionPath)
                    . ", problem $i_prob/" . $numDataframes . ") : '$model_name'. " . PHP_EOL;
            } else {
                /* Recursive step: for each output class value, recurse and train the subtree */
                echo "Branching at depth $recursionLevel on attribute \""
                    . $outputAttribute->getName() . "\" ($i_prob/"
                    . $numDataframes . ")) "
                    . " with domain " . Utils::toString($outputAttribute->getDomain())
                    . ". " . PHP_EOL;

                // echo get_var_dump($outputAttribute->getDomain()) . PHP_EOL;

                foreach ($outputAttribute->getDomain() as $className) {
                    // TODO right now I'm not recurring when a "NO_" outcome happens. This is not supersafe, there must be a nice generalization.
                    if (!Utils::startsWith($className, "NO_")) {
                        echo "Recursion on class '$className' for attribute \""
                            . $outputAttribute->getName() . "\". " . PHP_EOL;
                        // TODO generalize this. For each level we have a problem A, subproblems B,C,D. E dunque A/B, A/C, etc. For each subproblem we have a certain number of classes, like A/B/B, A/B/NO_B for the binary case.
                        $childPaths[] = array_merge($recursionPath, [[$i_prob, $className, $outputAttribute->getName()]]);
                    }
                }
            }
        } // END foreach

        /* Recurse */
        foreach ($childPaths as $childPath) {
            /** childPath = [i-prob, className, fatherNodeClassName] */
            $this->updateModel($idModelVersion, $childPath);
        }
    }

    /**
     * TODO
     * Load an existing set of models.
     * Defaulted to the models trained the most recently
     */
    // function loadModel(?string $path = NULL) {
    //   echo "DBFit->loadModel($path)" . PHP_EOL;

    //   die_error("TODO loadModel, load the full hierarchy");
    //   /* Default path to that of the latest model */
    //   if ($path === NULL) {
    //     $models = filesin(MODELS_FOLDER);
    //     if (count($models) == 0) {
    //       die_error("loadModel: No model to load in folder: \"". MODELS_FOLDER . "\"");
    //     }
    //     sort($models, true);
    //     $path = $models[0];
    //     echo "$path";
    //   }

    //   $this->models = [DiscriminativeModel::loadFromFile($path)];
    // }

    function getHierarchyDepth(): int
    {
        return count($this->outputColumns);
    }

    function setLearner(Learner $learner): self
    {
        $this->learner = $learner;

        return $this;
    }

    function getLearner(): string
    {
        return $this->learner;
    }

    // Maybe it isn't used no more? TODO: check
    /*function setLearningMethod(string $learningMethod) : self
    {
        if (!($learningMethod == "PRip"))
            Utils::die_error("Only \"PRip\" is available as a learning method");

        $learner = new PRip();
        // TODO $learner->setNumOptimizations(20);
        $this->setLearner($learner);

        return $this;
    }*/

    function getTrainingMode()
    {
        if ($this->trainingMode === NULL) {
            $this->trainingMode = $this->defaultOptions["trainingMode"];
            echo "Training mode defaulted to " . Utils::toString($this->trainingMode);
        }
        return $this->trainingMode;
    }

    function setTrainingMode($trainingMode): self
    {
        $this->trainingMode = $trainingMode;
        return $this;
    }

    function getCutOffValue(): ?float
    {
        return $this->cutOffValue;
    }

    function setCutOffValue(float $cutOffValue): self
    {
        $this->cutOffValue = $cutOffValue;
        return $this;
    }

    function getExperimentID(): ?string
    {
        return $this->experimentID;
    }

    function setExperimentID(string $experimentID): self
    {
        $this->experimentID = $experimentID;
        return $this;
    }

    function setTrainingSplit(array $trainingMode): self
    {
        $this->setTrainingMode($trainingMode);
        return $this;
    }

    function setDefaultOption($opt_name, $opt): self
    {
        $this->defaultOptions[$opt_name] = $opt;
        return $this;
    }

    function getPredictionResults(): array
    {
        return $this->predictionResults;
    }

    // function setPredictionResults(array $predictionResults) : self
    // {
    //   $this->predictionResults = $predictionResults;
    //   return $this;
    // }


    // function &getRawDataSplit(array $rawDataframe) : array {
    //   list($final_attributes, $final_data, $outputAttributes) = $rawDataframe;

    //   list($trainData, $testData) = $this->getDataSplit(...Instances($final_data));

    //   $train_final_data = toarray($trainData);
    //   $test_final_data = toarray($testData);

    //   return [
    //           [$final_attributes, $train_final_data, $outputAttributes],
    //           [$final_attributes, $test_final_data, $outputAttributes]
    //         ];
    // }
    function &getDataSplit(Instances &$data): array
    {
        $trainingMode = $this->getTrainingMode();
        $rt = NULL;
        /* training modes */
        switch (true) {
            /* Full training: use data for both training and testing */
            case $trainingMode == "FullTraining":
                $rt = [$data, $data];
                break;

            /* Train+test split */
            case is_array($trainingMode):
                $trRat = $trainingMode[0] / ($trainingMode[0] + $trainingMode[1]);
                // $rt = Instances::partition($data, $trRat);
                $numFolds = 1 / (1 - $trRat);
                // echo $numFolds;
                $rt = RuleStats::stratifiedBinPartition($data, $numFolds);

                break;

            default:
                Utils::die_error("Unknown training mode: " . Utils::toString($trainingMode));
                break;
        }

        // TODO RANDOMIZE
        // echo "Randomizing!" . PHP_EOL;
        // srand(make_seed());
        // $rt[0]->randomize();

        return $rt;
    }

    private function setHierarchyModel(array $recursionPath, int $i_prob, DiscriminativeModel $model)
    {
        $name = "";
        $modelKeyPath = [];
        foreach ($recursionPath as $recursionLevel => $node) {
            $modelKeyPath[] = "subtree";
            $modelKeyPath[] = $node[0];
            // $className = $node[1];
            // $this->getColumnAttributes($this->outputColumns[$recursionLevel], array_slice($recursionPath, 0,
            //                            $recursionLevel))[$node[0]]->getName()
            // $node[1]
        }
        $modelKeyPath[] = "subtree";
        $modelKeyPath[] = $i_prob;

        $recursionLevel = count($recursionPath);
        $name .= $this->getColumnAttributes($this->outputColumns[$recursionLevel],
            $recursionPath)[$i_prob]->getName();

        $subRecursionPath = array_merge($recursionPath, [[$i_prob, $name]]);

        $node = [
            "name" => $name,
            "model" => $model,
            "recursionPath" => $subRecursionPath,
            "subtree" => []
        ];
        Utils::arr_set_value($this->models, $modelKeyPath, $node);

        echo "setHierarchyModel(" . Utils::toString($recursionPath) . ")";
        // echo get_var_dump($this->models);
    }

    private function getHierarchyModel(array $recursionPath, int $i_prob): ?DiscriminativeModel
    {
        $modelKeyPath = [];
        foreach ($recursionPath as $recursionLevel => $node) {
            $modelKeyPath[] = "subtree";
            $modelKeyPath[] = $node[0];
        }
        $modelKeyPath[] = "subtree";
        $modelKeyPath[] = $i_prob;
        $modelKeyPath[] = "model";

        return Utils::arr_get_value($this->models, $modelKeyPath, true);
    }

    //private function getHierarchyName(array $recursionPath, int $i_prob) : string {
    private function getHierarchyName(array $recursionPath, int $i_prob)
    {
        $modelKeyPath = [];
        foreach ($recursionPath as $recursionLevel => $node) {
            $modelKeyPath[] = "subtree";
            $modelKeyPath[] = $node[0];
        }
        $modelKeyPath[] = "subtree";
        $modelKeyPath[] = $i_prob;
        $modelKeyPath[] = "name";

        return Utils::arr_get_value($this->models, $modelKeyPath, true);
    }

    function listHierarchyNodes($node = NULL, $maxdepth = -1)
    {
        // echo "listHierarchyNodes(" . toString($node) . ", $maxdepth)" . PHP_EOL;
        if ($maxdepth == 0) {
            return [];
        }
        $arr = [];
        if ($node === NULL) {
            $node = $this->models;
            $arr[$node["name"]] = [$node, []];
        }
        $childarr = [];

        foreach ($node["subtree"] as $i_prob => $childnode) {
            // $model    = $childnode["model"];
            $name = $childnode["name"];
            $subtree = $childnode["subtree"];
            $subRecursionPath = $childnode["recursionPath"];

            $arr[$childnode["name"]] = [$childnode, $subRecursionPath];

            $childarr = array_merge($childarr, $this->listHierarchyNodes($childnode, $maxdepth - 1));
        }

        return array_merge($arr, $childarr);
    }

}