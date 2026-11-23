<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('permissions', static function (Blueprint $t): void {
            $t->id();
            $t->string('module', 60)->comment('Module key, e.g. users, sales, gst_reports');
            $t->string('action', 60)->comment('view, create, edit, delete, approve, export, ...');
            $t->string('slug', 121)->comment('module.action');
            $t->string('name', 150);
            $t->string('description', 255)->nullable();
            $t->boolean('is_system')->default(true);
            $t->timestamps();
            $t->unique('slug');
            $t->unique(['module', 'action']);
            $t->index('module');
            $t->comment('Module + action level permission catalogue.');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('permissions');
    }
};
