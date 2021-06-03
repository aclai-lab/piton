<?php

namespace aclai-lab\piton;

use Illuminate\Database\Eloquent\Model;

class ClassModel extends Model
{
    /**
     * @var array
     */
	protected $guarded = [];

	protected $connection = "piton_connection";

	protected $table = "piton_class_model";

    /**
     * It casts the column rules from JSON to an array automatically.
     * This way, I will receive $classModel->rules as array and don’t need to do json_decode().
     */
    protected $casts = [
        'class' => 'array',
        'rules' => 'array',
        'attributes' => 'array'
    ];
}