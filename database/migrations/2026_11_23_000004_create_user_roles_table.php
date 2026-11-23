<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('user_roles', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id', 'users', 'id', 'CASCADE');
            $t->foreignId('role_id', 'roles', 'id', 'CASCADE');
            $t->foreignId('assigned_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('assigned_at');
            $t->unique(['user_id', 'role_id']);
            $t->index('role_id');
            $t->comment('Many-to-many: a user can hold multiple roles.');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('user_roles');
    }
};
