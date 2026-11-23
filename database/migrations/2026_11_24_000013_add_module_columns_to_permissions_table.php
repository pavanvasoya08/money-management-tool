<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->table('permissions', static function (Blueprint $t): void {
            $t->foreignId('module_id', 'modules', 'id', 'CASCADE')->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->index('module_id');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->dropColumns('permissions', ['module_id', 'sort_order', 'is_active'], ['fk_permissions_module_id'], ['ix_permissions_module_id']);
    }
};
