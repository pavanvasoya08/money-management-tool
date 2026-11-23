<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('activity_logs', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id', 'users', 'id', 'SET NULL')->nullable();
            $t->string('action', 100)->comment('e.g. auth.login, user.created');
            $t->string('description', 500);
            $t->string('subject_type', 100)->nullable()->comment('Affected record type, e.g. user');
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('properties')->nullable()->comment('Extra context; secrets are redacted');
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 500)->nullable();
            $t->dateTime('created_at');
            $t->index('action');
            $t->index(['user_id', 'created_at']);
            $t->index(['subject_type', 'subject_id']);
            $t->index('created_at');
            $t->comment('Append-only audit trail. The application never updates or deletes rows.');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('activity_logs');
    }
};
