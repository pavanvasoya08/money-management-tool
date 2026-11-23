<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('user_resource_assignments', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id', 'users', 'id', 'CASCADE');
            $t->string('resource_type', 60)->comment('Registry key: partner, gst_registration, bank_account, platform, warehouse, ...');
            $t->unsignedBigInteger('resource_id');
            $t->foreignId('assigned_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('created_at');
            $t->unique(['user_id', 'resource_type', 'resource_id']);
            $t->index(['resource_type', 'resource_id']);
            $t->comment('Which business resources (records) are assigned to which users.');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('user_resource_assignments');
    }
};
