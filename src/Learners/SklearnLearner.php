<?php

namespace aclai\piton\Learners;

use Illuminate\Support\Facades\Schema;

use Illuminate\Validation\Rule;
use aclai\piton\Attributes\Attribute;
use aclai\piton\Attributes\DiscreteAttribute;
use aclai\piton\Facades\Utils;
use aclai\piton\Instances\Instances;
use aclai\piton\DiscriminativeModels\RuleBasedModel;
use aclai\piton\DiscriminativeModels\DiscriminativeModel;

/*
 * Interface for Sklearn learner.
 *
 * This class offers compatibility with the python sklearn package, which
 * implements an optimised version of the CART algorithm for the creation of
 * decision trees. These trees are then converted into RuleBasedModels.
 *
 * The package must be installed on your operating system or python environment.
 * To install, use:
 *    $ pip3 install -U scikit-learn pymysql
 */

class SklearnLearner extends Learner
{
    /**
     * The chosen algorithm used for classification.
     * At the moment, only CART is supported.
     */
    private $classifier;

    /*** Options that are useful during the training stage. */

    /**
     * The function to measure the quality of a split. Supported criteria are
     * "gini" for the Gini impurity and "entropy" for the information gain.
     */
    private $criterion;

    /**
     * The strategy used to choose the split at each node. Supported
     * strategies are "best" to choose the best split and "random" to choose
     * the best random split.
     */
    private $splitter;

    /**
     * The maximum depth of the tree. If None, then nodes are expanded until
     * all leaves are pure or until all leaves contain less than
     * min_samples_split samples.
     */
    private $maxDepth;

    /**
     * The minimum number of samples required to split an internal node:
     * it is a fraction, and `ceil(min_samples_split * n_samples)` are the minimum
     * number of samples for each split.
     */
    private $minSamplesSplit;

    /**
     * The minimum number of samples required to be at a leaf node.
     * A split point at any depth will only be considered if it leaves at
     * least `min_samples_leaf` training samples in each of the left and
     * right branches.  This may have the effect of smoothing the model,
     * especially in regression.
     * It is a fraction, and `ceil(min_samples_leaf * n_samples)` are the minimum
     * number of samples for each node.
     */
    private $minSamplesLeaf;

    /**
     * The minimum weighted fraction of the sum total of weights (of all
     * the input samples) required to be at a leaf node. Samples have
     * equal weight when sample_weight is not provided.
     */
    private $minWeightFractionLeaf;

    /**
     * The number of features to consider when looking for the best split:
     *  - If "auto", then `max_features=sqrt(n_features)`.
     *  - If "sqrt", then `max_features=sqrt(n_features)`.
     *  - If "log2", then `max_features=log2(n_features)`.
     *  - If None, then `max_features=n_features`.
     * Note: the search for a split does not stop until at least one
     * valid partition of the node samples is found, even if it requires to
     * effectively inspect more than `max_features` features.
     */
    private $maxFeatures;

    /**
     * Controls the randomness of the estimator. The features are always
     * randomly permuted at each split, even if `splitter` is set to
     * `"best"`. When `max_features < n_features`, the algorithm will
     * select `max_features` at random at each split before finding the best
     * split among them. But the best found split may vary across different
     * runs, even if `max_features=n_features`. That is the case, if the
     * improvement of the criterion is identical for several splits and one
     * split has to be selected at random. To obtain a deterministic behaviour
     * during fitting, `random_state` has to be fixed to an integer.
     */
    private $randomState;

    /**
     * Grow a tree with `max_leaf_nodes` in best-first fashion.
     * Best nodes are defined as relative reduction in impurity.
     * If None then unlimited number of leaf nodes.
     */
    private $maxLeafNodes;

    /**
     * A node will be split if this split induces a decrease of the impurity
     * greater than or equal to this value.
     *
     * The weighted impurity decrease equation is the following:
     *
     *    N_t / N * (impurity - N_t_R / N_t * right_impurity
     *                        - N_t_L / N_t * left_impurity)
     *
     * where `N` is the total number of samples, `N_t` is the number of
     * samples at the current node, `N_t_L` is the number of samples in the
     * left child, and `N_t_R` is the number of samples in the right child.
     *
     * `N`, `N_t`, `N_t_R` and `N_t_L` all refer to the weighted sum,
     * if `sample_weight` is passed.
     */
    private $minImpurityDecrease;

    /**
     * Weights associated with classes in the form `{class_label: weight}`.
     * If None, all classes are supposed to have weight one. For
     * multi-output problems, a list of dicts can be provided in the same
     * order as the columns of y.
     *
     * Note that for multioutput (including multilabel) weights should be
     * defined for each class of every column in its own dict. For example,
     * for four-class multilabel classification weights should be
     * [{0: 1, 1: 1}, {0: 1, 1: 5}, {0: 1, 1: 1}, {0: 1, 1: 1}] instead of
     * [{1:1}, {2:5}, {3:1}, {4:1}].
     *
     * The "balanced" mode uses the values of y to automatically adjust
     * weights inversely proportional to class frequencies in the input data
     * as `n_samples / (n_classes * np.bincount(y))`
     *
     * For multi-output, the weights of each column of y will be multiplied.
     *
     * Note that these weights will be multiplied with sample_weight (passed
     * through the fit method) if sample_weight is specified.
     */
    private $classWeight;

