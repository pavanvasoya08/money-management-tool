<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('approval_workflows', static function (Blueprint $t): void {
            $t->id();
            $t->string('workflow_key', 60);
            $t->string('name', 120);
            $t->string('description', 255)->nullable();
            $t->foreignId('module_id', 'modules', 'id', 'RESTRICT')->comment('Module whose create/approve/reject permissions apply');
            $t->boolean('is_active')->default(false)->comment('Inactive = requests are auto-approved');
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique('workflow_key');
        });
        $schema->create('approval_workflow_steps', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('workflow_id', 'approval_workflows', 'id', 'CASCADE');
            $t->integer('step_order');
            $t->string('name', 120);
            $t->enum('approver_type', ['role', 'user', 'permission']);
            $t->foreignId('approver_role_id', 'roles', 'id', 'RESTRICT')->nullable();
            $t->foreignId('approver_user_id', 'users', 'id', 'RESTRICT')->nullable();
            $t->decimal('min_amount', 15, 2)->default(0)->comment('Step applies when the request amount is at least this');
            $t->timestamps();
            $t->unique(['workflow_id', 'step_order']);
        });
        $schema->create('approval_requests', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('workflow_id', 'approval_workflows', 'id', 'RESTRICT');
            $t->string('subject_type', 60)->comment('e.g. expense, purchase, bank_transfer');
            $t->unsignedBigInteger('subject_id');
            $t->string('title', 200);
            $t->decimal('amount', 15, 2)->default(0);
            $t->foreignId('requested_by', 'users', 'id', 'RESTRICT');
            $t->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $t->integer('current_step')->nullable();
            $t->json('required_steps')->nullable()->comment('Snapshot of step orders required for this amount');
            $t->dateTime('submitted_at');
            $t->dateTime('completed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'workflow_id']);
            $t->index(['subject_type', 'subject_id']);
            $t->index('requested_by');
        });
        $schema->create('approval_actions', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('request_id', 'approval_requests', 'id', 'CASCADE');
            $t->integer('step_order')->nullable();
            $t->enum('action', ['submitted', 'approved', 'rejected', 'cancelled']);
            $t->foreignId('acted_by', 'users', 'id', 'RESTRICT');
            $t->string('comment', 500)->nullable()->comment('Rejection reason or approval note');
            $t->dateTime('created_at');
            $t->index('request_id');
            $t->comment('Immutable approval history.');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('approval_actions');
        $schema->drop('approval_requests');
        $schema->drop('approval_workflow_steps');
        $schema->drop('approval_workflows');
    }
};
