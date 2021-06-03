<?php

namespace aclai-lab\piton\Learners;

use aclai-lab\piton\DiscriminativeModels\DiscriminativeModel;
use aclai-lab\piton\Instances\Instances;

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
