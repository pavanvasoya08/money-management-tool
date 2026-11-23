<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('permission_templates', static function (Blueprint $t): void {
            $t->id();
            $t->string('name', 100);
            $t->string('slug', 100);
            $t->string('description', 255)->nullable();
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique('slug');
        });
        $schema->create('permission_template_items', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('template_id', 'permission_templates', 'id', 'CASCADE');
            $t->foreignId('permission_id', 'permissions', 'id', 'CASCADE');
            $t->unique(['template_id', 'permission_id']);
            $t->index('permission_id');
        });
        $schema->create('permission_template_scopes', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('template_id', 'permission_templates', 'id', 'CASCADE');
            $t->foreignId('module_id', 'modules', 'id', 'CASCADE');
            $t->enum('scope', ['all', 'own', 'assigned', 'selected', 'selected_partners', 'selected_gst_registrations', 'selected_bank_accounts', 'selected_platforms', 'none']);
            $t->unique(['template_id', 'module_id']);
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('permission_template_scopes');
        $schema->drop('permission_template_items');
        $schema->drop('permission_templates');
    }
};
