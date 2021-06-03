<?php

namespace aclai-lab\piton\Learners;

use Illuminate\Support\Facades\Schema;
use aclai-lab\piton\DiscriminativeModels\RuleBasedModel;
use aclai-lab\piton\Facades\Utils;
use aclai-lab\piton\Instances\Instances;
use aclai-lab\piton\DiscriminativeModels\DiscriminativeModel;

/*
 * Interface for wittgenstein learner.
 *
 * This class offers compatibility with the python wittgenstein package, which
 * implements two iterative coverage-based ruleset algorithms: IREP and RIPPERk.
 *
 * The package must be installed on your operating system or python environment.
 * To install, use:
 *    $ pip3 install wittgenstein
 * Requirements:
 *    pandas
 *    numpy
 *    python version>=3.6
 */
class WittgensteinLearner extends Learner
{

    /**
     * The chosen iterative coverage-based ruleset algorithm used for classification.
     * It is possible to choose between IREP and RIPPERk.
     */
    private $classifier;

    /*** Options that are useful during the training stage. */

    /**
     * Number of RIPPERk optimization iterations.
     */
    private $k;

    /**
     * Terminate RIPPERk Ruleset grow phase early if a Ruleset description length is encountered
     * that is more than this amount above the lowest description length so far encountered.
     */
    private $dlAllowance;

    /**
     * Proportion of training set to be used for pruning.
     */
    private $pruneSize;

    /**
     * Fit apparent numeric attributes into a maximum of n_discretize_bins discrete bins,
     * inclusive on upper part of range. Pass None to disable auto-discretization.
     */
    private $nDiscretizeBins;

    /***
     * Limits for early-stopping. Intended for enhancing model interpretability and limiting training time
     * on noisy datasets. Not specifically intended for use as a hyperparameter, since pruning already occurs
     * during training, though it is certainly possible that tuning could improve model performance.
     */

    /**
     * Maximum number of rules
     */
    private $maxRules;

    /**
     * Maximum number of conds per rule.
     */
    private $maxRuleConds;

    /**
     * Maximum number of total conds in entire ruleset (RIPPERk only).
     */
    private $maxTotalConds;

    /**
     * Random seed for repeatable results.
     */
    private $randomState;

    /**
     * Output progress, model development, and/or computation.
     * Each level includes the information belonging to lower-value levels.
     *                1: Show results of each major phase
     *                2: Show Ruleset grow/optimization steps
     *                3: Show Ruleset grow/optimization calculations
     *                4: Show Rule grow/prune steps
     *                5: Show Rule grow/prune calculations
     */
    private $verbosity;

    /**
     * Threshold that indicates the maximum % of NaN values allowed for an attribute
     * to be considered valid, and so to be considered in the classification process.
     */
    private $threshold;

    /**
     * The constructor of the WittgensteinLearner.
     * A classifier algorithm and a database connection are required, but they can be changed later on.
     * It is also possible to set a random seed, which is set to NULL by default.
     * The other options are set to NULL and will be set by pyhton to their default values, and can be set later on.
     * Warning: k, dlAllowance and maxTotalConds are only used by the RIPPERk classifier and will not be
     * considered when using the IREP classifier.
     */
    public function __construct(string $classifier, ?int $randomState = NULL)
    {
        $this->setClassifier($classifier);
        $this->setRandomState($randomState);
        /** Options */
        $this->setK(NULL);
        $this->setDlAllowance(NULL);
        $this->setPruneSize(NULL);
        $this->setNDiscretizeBins(NULL);
        $this->setMaxRules(NULL);
        $this->setMaxRuleConds(NULL);
        $this->setMaxTotalConds(NULL);
        $this->setVerbosity(NULL);
        $this->setThreshold(NULL);
    }

    /**
     * Gives information about the current wittgenstein classifier used for the training.
     */
    public function getClassifier(): string
    {
        return $this->classifier;
    }

    /**
     * Sets the wittgenstein classifier to use for the training.
     * It is possible to choose between IREP and RIPPERk.
     */
    public function setClassifier(string $classifier): void
    {
        $this->classifier = $classifier;
    }

