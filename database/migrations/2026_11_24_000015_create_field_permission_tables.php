<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    private const ACCESS = ['visible', 'hidden', 'masked', 'read_only', 'editable'];

    public function up(Schema $schema, Database $db): void
    {
        $schema->create('module_fields', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('module_id', 'modules', 'id', 'CASCADE');
            $t->string('field_key', 60);
            $t->string('label', 100);
            $t->boolean('is_sensitive')->default(false);
            $t->enum('mask_type', ['full', 'last4', 'email', 'phone', 'amount'])->default('full');
            $t->enum('default_access', self::ACCESS)->default('editable')->comment('Applies when no role/user rule exists');
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['module_id', 'field_key']);
            $t->comment('Catalogue of protectable fields per module.');
        });
        $schema->create('role_field_permissions', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('role_id', 'roles', 'id', 'CASCADE');
            $t->foreignId('field_id', 'module_fields', 'id', 'CASCADE');
            $t->enum('access', self::ACCESS);
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique(['role_id', 'field_id']);
            $t->index('field_id');
        });
        $schema->create('user_field_overrides', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id', 'users', 'id', 'CASCADE');
            $t->foreignId('field_id', 'module_fields', 'id', 'CASCADE');
            $t->enum('access', self::ACCESS);
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'field_id']);
            $t->index('field_id');
            $t->comment('User-specific field access. No row = inherit from roles.');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('user_field_overrides');
        $schema->drop('role_field_permissions');
        $schema->drop('module_fields');
    }
};
