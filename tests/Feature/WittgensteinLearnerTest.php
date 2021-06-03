<?php

namespace aclai-lab\piton\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

use aclai-lab\piton\ClassModel;
use aclai-lab\piton\Instances\Instances;
use aclai-lab\piton\Learners\WittgensteinLearner;

class WittgensteinLearnerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function a_model_can_be_created_from_an_object_of_type_instances_with_RIPPERk()
    {
        $trainData = Instances::createFromARFF(__DIR__."/../Arff/iris.arff");
        $learner = new WittgensteinLearner("RIPPERk", 2);
        $model = $learner->initModel();
        $model->fit($trainData, $learner);
        echo "MODEL:" . PHP_EOL . $model . PHP_EOL;
        $this->assertTrue(true);
    }

    /** @test */
    public function a_model_can_be_created_from_an_object_of_type_instances_with_IREP()
    {
        $trainData = Instances::createFromARFF(__DIR__."/../Arff/iris.arff");
        $learner = new WittgensteinLearner("IREP", 2);
        $model = $learner->initModel();
        $model->fit($trainData, $learner);
        echo "MODEL:" . PHP_EOL . $model . PHP_EOL;
        $this->assertTrue(true);
    }

    /** @test */
    public function a_model_can_be_stored_into_the_database()
    {
        $trainData = Instances::createFromARFF(__DIR__."/../Arff/iris.arff");
        $learner = new WittgensteinLearner("IREP", 2);
        $model = $learner->initModel();
        $model->fit($trainData, $learner);
        $model->saveToDB(1, "myIris", "Wittgenstein IREP");
        $this->assertCount(1, ClassModel::all());
    }

    /** @test */
    public function evaluation_of_a_model_before_storing_it_into_the_database()
    {
        $trainData = Instances::createFromARFF(__DIR__."/../Arff/iris.arff");
        $testData = Instances::createFromARFF(__DIR__."/../Arff/irisTest.arff");
        $learner = new WittgensteinLearner("IREP", 2);
        $model = $learner->initModel();
        $model->fit($trainData, $learner);
        $model->saveToDB(1, "myIris", "Wittgenstein IREP", $testData);
        dd(ClassModel::all());
        $this->assertCount(1, ClassModel::all());
    }
}