    /**
     * Complexity parameter used for Minimal Cost-Complexity Pruning. The
     * subtree with the largest cost complexity that is smaller than
     * `ccp_alpha` will be chosen. By default, no pruning is performed.
     */
    private $ccpAlpha;

    /**
     * Threshold that indicates the maximum % of NaN values allowed for an attribute
     * to be considered valid, and so to be considered in the classification process.
     */
    private $threshold;

    /**
     * The constructor of the SklearnLearner.
     * A classifier algorithm and a database connection are required, but they can be changed later on.
     * It is also possible to set a random seed, which is set to NULL by default.
     * The other options are set to NULL and will be set by pyhton to their default values, and can be set later on.
     */
    function __construct(string $classifier, ?int $randomState = NULL)
    {
        $this->setClassifier($classifier);
        $this->setRandomState($randomState);
        /** Options */
        $this->setCriterion(NULL);
        $this->setSplitter(NULL);
        $this->setMaxDepth(NULL);
        $this->setMinSamplesSplit(NULL);
        $this->setMinSamplesLeaf(NULL);
        $this->setMinWeightFractionLeaf(NULL);
        $this->setMaxFeatures(NULL);
        $this->setMaxLeafNodes(NULL);
        $this->setMinImpurityDecrease(NULL);
        $this->setClassWeight(NULL);
        $this->setCcpAlpha(NULL);
        $this->setThreshold(NULL);
    }

    /**
     * Gives information about the current sklearn classifier used for the training.
     */
    function getClassifier(): string
    {
        return $this->classifier;
    }

    /**
     * Sets the sklearn classifier to use for the training.
     * Only CART is supported at the moment.
     */
    function setClassifier(string $classifier): void
    {
        $this->classifier = $classifier;
    }

    /**
     * Gives information about the criterion.
     */
    function getCriterion(): ?string
    {
        return $this->criterion;
    }

    /**
     * Sets the criterion used.
     */
    function setCriterion(?string $criterion): void
    {
        $this->criterion = $criterion;
    }

    /**
     * Gives information about the splitter.
     */
    function getSplitter(): ?string
    {
        return $this->splitter;
    }

    /**
     * Sets the splitter used.
     */
    function setSplitter(?string $splitter): void
    {
        $this->splitter = $splitter;
    }

    /**
     * Gives information about the maxDepth.
     */
    function getMaxDepth(): ?int
    {
        return $this->maxDepth;
    }

    /**
     * Sets the maxDepth used.
     */
    function setMaxDepth(?int $maxDepth): void
    {
        $this->maxDepth = $maxDepth;
    }

    /**
     * Gives information about the minSamplesSplit.
     */
    function getMinSamplesSplit(): ?float
    {
        return $this->minSamplesSplit;
    }

    /**
     * Sets the minSamplesSplit used.
     */
    function setMinSamplesSplit(?float $minSamplesSplit): void
    {
        $this->minSamplesSplit = $minSamplesSplit;
    }

    /**
     * Gives information about the minSamplesLeaf.
     */
    function getMinSamplesLeaf(): ?float
    {
        return $this->minSamplesLeaf;
    }

    /**
     * Sets the minSamplesLeaf used.
     */
    function setMinSamplesLeaf(?float $minSamplesLeaf): void
    {
        $this->minSamplesLeaf = $minSamplesLeaf;
    }

    /**
     * Gives information about the minWeightFractionLeaf.
     */
    function getMinWeightFractionLeaf(): ?float
    {
        return $this->minWeightFractionLeaf;
    }

    /**
     * Sets the minWeightFractionLeaf used.
     */
    function setMinWeightFractionLeaf(?float $minWeightFractionLeaf): void
    {
        $this->minWeightFractionLeaf = $minWeightFractionLeaf;
    }

    /**
     * Gives information about the maxFeatures.
     */
    function getMaxFeatures(): ?string
    {
        return $this->maxFeatures;
    }

    /**
     * Sets the maxFeatures used.
     */
    function setMaxFeatures(?string $maxFeatures): void
    {
        $this->maxFeatures = $maxFeatures;
    }

    /**
     * Gets information about the setted random seed for repeatable results.
     */
    function getRandomState(): ?int
    {
        return $this->randomState;
    }

    /**
     * Sets the random seed for repeatable results.
     */
    function setRandomState(?int $randomState): void
    {
        $this->randomState = $randomState;
    }

    /**
     * Gives information about the maxLeafNodes.
     */
    function getMaxLeafNodes(): ?int
    {
        return $this->maxLeafNodes;
    }

    /**
     * Sets the maxLeafNodes used.
     */
    function setMaxLeafNodes(?int $maxLeafNodes): void
    {
        $this->maxLeafNodes = $maxLeafNodes;
    }

    /**
     * Gives information about the minImpurityDecrease.
     */
    function getMinImpurityDecrease(): ?float
    {
        return $this->minImpurityDecrease;
    }

