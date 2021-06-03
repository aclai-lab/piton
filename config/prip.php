<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Number of Minimal Weights.
    |--------------------------------------------------------------------------
    |
    | Minimal weights of instance weights within a split.
    |
    */

    'minNo' => 2,

    /*
    |--------------------------------------------------------------------------
    | Number of Folders
    |--------------------------------------------------------------------------
    |
    | The number of folds to split data into Grow and Prune for IREP.
    | One fold is used as pruning set.
    |
    */
    'numFolds' => 5,

    /*
    |--------------------------------------------------------------------------
    | Number of Optimizations
    |--------------------------------------------------------------------------
    |
    | Number of runs of optimizations.
    |
    */

    'numOptimizations' => 2,
];