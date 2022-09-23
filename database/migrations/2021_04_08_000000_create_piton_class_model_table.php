<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePitonClassModelTable extends Migration
{
	/**
	 * Create a migration for the class_model_table.
	 *
	 * @return void
	 */
	public function up()
	{
		/**
		 * I first have to check if the table is present in the database, because of a problem with the order of
		 * execution inside the command migrate:fresh, which will first drop all the table in the default database
		 * and then try to create all the tables, including this one. This is the actual turn-around suggested.
		 * Source: https://github.com/laravel/framework/issues/21009
		 */
		if (! Schema::connection('piton_connection')->hasTable('piton_class_model')) {
			Schema::connection('piton_connection')->create('piton_class_model', function (Blueprint $table) {
				/* ID of the class_model instance, therefore of the model. */
				$table->increments('id');
				/* ID of the relative model_version, therefore the hierarchy of problems which created it. */
				$table->integer('id_model_version');
				/* The level of the recursion on the hierarchy of problems. */
				$table->integer('recursion_level')->default(0);
				/* The father node in the hierarchy of problems. */
				$table->string('father_node')->nullable();
				/* The class attribute of the model and its domain. */
				$table->json('class');
				/* The rules of the rule based model and relative testing values. */
				$table->json('rules');
				/* The rules of the rule based model in json logic format. */
				$table->json('json_logic_rules');
				/* The model attributes. */
				$table->json('attributes')->nullable()->default(null);
				/* Test results */
				$table->integer('totNumRules');
				$table->integer('numRulesRA')->nullable()->default(null);
				$table->integer('numRulesRNA')->nullable()->default(null);
				$table->integer('numRulesNRA')->nullable()->default(null);
				$table->integer('numRulesNRNA')->nullable()->default(null);
				$table->decimal('percRulesRA', 10, 2)->nullable();
				$table->decimal('percRulesRNA', 10, 2)->nullable();
				$table->decimal('percRulesNRA', 10, 2)->nullable();
				$table->decimal('percRulesNRNA', 10, 2)->nullable();
				$table->decimal('totPositives', 10, 2)->nullable()->default(null);
				$table->decimal('totNegatives', 10, 2)->nullable()->default(null);
				$table->decimal('totN', 10, 2)->nullable();
				$table->decimal('totClassShare', 10, 2)->nullable();
				$table->decimal('testPositives', 10, 2)->nullable()->default(null);
				$table->decimal('testNegatives', 10, 2)->nullable()->default(null);
				$table->decimal('testN', 10, 2)->nullable();
				$table->decimal('trainN', 10, 2)->nullable();
				$table->decimal('TP', 10, 2)->nullable()->default(null);
				$table->decimal('TN', 10, 2)->nullable()->default(null);
				$table->decimal('FP', 10, 2)->nullable()->default(null);
				$table->decimal('FN', 10, 2)->nullable()->default(null);
				$table->decimal('TP_typeRA', 10, 2)->nullable()->default(null);
				$table->decimal('TP_typeRNA', 10, 2)->nullable()->default(null);
				$table->decimal('TP_typeNRA', 10, 2)->nullable()->default(null);
				$table->decimal('TP_typeNRNA', 10, 2)->nullable()->default(null);
				$table->decimal('TN_typeRA', 10, 2)->nullable()->default(null);
				$table->decimal('TN_typeRNA', 10, 2)->nullable()->default(null);
				$table->decimal('TN_typeNRA', 10, 2)->nullable()->default(null);
				$table->decimal('TN_typeNRNA', 10, 2)->nullable()->default(null);
				$table->decimal('FP_typeRA', 10, 2)->nullable()->default(null);
				$table->decimal('FP_typeRNA', 10, 2)->nullable()->default(null);
				$table->decimal('FP_typeNRA', 10, 2)->nullable()->default(null);
				$table->decimal('FP_typeNRNA', 10, 2)->nullable()->default(null);
				$table->decimal('FN_typeRA', 10, 2)->nullable()->default(null);
				$table->decimal('FN_typeRNA', 10, 2)->nullable()->default(null);
				$table->decimal('FN_typeNRA', 10, 2)->nullable()->default(null);
				$table->decimal('FN_typeNRNA', 10, 2)->nullable()->default(null);
				$table->decimal('accuracy', 10, 2)->nullable()->default(null);
				$table->decimal('sensitivity', 10, 2)->nullable()->default(null);
				$table->decimal('specificity', 10, 2)->nullable()->default(null);
				$table->decimal('PPV', 10, 2)->nullable()->default(null);
				$table->decimal('NPV', 10, 2)->nullable()->default(null);
				/* Timestamp on when the testing occurred. */
				/* TODO remove this column */
				$table->date('test_date');
						/* Additional infos, such as the IDs of the instances used for training and testing. */
				$table->json('additional_infos')->nullable()->default(null);
				/* Standards. */
				$table->timestamps();
				$table->index('created_at');
				$table->index('updated_at');
			});
		}
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::connection('piton_connection')->dropIfExists('piton_class_model');
	}
}
