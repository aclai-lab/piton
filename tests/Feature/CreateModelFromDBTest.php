<?php

namespace aclai\piton\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

use aclai\piton\ClassModel;
use aclai\piton\DiscriminativeModels\RuleBasedModel;

class CreateModelFromDBTest extends TestCase
{
    /** @test */
    public function a_model_can_be_created_from_db_specifying_its_id()
    {
      $classModel = ClassModel::orderByDesc('id')->first(); # most recent model created
      $model = RuleBasedModel::createFromDB($classModel->id);
      echo "Model created: " . $model;
      $this->assertTrue(true);
    }

    /** @test */
    public function a_class_model_can_be_created_from_db_no_learner_specified()
    {
        $model = RuleBasedModel::createFromDB('PrincipioAttivo_Calcio carbonato');
        echo "Model created: " . $model;
        $this->assertTrue(true);
    }

    /** @test */
    public function a_class_model_can_be_created_from_db_specifying_a_learner()
    {
        $model = RuleBasedModel::createFromDB('PrincipioAttivo_Calcio carbonato', 'PRip');
        echo "Model created: " . $model;
        $this->assertTrue(true);
    }
}