    /**
     * Sets the minImpurityDecrease used.
     */
    function setMinImpurityDecrease(?float $minImpurityDecrease): void
    {
        $this->minImpurityDecrease = $minImpurityDecrease;
    }

    /**
     * Gives information about the classWeight.
     */
    function getClassWeight(): ?string
    {
        return $this->classWeight;
    }

    /**
     * Sets the classWeight used.
     */
    function setClassWeight(?string $classWeight): void
    {
        $this->classWeight = $classWeight;
    }

    /**
     * Gives information about the ccpAlpha.
     */
    function getCcpAlpha(): ?float
    {
        return $this->ccpAlpha;
    }

    /**
     * Sets the ccpAlpha used.
     */
    function setCcpAlpha(?float $ccpAlpha): void
    {
        $this->ccpAlpha = $ccpAlpha;
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
     * Returns an uninitialized normalized DiscriminativeModel.
     */
    function initModel() : DiscriminativeModel
    {
        $model = new RuleBasedModel();
        $model->setIsNormalized(true);
        return $model;
    }

    /**
     * Builds a model through a specified wittgenstein algorithm.
     *
     * @param DiscriminativeModel $model the model to train.
     * @param Instances $data the training data (wrapped in a structure that holds the appropriate header information
     *                        for the attributes).
     */
    function teach(DiscriminativeModel &$model, Instances $data)
    {

        /** Chosen classifier */
        $classifier = $this->getClassifier();

        /** Options */
        $criterion = $this->getCriterion();
        $splitter = $this->getSplitter();
        $maxDepth = $this->getMaxDepth();
        $minSamplesSplit = $this->getMinSamplesSplit();
        $minSamplesLeaf = $this->getMinSamplesLeaf();
        $minWeightFractionLeaf = $this->getMinWeightFractionLeaf();
        $maxFeatures = $this->getMaxFeatures();
        $randomState = $this->getRandomState();
        $maxLeafNodes = $this->getMaxLeafNodes();
        $minImpurityDecrease = $this->getMinImpurityDecrease();
        $classWeight = $this->getClassWeight();
        $ccpAlpha = $this->getCcpAlpha();
        $threshold = $this->getThreshold();

        /** Information about the attributes of the training data set */
        $attributes = $data->getAttributes();
        $classAttr = $data->getClassAttribute();

        /** Saving the training data set in a temporary table in the database */
        $tableName = 'piton_' . md5("reserved__tmpSklearnTrainData" . uniqid());
        $data->SaveToDB($tableName);

        /** If a parameter is NULL, I translate it to None */
        $getParameter = function ($parameter) {
            if ($parameter === NULL) {
                return "None";
            } else {
                return addcslashes($parameter, '"');
            }
        };

        /** Call to the python script that will use the training algorithm of the library */
        if ($classifier === "CART") {
            $command = escapeshellcmd("python3 " . __DIR__ . "/PythonLearners/sklearn_learner.py "
                . $getParameter($classifier) . " " . $getParameter($tableName) . " "
                . $getParameter($criterion) . " " . $getParameter($splitter) . " "
                . $getParameter($maxDepth) . " " . $getParameter($minSamplesSplit) . " "
                . $getParameter($minSamplesLeaf) . " " . $getParameter($minWeightFractionLeaf) . " "
                . $getParameter($maxFeatures) . " " . $getParameter($randomState) . " "
                . $getParameter($maxLeafNodes) . " " . $getParameter($minImpurityDecrease) . " "
                . " " . $getParameter($classWeight) . " " . $getParameter($ccpAlpha) . " " . $getParameter($threshold)
                . " " . $getParameter(config('database.connections.piton_connection.host'))
                . " " . $getParameter(config('database.connections.piton_connection.username'))
                . " " . $getParameter(config('database.connections.piton_connection.password'))
                . " " . $getParameter(config('database.connections.piton_connection.database')));
            $output = shell_exec($command);
        } else {
            Utils::die_error("The classifier $classifier is invalid. Only CART is supported at the moment." . PHP_EOL);
        }

        /** Drop of the temporary table for safety reason */
        Schema::connection('piton_connection')->drop($tableName);

        /** Parsing of the extracted rules to a string I can use to build a RuleBasedModel */
        #echo $output;    # DEBUG
        preg_match('/extracted_rule_based_model:(.*?)\[(.*?)\]/ms', $output, $matches);
        if (empty($matches[0])) {
            Utils::die_error("The SklearnLearner using $classifier did not return a valid RuleBasedModel." . PHP_EOL);
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
        /** All the attributes are now continuous, so the old discrete attributes have been transformed into continuous */
        $model->setAttributes($attributes);
    }

    /**
     * Check if SKLear CART config file has been published and set.
     *
     * @return bool
     */
    public static function CARTconfigNotPublished(): bool
    {
        return is_null(config('sklearn_cart'));
    }

    /** Returns the name of the learner and the algorithm being used */
    function getName(): string
    {
        return "SKLearnLearner" . "\t" . $this->getClassifier();
    }
}