<?php

namespace aclai\piton\Learners;

use aclai\piton\DiscriminativeModels\DiscriminativeModel;
use aclai\piton\Instances\Instances;

/*
 * Interface for learner/optimizers
 */
abstract class Learner
{
    /* Returns an uninitialized DiscriminativeModel */
    abstract public function initModel(): DiscriminativeModel;

    /* Trains a DiscriminativeModel */
    abstract public function teach(DiscriminativeModel &$model, Instances $data);

    /* Returns the name of the learner */
    abstract public function getName();
}
