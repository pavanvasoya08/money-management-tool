<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('system_settings', static function (Blueprint $t): void {
            $t->id();
            $t->string('setting_key', 100);
            $t->text('value')->nullable();
            $t->enum('type', ['string', 'integer', 'boolean', 'json'])->default('string');
            $t->string('group_name', 50)->default('general');
            $t->string('description', 255)->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique('setting_key');
            $t->index('group_name');
            $t->comment('Key/value system configuration editable from Settings.');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('system_settings');
    }
};
