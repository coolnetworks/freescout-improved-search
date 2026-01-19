<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSearchTables extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        // Search index table for optimized full-text search
        Schema::create('search_index', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('conversation_id')->unique();
            $table->unsignedInteger('mailbox_id')->index();
            $table->unsignedInteger('customer_id')->nullable()->index();
            $table->string('subject', 1000)->nullable();
            $table->string('customer_email', 255)->nullable();
            $table->string('customer_name', 255)->nullable();
            $table->text('preview')->nullable();
            $table->mediumText('body_text')->nullable();
            $table->unsignedTinyInteger('status')->default(1)->index();
            $table->unsignedTinyInteger('state')->default(1)->index();
            $table->timestamps();

            $table->foreign('conversation_id')
                ->references('id')
                ->on('conversations')
                ->onDelete('cascade');

            $table->foreign('mailbox_id')
                ->references('id')
                ->on('mailboxes')
                ->onDelete('cascade');
        });

        // Add fulltext indexes for MySQL
        if (!$this->isPgSql()) {
            DB::statement('ALTER TABLE search_index ADD FULLTEXT INDEX search_index_subject_ft (subject)');
            DB::statement('ALTER TABLE search_index ADD FULLTEXT INDEX search_index_body_ft (body_text)');
            DB::statement('ALTER TABLE search_index ADD FULLTEXT INDEX search_index_combined_ft (subject, customer_email, customer_name, body_text)');
        }

        // Search history table for suggestions and analytics
        Schema::create('search_history', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->index();
            $table->string('query', 255);
            $table->unsignedInteger('results_count')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->index(['user_id', 'query']);
            $table->index(['query', 'results_count']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('search_history');
        Schema::dropIfExists('search_index');
    }

    /**
     * Check if using PostgreSQL.
     */
    protected function isPgSql()
    {
        return config('database.default') === 'pgsql'
            || DB::connection()->getDriverName() === 'pgsql';
    }
}
