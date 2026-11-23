<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('access_requests', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id', 'users', 'id', 'CASCADE');
            $t->foreignId('permission_id', 'permissions', 'id', 'CASCADE');
            $t->string('reason', 500);
            $t->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $t->foreignId('decided_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->dateTime('grant_expires_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
            $t->index(['user_id', 'permission_id']);
            $t->comment('Users requesting a permission; approval creates an explicit Allow override.');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('access_requests');
    }
};
