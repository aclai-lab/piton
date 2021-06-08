<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePitonModelVersionTable extends Migration
{
	/**
	 * Create a migration for the model_version_table.
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
		if (! Schema::connection('piton_connection')->hasTable('piton_model_version')) {
			Schema::connection('piton_connection')->create('piton_model_version', function (Blueprint $table) {
				/* ID of the model_version and therefore of the hierarchy of problems. */
				$table->increments('id');
				/* ID of the associated problem. */
        		$table->integer('id_problem');
				/* ID of the author who created the model_version. */
				/* TODO doesn't this have to be in model? What if an author modifies a model? */
				$table->integer('id_author')->nullable()->default(null);
				/* Name of the learner (and eventually the algorithm in the form 'learner\talgorithm') used. */
				$table->string('learner', 256)->nullable();
				/* Training mode ("FullTraining" or [train_w, test_w], train/test split according to these two weights). */
				$table->string('training_mode', 256)->nullable();
				/* The minimum percentage of any of the classes values for telling whether a dataset is too unbalanced. */
				$table->decimal('cut_off_value', 10, 2)->nullable();
				/* The ID of the current run. */
				/* TODO isn't this id_model_version now? */
				$table->integer('experiment_id')->nullable();
				/* Timestamp of the creation of the model_version. */
				/* TODO can't I just use created_at? */
				$table->date('date');
        /* Information about the hierarchy of problems. */
        /* TODO check if this format could work. */
        $table->json('hierarchy')->nullable()->default(null);
				/* TODO what columns for test results? The following line is temporary. */
				$table->text('test_results')->nullable();
				/* TODO difference between date and test date? Furthermore, I already have created_at.*/
				/* Date of the testing results. */
				$table->date('test_date');
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
		Schema::connection('piton_connection')->dropIfExists('piton_model_version');
	}
}