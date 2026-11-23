<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('modules', static function (Blueprint $t): void {
            $t->id();
            $t->string('module_key', 60)->comment('Stable key used in permission slugs, e.g. bank_accounts');
            $t->string('name', 100);
            $t->string('description', 255)->nullable();
            $t->string('icon', 40)->nullable();
            $t->integer('sort_order')->default(0);
            $t->integer('phase')->default(1)->comment('Roadmap phase that delivers the module screens');
            $t->boolean('is_active')->default(true)->comment('Super Admin can disable a module for everyone except Super Admins');
            $t->boolean('super_admin_only')->default(false)->comment('Cannot be delegated to other roles');
            $t->timestamps();
            $t->unique('module_key');
            $t->comment('ERP module registry.');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('modules');
    }
};
