<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('remember_tokens', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id', 'users', 'id', 'CASCADE');
            $t->char('selector', 24)->comment('Public lookup part of the cookie');
            $t->char('validator_hash', 64)->comment('SHA-256 of the secret part; rotated on every use');
            $t->dateTime('expires_at');
            $t->dateTime('last_used_at')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 500)->nullable();
            $t->dateTime('created_at');
            $t->unique('selector');
            $t->index('user_id');
            $t->comment('Remember-me tokens (split selector/validator, one row per device).');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('remember_tokens');
    }
};