    /**
     * Gets information about the number of RIPPERk optimization iterations.
     */
    public function getK(): ?int
    {
        return $this->k;
    }

    /**
     * Sets the number of RIPPERk optimization iterations.
     */
    public function setK(?int $k): void
    {
        $this->k = $k;
    }

    /**
     * Gets information about the description length allowance.
     */
    public function getDlAllowance(): ?int
    {
        return $this->dlAllowance;
    }

    /**
     * Sets the description length allowance.
     */
    public function setDlAllowance(?int $dlAllowance): void
    {
        $this->dlAllowance = $dlAllowance;
    }

    /**
     * Gets information about the proportion of training set to be used for pruning.
     */
    public function getPruneSize(): ?float
    {
        return $this->pruneSize;
    }

    /**
     * Sets the proportion of training set to be used for pruning.
     */
    public function setPruneSize(?float $pruneSize): void
    {
        $this->pruneSize = $pruneSize;
    }

    /**
     * Gets information about the maximum of discrete bins for apparent numeric attributes fitting.
     */
    public function getNDiscretizeBins(): ?int
    {
        return $this->nDiscretizeBins;
    }

    /**
     * Sets the maximum of discrete bins for apparent numeric attributes fitting.
     */
    public function setNDiscretizeBins(?int $nDiscretizeBins): void
    {
        $this->nDiscretizeBins = $nDiscretizeBins;
    }

    /**
     * Gets information about the maximum number of rules.
     */
    public function getMaxRules(): ?int
    {
        return $this->maxRules;
    }

    /**
     * Sets the maximum number of rules.
     */
    public function setMaxRules(?int $maxRules): void
    {
        $this->maxRules = $maxRules;
    }

    /**
     * Gets information about the maximum number of conditions per rule.
     */
    public function getMaxRuleConds(): ?int
    {
        return $this->maxRuleConds;
    }

    /**
     * Sets the maximum number of conditions per rule.
     */
    public function setMaxRuleConds(?int $maxRuleConds): void
    {
        $this->maxRuleConds = $maxRuleConds;
    }

    /**
     * Gets information about the maximum number of total conds in entire ruleset.
     */
    public function getMaxTotalConds(): ?int
    {
        return $this->maxTotalConds;
    }

    /**
     * Sets the maximum number of total conds in entire ruleset.
     */
    public function setMaxTotalConds(?int $maxTotalConds): void
    {
        $this->maxTotalConds = $maxTotalConds;
    }

    /**
     * Gets information about the set random seed for repeatable results.
     */
    public function getRandomState(): ?int
    {
        return $this->randomState;
    }

    /**
     * Sets the random seed for repeatable results.
     */
    public function setRandomState(?int $randomState): void
    {
        $this->randomState = $randomState;
    }

    /**
     * Gets information about the verbosity of the output progress, model development, and/or computation.
     */
    public function getVerbosity(): ?int
    {
        return $this->verbosity;
    }

    /**
     * Sets information about the verbosity of the output progress, model development, and/or computation.
     */
    public function setVerbosity(?int $verbosity): void
    {
        $this->verbosity = $verbosity;
    }

    /**
     * Gives information about the Threshold that indicates the maximum % of NaN values allowed for an attribute.
     */
    function getThreshold(): ?float
    {
        return $this->threshold;
    }

    /**
     * Sets the Threshold that indicates the maximum % of NaN values allowed for an attribute.
     */
    function setThreshold(?float $threshold): void
    {
        $this->threshold = $threshold;
    }

    /**
     * Returns an uninitialized DiscriminativeModel.
     */
    public function initModel() : DiscriminativeModel
    {
        return new RuleBasedModel();
    }

