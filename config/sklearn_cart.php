<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Criterion.
    |--------------------------------------------------------------------------
    |
    | The function to measure the quality of a split. Supported criteria are
    | "gini" for the Gini impurity and "entropy" for the information gain.
    | If not specified, the scikit-learn package default value is used.
    |
    */
    'criterion' => NULL,

    /*
    |--------------------------------------------------------------------------
    | Splitter.
    |--------------------------------------------------------------------------
    |
    | The strategy used to choose the split at each node. Supported
    | strategies are "best" to choose the best split and "random" to choose
    | the best random split.
    | If not specified, the scikit-learn package default value is used.
    |
    */

    'splitter' => NULL,

    /*
    |--------------------------------------------------------------------------
    | Maximum Depth.
    |--------------------------------------------------------------------------
    |
    | The maximum depth of the tree. If NULL, then nodes are expanded until
    | all leaves are pure or until all leaves contain less than
    | min_samples_split samples.
    | If not specified, the scikit-learn package default value is used.
    |
    */

    'maxDepth' => NULL,

    /*
    |--------------------------------------------------------------------------
    | Minimum Samples Split.
    |--------------------------------------------------------------------------
    |
    | The minimum number of samples required to split an internal node:
    | it is a fraction, and `ceil(min_samples_split * n_samples)` are the minimum
    | number of samples for each split.
    | If not specified, the scikit-learn package default value is used.
    |
    */

    'minSamplesSplit' => NULL,

    /*
    |--------------------------------------------------------------------------
    | Minimum Samples Leaf.
    |--------------------------------------------------------------------------
    |
    | The minimum number of samples required to be at a leaf node.
    | A split point at any depth will only be considered if it leaves at
    | least `min_samples_leaf` training samples in each of the left and
    | right branches.  This may have the effect of smoothing the model,
    | especially in regression.
    | It is a fraction, and `ceil(min_samples_leaf * n_samples)` are the minimum
    | number of samples for each node.
    | If not specified, the scikit-learn package default value is used.
    |
    */

    'minSamplesLeaf' => NULL,

    /*
    |--------------------------------------------------------------------------
    | Minimum Weight Fraction Leaf.
    |--------------------------------------------------------------------------
    |
    | The minimum weighted fraction of the sum total of weights (of all
    | the input samples) required to be at a leaf node. Samples have
    | equal weight when sample_weight is not provided.
    |
    */

    'minWeightFractionLeaf' => NULL,

    /*
    |--------------------------------------------------------------------------
    | Maximum Features.
    |--------------------------------------------------------------------------
    |
    | The number of features to consider when looking for the best split:
    |  - If "auto", then `max_features=sqrt(n_features)`.
    |  - If "sqrt", then `max_features=sqrt(n_features)`.
    |  - If "log2", then `max_features=log2(n_features)`.
    |  - If NULL, then `max_features=n_features`.
    | Note: the search for a split does not stop until at least one
    | valid partition of the node samples is found, even if it requires to
    | effectively inspect more than `max_features` features.
    |
    */

    'maxFeatures' => NULL,

    /*
    |--------------------------------------------------------------------------
    | Random State.
    |--------------------------------------------------------------------------
    |
    | Controls the randomness of the estimator. The features are always
    | randomly permuted at each split, even if `splitter` is set to
    | `"best"`. When `max_features < n_features`, the algorithm will
    | select `max_features` at random at each split before finding the best
    | split among them. But the best found split may vary across different
    | runs, even if `max_features=n_features`. That is the case, if the
    | improvement of the criterion is identical for several splits and one
    | split has to be selected at random. To obtain a deterministic behaviour
    | during fitting, `random_state` has to be fixed to an integer.
    |
    */

    'randomState' => NULL,

    /*
    |--------------------------------------------------------------------------
    | Maximum Leaf Nodes.
    |--------------------------------------------------------------------------
    |
    | Grow a tree with `max_leaf_nodes` in best-first fashion.
    | Best nodes are defined as relative reduction in impurity.
    | If NULL then unlimited number of leaf nodes.
    |
    */

    'maxLeafNodes' => NULL,

    /*
    |--------------------------------------------------------------------------
    | Minimum Impurity Decrease.
    |--------------------------------------------------------------------------
    |
    | A node will be split if this split induces a decrease of the impurity
    | greater than or equal to this value.
    |
    | The weighted impurity decrease equation is the following:
    |
    |    N_t / N * (impurity - N_t_R / N_t * right_impurity
    |                        - N_t_L / N_t * left_impurity)
    |
    | where `N` is the total number of samples, `N_t` is the number of
    | samples at the current node, `N_t_L` is the number of samples in the
    | left child, and `N_t_R` is the number of samples in the right child.
    |
    | `N`, `N_t`, `N_t_R` and `N_t_L` all refer to the weighted sum,
    | if `sample_weight` is passed.
    |
    */

    'minImpurityDecrease' => NULL,

    /*
    |--------------------------------------------------------------------------
    | Minimum Impurity Split.
    |--------------------------------------------------------------------------
    |
    | Threshold for early stopping in tree growth. A node will split
    | if its impurity is above the threshold, otherwise it is a leaf.
    |
    */

    'minImpuritySplit' => NULL,

    /*
    |--------------------------------------------------------------------------
    | Class Weight.
    |--------------------------------------------------------------------------
    |
    | Weights associated with classes in the form `{class_label: weight}`.
    | If NULL, all classes are supposed to have weight one. For
    | multi-output problems, a list of dicts can be provided in the same
    | order as the columns of y.
    |
    | Note that for multioutput (including multilabel) weights should be
    | defined for each class of every column in its own dict. For example,
    | for four-class multilabel classification weights should be
    | [{0: 1, 1: 1}, {0: 1, 1: 5}, {0: 1, 1: 1}, {0: 1, 1: 1}] instead of
    | [{1:1}, {2:5}, {3:1}, {4:1}].
    |
    | The "balanced" mode uses the values of y to automatically adjust
    | weights inversely proportional to class frequencies in the input data
    | as `n_samples / (n_classes * np.bincount(y))`
    |
    | For multi-output, the weights of each column of y will be multiplied.
    |
    | Note that these weights will be multiplied with sample_weight (passed
    | through the fit method) if sample_weight is specified.
    |
    */

    'classWeight' => NULL,

    /*
    |--------------------------------------------------------------------------
    | Cost Complexity Pruning Alpha.
    |--------------------------------------------------------------------------
    |
    | Complexity parameter used for Minimal Cost-Complexity Pruning. The
    | subtree with the largest cost complexity that is smaller than
    | `ccp_alpha` will be chosen. By default, no pruning is performed.
    |
    */

    'ccpAlpha' => NULL,

    /*
    |--------------------------------------------------------------------------
    | Threshold.
    |--------------------------------------------------------------------------
    |
    | Threshold that indicates the maximum % of NaN values allowed for an
    | attribute to be considered valid, and so to be considered in the
    | classification process.
    |
    */

    'threshold' => 0.1,
];