<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    private const COLUMNS = ['suspended_at', 'suspended_until', 'suspension_reason', 'suspended_by', 'account_expires_at', 'allowed_ips', 'access_start_time', 'access_end_time', 'deleted_at', 'deleted_by'];

    public function up(Schema $schema, Database $db): void
    {
        $schema->table('users', static function (Blueprint $t): void {
            $t->dateTime('suspended_at')->nullable();
            $t->dateTime('suspended_until')->nullable()->comment('NULL = suspended until lifted');
            $t->string('suspension_reason', 255)->nullable();
            $t->foreignId('suspended_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('account_expires_at')->nullable()->comment('Sign-in refused after this moment');
            $t->text('allowed_ips')->nullable()->comment('Comma-separated IPs/CIDR ranges; empty = any');
            $t->string('access_start_time', 5)->nullable()->comment('HH:MM in display timezone');
            $t->string('access_end_time', 5)->nullable();
            $t->dateTime('deleted_at')->nullable()->comment('Soft delete — history is preserved');
            $t->foreignId('deleted_by', 'users', 'id', 'SET NULL')->nullable();
            $t->index('deleted_at');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->dropColumns('users', self::COLUMNS, ['fk_users_suspended_by', 'fk_users_deleted_by'], ['ix_users_deleted_at']);
    }
};
