<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->table('activity_logs', static function (Blueprint $t): void {
            $t->string('module', 60)->nullable()->comment('Module key the change belongs to');
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->foreignId('affected_user_id', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('affected_role_id', 'roles', 'id', 'SET NULL')->nullable();
            $t->index(['module', 'created_at']);
            $t->index('affected_user_id');
            $t->index('affected_role_id');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->dropColumns(
            'activity_logs',
            ['module', 'old_values', 'new_values', 'affected_user_id', 'affected_role_id'],
            ['fk_activity_logs_affected_user_id', 'fk_activity_logs_affected_role_id'],
            ['ix_activity_logs_module_created_at', 'ix_activity_logs_affected_user_id', 'ix_activity_logs_affected_role_id']
        );
    }
};
