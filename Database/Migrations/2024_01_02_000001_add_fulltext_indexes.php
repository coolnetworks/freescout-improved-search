<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddFulltextIndexes extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        // Only for MySQL/MariaDB
        if ($this->isPgSql()) {
            return;
        }

        // Add FULLTEXT index on conversations.subject if not exists
        try {
            DB::statement('ALTER TABLE conversations ADD FULLTEXT INDEX conversations_subject_ft (subject)');
        } catch (\Exception $e) {
            // Index may already exist
        }

        // Add FULLTEXT index on threads.body if not exists
        try {
            DB::statement('ALTER TABLE threads ADD FULLTEXT INDEX threads_body_ft (body)');
        } catch (\Exception $e) {
            // Index may already exist
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        if ($this->isPgSql()) {
            return;
        }

        try {
            DB::statement('ALTER TABLE conversations DROP INDEX conversations_subject_ft');
        } catch (\Exception $e) {
            // Index may not exist
        }

        try {
            DB::statement('ALTER TABLE threads DROP INDEX threads_body_ft');
        } catch (\Exception $e) {
            // Index may not exist
        }
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
