<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePitonRulesTable extends Migration
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
    if (! Schema::connection('piton_connection')->hasTable('piton_rules')) {
      Schema::connection('piton_connection')->create('piton_rules', function (Blueprint $table) {
        /* Global ID of the rule. */
        $table->increments('id');
        /* ID of the class model instance which contains the rule. */
        /* Note: I only need this to uniquely get the model version and, therefore, the associated problem. */
        $table->integer('id_class_model');
        /* The antecedents of the rule in the form of {feature_id, feature, operator and value}. */
        $table->json('antecedents');
        /* The consequent of the rule. */
        $table->string('consequent', 256);
        /* Test results. */
        $table->integer('covered')->nullable();
        $table->decimal('support', 10, 2)->nullable();
        $table->decimal('confidence', 10, 2)->nullable();
        $table->decimal('lift', 10, 2)->nullable();
        $table->decimal('conviction', 10, 2)->nullable();
        $table->integer('globalCovered')->nullable();
        $table->decimal('globalSupport', 10, 2)->nullable();
        $table->decimal('globalConfidence', 10, 2)->nullable();
        $table->decimal('globalLift', 10, 2)->nullable();
        $table->decimal('globalConviction', 10, 2)->nullable();
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
    Schema::connection('piton_connection')->dropIfExists('piton_rules');
  }
}