    /**
     * Builds a model through a specified wittgenstein algorithm.
     *
     * @param DiscriminativeModel $model the model to train.
     * @param Instances $data the training data (wrapped in a structure that holds
     *                        the appropriate header information for the attributes).
     */
    public function teach(DiscriminativeModel &$model, Instances $data)
    {
        /** Chosen classifier */
        $classifier = $this->getClassifier();

        /** Options */
        $k = $this->getK();
        $dlAllowance = $this->getDlAllowance();
        $pruneSize = $this->getPruneSize();
        $nDiscretizeBins = $this->getNDiscretizeBins();
        $maxRules = $this->getMaxRules();
        $maxRuleConds = $this->getMaxRuleConds();
        $maxTotalConds = $this->getMaxTotalConds();
        $randomState = $this->getRandomState();
        $verbosity = $this->getVerbosity();
        $threshold = $this->getThreshold();

        /** Information about the attributes of the training data set */
        $attributes = $data->getAttributes();
        $classAttr = $data->getClassAttribute();

        /** Saving the training data set in a temporary table in the database */
        $tableName = 'piton_' . md5("reserved__tmpWittgensteinTrainData" . uniqid());
        $data->SaveToDB($tableName);

        /** If a parameter is NULL, it is translated to None */
        $getParameter = function ($parameter) {
            if ($parameter === NULL) {
                return "None";
            } else {
                return addcslashes($parameter, '"');
            }
        };

        $output = null;
        /** Call to the python script that will use the training algorithm of the library */
        if ($classifier === "RIPPERk" || $classifier === "IREP") {
            $command = escapeshellcmd("python3 " . __DIR__ . "/PythonLearners/wittgenstein_learner.py "
                . $getParameter($classifier) . " " . $getParameter($tableName) . " "
                . $getParameter($k) . " " . $getParameter($dlAllowance) . " "
                . $getParameter($pruneSize) . " " . $getParameter($nDiscretizeBins) . " "
                . $getParameter($maxRules) . " " . $getParameter($maxRuleConds) . " "
                . $getParameter($maxTotalConds) . " " . $getParameter($randomState) . " "
                . $getParameter($verbosity) . " " . $getParameter($threshold)
              . " " . $getParameter(config('database.connections.piton_connection.host'))
              . " " . $getParameter(config('database.connections.piton_connection.username'))
              . " " . $getParameter(config('database.connections.piton_connection.password'))
              . " " . $getParameter(config('database.connections.piton_connection.database')));
            $output = shell_exec($command);
        } else {
            Utils::die_error("The classifier $classifier is invalid. Please choose between RIPPERk and IREP." . PHP_EOL);
        }

        /** Drop of the temporary table for safety reason */
        Schema::connection('piton_connection')->drop($tableName);

        /** Parsing of the extracted rules to a string I can use to build a RuleBasedModel */
        #echo $output;    # DEBUG
        preg_match('/extracted_rule_based_model:(.*?)\[(.*?)\]/ms', $output, $matches);
        if (empty($matches[0])) {
            Utils::die_error("The WittgensteinLearner using $classifier did not return a valid RuleBasedModel." . PHP_EOL);
        }
        $rule = $matches[0];
        if (substr($rule, 0, strlen('extracted_rule_based_model: [')) == 'extracted_rule_based_model: [') {
            $rule = substr($rule, strlen('extracted_rule_based_model: ['));
        }
        $rule = rtrim($rule, "]");

        /** Creation of the rule based model; nb: I cannot use the same model or it will not update outside of the function */
        $newModel = RuleBasedModel::fromString($rule, $classAttr, $attributes);

        /** Model update */
        $model->setRules($newModel->getRules());
        $model->setAttributes($attributes);
    }

    /**
     * Check if Wittgenstein IREP config file has been published and set.
     *
     * @return bool
     */
    public static function IREPconfigNotPublished(): bool
    {
        return is_null(config('wittgenstein_irep'));
    }

    /**
     * Check if Wittgenstein RIPPERk config file has been published and set.
     *
     * @return bool
     */
    public static function RIPPERkconfigNotPublished(): bool
    {
        return is_null(config('wittgenstein_ripperk'));
    }

    /** Returns the name of the learner and the algorithm being used */
    function getName(): string
    {
        return "WittgensteinLearner" . "\t" . $this->getClassifier();
    }
}