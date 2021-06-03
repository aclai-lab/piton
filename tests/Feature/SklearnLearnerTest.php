<?php

namespace aclai-lab\piton\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

use aclai-lab\piton\Instances\Instances;
use aclai-lab\piton\Learners\SklearnLearner;

class SklearnLearnerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function a_model_can_be_created_from_an_object_of_type_instances_with_CART()
    {
        $trainData = Instances::createFromARFF(__DIR__ . "/../Arff/iris.arff");
        $learner = new SklearnLearner("CART");
        $model = $learner->initModel();
        $model->fit($trainData, $learner);
        echo "MODEL:" . PHP_EOL . $model . PHP_EOL;
        $this->assertTrue(true);
    }
}