<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('user_sessions', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id', 'users', 'id', 'CASCADE');
            $t->char('session_hash', 64)->comment('SHA-256 of a random per-login secret; the PHP session ID is never stored');
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 500)->nullable();
            $t->boolean('via_remember')->default(false);
            $t->dateTime('created_at');
            $t->dateTime('last_activity_at');
            $t->dateTime('revoked_at')->nullable();
            $t->foreignId('revoked_by', 'users', 'id', 'SET NULL')->nullable();
            $t->string('revoke_reason', 100)->nullable();
            $t->unique('session_hash');
            $t->index(['user_id', 'revoked_at']);
            $t->comment('Server-side registry of logins; revoking a row ends that session on its next request.');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('user_sessions');
    }
};
