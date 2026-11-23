<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('roles', static function (Blueprint $t): void {
            $t->id();
            $t->string('name', 100);
            $t->string('slug', 100);
            $t->string('description', 255)->nullable();
            $t->boolean('is_system')->default(false)->comment('Seeded role; cannot be deleted');
            $t->boolean('is_super_admin')->default(false)->comment('Bypasses all permission checks');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique('slug');
            $t->comment('Roles. Unlimited custom roles are supported (managed in Phase 2).');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('roles');
    }
};
