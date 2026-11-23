<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('login_attempts', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id', 'users', 'id', 'SET NULL')->nullable();
            $t->string('email', 191);
            $t->string('ip_address', 45);
            $t->string('user_agent', 500)->nullable();
            $t->boolean('successful')->default(false);
            $t->string('failure_reason', 50)->nullable()->comment('invalid_credentials, locked, inactive, throttled');
            $t->dateTime('attempted_at');
            $t->index(['email', 'attempted_at']);
            $t->index(['ip_address', 'attempted_at']);
            $t->index(['user_id', 'attempted_at']);
            $t->index('attempted_at');
            $t->comment('Login history and brute-force tracking.');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('login_attempts');
    }
};
