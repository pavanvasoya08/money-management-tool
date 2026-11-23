<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('password_reset_tokens', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id', 'users', 'id', 'CASCADE');
            $t->char('token_hash', 64)->comment('SHA-256 of the emailed token; the raw token is never stored');
            $t->dateTime('expires_at');
            $t->dateTime('used_at')->nullable()->comment('Single use');
            $t->string('requested_ip', 45)->nullable();
            $t->dateTime('created_at');
            $t->unique('token_hash');
            $t->index(['user_id', 'created_at']);
            $t->comment('Single-use, expiring password reset tokens.');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('password_reset_tokens');
    }
};
