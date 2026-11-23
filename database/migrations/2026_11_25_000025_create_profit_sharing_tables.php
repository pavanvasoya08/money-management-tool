<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

/**
 * Profit-sharing configuration (no profit is calculated in Phase 3).
 * An arrangement holds effective-dated allocations; each allocation lists
 * partner percentages. Percentages are independent of capital.
 */
return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('profit_sharing_arrangements', static function (Blueprint $t): void {
            $t->id();
            $t->string('code', 40);
            $t->string('name', 120);
            $t->string('description', 255)->nullable();
            $t->enum('allocation_type', ['complete', 'partial'])->default('complete')->comment('complete = partner percentages must total exactly 100%');
            $t->enum('calculation_method', ['net_profit', 'distributable_profit', 'gross_profit'])->default('net_profit');
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by', 'users', 'id', 'RESTRICT');
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique('code');
        });
        $schema->create('profit_sharing_allocations', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('arrangement_id', 'profit_sharing_arrangements', 'id', 'RESTRICT');
            $t->date('effective_from');
            $t->date('effective_to')->nullable()->comment('Inclusive; null = open-ended');
            $t->enum('status', ['active', 'inactive'])->default('active');
            $t->text('notes')->nullable();
            $t->foreignId('created_by', 'users', 'id', 'RESTRICT');
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->index(['arrangement_id', 'effective_from']);
        });
        $schema->create('profit_sharing_allocation_lines', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('allocation_id', 'profit_sharing_allocations', 'id', 'CASCADE');
            $t->foreignId('partner_id', 'partners', 'id', 'RESTRICT');
            $t->decimal('percentage', 7, 4);
            $t->unique(['allocation_id', 'partner_id']);
            $t->index('partner_id');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('profit_sharing_allocation_lines');
        $schema->drop('profit_sharing_allocations');
        $schema->drop('profit_sharing_arrangements');
    }
};
