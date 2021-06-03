<?php

namespace aclai-lab\piton\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;

use aclai-lab\piton\Instances\Instances;

class InstancesTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function an_object_of_type_instances_can_be_created_from_an_arff_file()
    {
        $trainData = Instances::createFromARFF(__DIR__."/../Arff/iris.arff");
        $trainData->saveToARFF(__DIR__."/../Arff/myIris.arff");
        $this->assertTrue(true);
    }

    /** @test */
    public function an_object_of_type_instances_can_be_saved_to_db()
    {
        $trainData = Instances::createFromARFF(__DIR__."/../Arff/iris.arff");
        $trainData->saveToDB("myIris");
        $this->assertCount(100, DB::connection('piton_connection')->table("myIris")->get());
    }
}