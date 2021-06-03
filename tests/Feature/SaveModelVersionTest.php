<?php

namespace aclai-lab\piton\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

use aclai-lab\piton\ModelVersion;

class SaveModelVersionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function a_model_version_can_be_created_with_the_factory()
    {
        factory(ModelVersion::class)->create();

        $this->assertCount(1, ModelVersion::all());
    }
}