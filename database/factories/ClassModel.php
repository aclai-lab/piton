<?php

use aclai\piton\ClassModel;

$factory->define(ClassModel::class, function (Faker\Generator $faker) {
	return [
        'id_model_version' => rand(),
        'class' => $faker->sentence,
        'rules' => json_encode($faker->paragraph),
        'learner' => "RIPPERk",
        'support' => 1.0 / rand(1, 100),
        'confidence' => 1.0 / rand(1, 100),
        'lift' => 1.0 / rand(1, 100),
        'conviction' => 1.0 / rand(1, 100),
        'test_date' => date("Y-m-d H:i:s", mt_rand(1262055681, 1262055681))
    ];
});