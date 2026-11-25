<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

/**
 * Phase 5: a workflow may name the permission actions its approvers and rejecters need,
 * so one module can have separate approvers (e.g. bank transactions vs transfers).
 * NULL keeps the Phase 2 behaviour (module.approve / module.reject).
 */
return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->table('approval_workflows', static function (Blueprint $t): void {
            $t->string('approve_action', 60)->nullable()->comment('Permission action approvers need (default: approve)');
            $t->string('reject_action', 60)->nullable()->comment('Permission action rejecters need (default: reject)');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->dropColumns('approval_workflows', ['approve_action', 'reject_action']);
    }
};
