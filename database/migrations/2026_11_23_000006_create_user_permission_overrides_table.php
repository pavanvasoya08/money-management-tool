<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('user_permission_overrides', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id', 'users', 'id', 'CASCADE');
            $t->foreignId('permission_id', 'permissions', 'id', 'CASCADE');
            $t->enum('effect', ['allow', 'deny'])->comment('deny always wins over role grants');
            $t->enum('data_scope', ['all', 'own', 'assigned', 'selected'])->nullable();
            $t->json('constraints')->nullable();
            $t->string('reason', 255)->nullable();
            $t->dateTime('expires_at')->nullable()->comment('Temporary access; ignored after expiry');
            $t->foreignId('granted_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'permission_id']);
            $t->index('permission_id');
            $t->comment('Per-user allow/deny exceptions to role permissions.');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('user_permission_overrides');
    }
};
