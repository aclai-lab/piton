<?php

namespace aclai\piton\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

use aclai\piton\ClassModel;
use aclai\piton\DBFit\DBFit;
use aclai\piton\DiscriminativeModels\RuleBasedModel;
use aclai\piton\PitonBaseServiceProvider;

class PredictionTest extends TestCase
{
  /** @test */
  public function a_prediction_on_an_instance_of_given_id_can_be_done()
  {
    $db_fit = new DBFit();
    $classModel = ClassModel::orderByDesc('id')->first(); # most recent model created
    $model = RuleBasedModel::createFromDB($classModel->id);
    echo "Model created: " . $model;
    $db_fit->setIdentifierColumnName('referti.id');
    $db_fit->setOutputColumns(config('piton.outputColumns'));
    $db_fit->predictByIdentifier(1);
    dd($db_fit->getPredictionResults());
    $this->assertTrue(true);
  }
}