<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Prune Size
    |--------------------------------------------------------------------------
    |
    | Proportion of training set to be used for pruning.
    |
    */

    'pruneSize' => NULL,

    /*
    |--------------------------------------------------------------------------
    | Prune Size
    |--------------------------------------------------------------------------
    |
    | Fit apparent numeric attributes into a maximum of $nDiscretizeBins discrete bins,
    | inclusive on upper part of range. Pass NULL to disable auto-discretization.
    |
    */

    'nDiscretizeBins' => NULL,

    /*
    |--------------------------------------------------------------------------
    | Maximum Rules
    |--------------------------------------------------------------------------
    |
    | Maximum number of rules.
    |
    */

    'maxRules' => NULL,

    /*
    |--------------------------------------------------------------------------
    | Maximum Rule Conds
    |--------------------------------------------------------------------------
    |
    | Maximum number of conds per rule.
    |
    */

    'maxRuleConds' => NULL,

    /*
    |--------------------------------------------------------------------------
    | Random State
    |--------------------------------------------------------------------------
    |
    | Random seed for repeatable results.
    |
    */

    'randomState' => NULL,

    /*
    |--------------------------------------------------------------------------
    | Verbosity
    |--------------------------------------------------------------------------
    |
    | Output progress, model development, and/or computation.
    | Each level includes the information belonging to lower-value levels.
    |                1: Show results of each major phase
    |                2: Show Ruleset grow/optimization steps
    |                3: Show Ruleset grow/optimization calculations
    |                4: Show Rule grow/prune steps
    |                5: Show Rule grow/prune calculations
    |
    */

    'verbosity' => NULL,

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