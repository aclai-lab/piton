<?php

namespace aclai\piton\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

use aclai\piton\ClassModel;

class SaveClassModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function a_class_model_can_be_created_with_the_factory()
    {
        factory(ClassModel::class)->create();

        $this->assertCount(1, ClassModel::all());
    }
}