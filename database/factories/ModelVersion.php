<?php

use aclai\piton\ModelVersion;

$factory->define(ModelVersion::class, function (Faker\Generator $faker) {
	return [
        'id' => rand(),
        'author' => $faker->sentence,
        'test_results' => $faker->paragraph,
        'test_date' => date("Y-m-d H:i:s", mt_rand(1262055681, 1262055681))
    ];
});