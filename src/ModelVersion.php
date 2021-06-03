<?php

namespace aclai\piton;

use Illuminate\Database\Eloquent\Model;

class ModelVersion extends Model
{
    /**
     * @var array
     */
	protected $guarded = [];

	protected $connection = "piton_connection";

	protected $table = "piton_model_version";

    /**
     * It casts the columns from JSON to an array automatically without need for a json_decode().
     */
    protected $casts = [
        'hierarchy' => 'array',
        'allData' => 'array',
        'trainData' => 'array',
        'testData' => 'array',
    ];
}