<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePitonProblemsTable extends Migration
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
    if (! Schema::connection('piton_connection')->hasTable('piton_problems')) {
      Schema::connection('piton_connection')->create('piton_problems', function (Blueprint $table) {
        /* ID of the problem. */
        $table->increments('id');
        /* Name of the problem. */
        $table->string('name', 256);
        /* The database tables where the input columns are (array of table-terms, one for each table). */
        $table->json('inputTables');
        /* Input columns (array of inputColumn-terms, one for each column). */
        $table->json('inputColumns');
        /* Columns that are to be treated as output (array of outputColumn-terms, one for each column). */
        $table->json('outputColumns');
        /* SQL WHERE clauses for the concerning inputTables (array of {array of strings, or single string}). */
        $table->json('whereClauses');
        /* SQL ORDER BY clauses (array of strings, or single string). */
        $table->json('OrderByClauses');
        /* SQL LIMIT term in the SELECT query (integer). */
        /* This is perhaps just for debug.
           TODO remove this parameter? note that right now we use the same value at every recursion level.
           Maybe we want to specify a different value for every recursion level instead? */
        $table->integer('limit')->nullable();
        /* An identifier column, used for sql-based prediction and a correct retrieval step of prediction results.
          Furthermore, a value for the identifier column identifies a set of data rows that are to be
          compressed into a single data instance before use. TODO explain better this important point. */
        $table->string('identifierColumnName', 256);
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
    Schema::connection('piton_connection')->dropIfExists('piton_problems');
  }
}