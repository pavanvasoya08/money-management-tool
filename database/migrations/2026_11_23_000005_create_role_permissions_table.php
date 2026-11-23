<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('role_permissions', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('role_id', 'roles', 'id', 'CASCADE');
            $t->foreignId('permission_id', 'permissions', 'id', 'CASCADE');
            $t->enum('data_scope', ['all', 'own', 'assigned', 'selected'])->default('all')
                ->comment('Record-level visibility for this grant');
            $t->json('constraints')->nullable()
                ->comment('Phase 2: e.g. {"gst_registration_ids":[1,2],"bank_account_ids":[3],"platform_ids":[],"partner_ids":[]}');
            $t->foreignId('granted_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique(['role_id', 'permission_id']);
            $t->index('permission_id');
            $t->comment('Role grants with data scope and GST/bank/platform/partner constraints.');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('role_permissions');
    }
};
