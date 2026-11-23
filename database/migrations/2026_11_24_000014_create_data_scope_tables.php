<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    private const SCOPES = ['all', 'own', 'assigned', 'selected', 'selected_partners', 'selected_gst_registrations', 'selected_bank_accounts', 'selected_platforms', 'none'];

    public function up(Schema $schema, Database $db): void
    {
        $schema->create('role_data_scopes', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('role_id', 'roles', 'id', 'CASCADE');
            $t->foreignId('module_id', 'modules', 'id', 'CASCADE');
            $t->enum('scope', self::SCOPES);
            $t->json('resource_ids')->nullable()->comment('IDs for selected* scopes. Empty = use the user\'s resource assignments');
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique(['role_id', 'module_id']);
            $t->index('module_id');
            $t->comment('Record-level visibility per role and module.');
        });
        $schema->create('user_data_scope_overrides', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id', 'users', 'id', 'CASCADE');
            $t->foreignId('module_id', 'modules', 'id', 'CASCADE');
            $t->enum('scope', self::SCOPES);
            $t->json('resource_ids')->nullable();
            $t->string('reason', 255)->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'module_id']);
            $t->index('module_id');
            $t->comment('User-specific record visibility. No row = inherit from roles.');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('user_data_scope_overrides');
        $schema->drop('role_data_scopes');
    }
};
