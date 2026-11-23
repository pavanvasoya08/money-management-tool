<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('users', static function (Blueprint $t): void {
            $t->id();
            $t->string('name', 120);
            $t->string('email', 191);
            $t->string('phone', 20)->nullable();
            $t->string('password_hash', 255)->comment('password_hash() output — never plaintext');
            $t->enum('status', ['active', 'inactive'])->default('active');
            $t->boolean('must_change_password')->default(false);
            $t->integer('failed_login_count')->default(0);
            $t->dateTime('locked_until')->nullable();
            $t->dateTime('last_login_at')->nullable();
            $t->string('last_login_ip', 45)->nullable();
            $t->dateTime('password_changed_at')->nullable();
            $t->dateTime('deactivated_at')->nullable();
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique('email');
            $t->index('status');
            $t->comment('Application users. Authentication state and lockout counters.');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('users');
    }
};
