<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

/**
 * Help & User Guide. Published guides are readable by every signed-in user;
 * managing them uses the existing `documentation.*` permissions.
 * Screenshots are private files served only through HelpController.
 */
return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('documentation_categories', static function (Blueprint $t): void {
            $t->id();
            $t->string('slug', 120);
            $t->string('name', 150);
            $t->string('description', 500)->nullable();
            $t->string('icon', 60)->nullable()->comment('Lucide icon name');
            $t->integer('sort_order')->default(0);
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique('slug');
            $t->index('sort_order');
        });

        $schema->create('documentation_guides', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('category_id', 'documentation_categories', 'id', 'RESTRICT');
            $t->string('slug', 150);
            $t->string('title', 200);
            $t->string('section', 120)->nullable()->comment('Sub-heading inside the category, e.g. Authentication');
            $t->string('summary', 600)->comment('Short introduction');
            $t->text('what_is')->nullable();
            $t->text('why_used')->nullable();
            $t->text('who_uses')->nullable();
            $t->text('prerequisites')->nullable();
            $t->string('example_title', 200)->nullable();
            $t->text('example_body')->nullable();
            $t->text('expected_result')->nullable();
            $t->text('important_notes')->nullable();
            $t->text('common_mistakes')->nullable();
            $t->string('keywords', 500)->nullable()->comment('Extra search terms');
            $t->integer('sort_order')->default(0);
            $t->enum('status', ['draft', 'published'])->default('draft');
            $t->boolean('is_featured')->default(false)->comment('Listed under Step-by-step tutorials on the Help home page');
            $t->unsignedInteger('view_count')->default(0);
            $t->dateTime('published_at')->nullable();
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique('slug');
            $t->index(['category_id', 'status', 'sort_order']);
            $t->index(['status', 'updated_at']);
        });

        $schema->create('documentation_media', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('guide_id', 'documentation_guides', 'id', 'CASCADE');
            $t->string('stored_name', 160)->comment('Random name (uploads) or bundled:<file> (shipped screenshots, may be shared by several guides)');
            $t->string('original_name', 200);
            $t->string('mime_type', 60);
            $t->unsignedBigInteger('size_bytes');
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->string('caption', 300)->nullable();
            $t->string('alt_text', 300)->nullable();
            $t->json('annotations')->nullable()->comment('[{x, y, label}] in percent of the image; numbered in order');
            $t->integer('sort_order')->default(0);
            $t->foreignId('uploaded_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('created_at');
            $t->index(['guide_id', 'sort_order']);
        });

        $schema->create('documentation_steps', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('guide_id', 'documentation_guides', 'id', 'CASCADE');
            $t->integer('sort_order')->default(0);
            $t->string('title', 200);
            $t->text('body')->nullable();
            $t->foreignId('media_id', 'documentation_media', 'id', 'SET NULL')->nullable();
            $t->index(['guide_id', 'sort_order']);
        });

        $schema->create('documentation_faqs', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('guide_id', 'documentation_guides', 'id', 'CASCADE')->nullable()->comment('NULL = general FAQ shown on the Help home page');
            $t->string('question', 300);
            $t->text('answer');
            $t->integer('sort_order')->default(0);
            $t->index(['guide_id', 'sort_order']);
        });

        $schema->create('documentation_related_guides', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('guide_id', 'documentation_guides', 'id', 'CASCADE');
            $t->foreignId('related_guide_id', 'documentation_guides', 'id', 'CASCADE');
            $t->integer('sort_order')->default(0);
            $t->unique(['guide_id', 'related_guide_id']);
        });

        $schema->create('documentation_revisions', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('guide_id', 'documentation_guides', 'id', 'CASCADE');
            $t->unsignedInteger('revision_no');
            $t->string('event', 40)->comment('created | updated | published | unpublished | seeded');
            $t->string('change_note', 300)->nullable();
            $t->json('snapshot')->comment('Full guide content at this revision');
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('created_at');
            $t->unique(['guide_id', 'revision_no']);
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        foreach (['documentation_revisions', 'documentation_related_guides', 'documentation_faqs', 'documentation_steps', 'documentation_media', 'documentation_guides', 'documentation_categories'] as $table) {
            $schema->drop($table);
        }
    }
};
