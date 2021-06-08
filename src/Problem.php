<?php

namespace aclai\piton;

use Illuminate\Database\Eloquent\Model;

class Problem extends Model
{
    /**
     * @var array
     */
	protected $guarded = [];

	protected $connection = "piton_connection";

	protected $table = "piton_problems";

    /**
     * It casts the columns from JSON to an array automatically without need for a json_decode().
     */
    protected $casts = [
        'inputTables' => 'array',
        'inputColumns' => 'array',
        'outputColumns' => 'array',
        'whereClauses' => 'array',
        'orderByClauses' => 'array',
    ];
}