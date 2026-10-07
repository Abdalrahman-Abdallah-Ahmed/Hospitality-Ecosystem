<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge documents (SPEC-008): the table shipped in August with no model
 * behind it. This adds what the pipeline needs:
 *
 * - `segments`: the located text (page, sheet, section) the index is built
 *   from, so rebuilds and staff corrections never re-read the file.
 * - `content_source` / `corrected_*`: whether that text is a staff correction.
 * - `failure_code`: why indexing failed, as a translatable code.
 * - `pending_*`: a replacement file waiting to be indexed. The current file
 *   and its passages stay live until the replacement's swap commits.
 * - `index_fingerprint`: the input the live passages were built from, so a
 *   stale run can tell it has been superseded.
 *
 * `content_hash` stays nullable for any row that predates this migration;
 * every document created from now on has one. The partial unique indexes
 * treat nulls as distinct, so such rows never block an upload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->char('content_hash', 64)->nullable();
            $table->jsonb('segments')->nullable();
            $table->string('content_source', 16)->default('extracted');
            $table->foreignUuid('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('corrected_at')->nullable();
            $table->string('failure_code', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedInteger('scanned_page_count')->default(0);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->char('index_fingerprint', 64)->nullable();
            $table->timestampTz('indexed_at')->nullable();
            $table->string('pending_path')->nullable();
            $table->string('pending_original_filename')->nullable();
            $table->string('pending_mime_type')->nullable();
            $table->unsignedBigInteger('pending_size')->nullable();
            $table->char('pending_content_hash', 64)->nullable();

            $table->index(['hotel_id', 'status']);
            $table->index(['hotel_id', 'is_active']);
            $table->index('deleted_at');
        });

        DB::statement("ALTER TABLE knowledge_documents ALTER COLUMN status SET DEFAULT 'uploaded'");
        DB::table('knowledge_documents')->where('status', 'processing')->update(['status' => 'uploaded']);
        DB::table('knowledge_documents')->whereNotIn('status', ['uploaded', 'extracting', 'indexed', 'failed'])->update(['status' => 'uploaded']);
        DB::table('knowledge_documents')->where('status', 'failed')->whereNull('failure_code')->update(['failure_code' => 'corrupt']);

        DB::statement("ALTER TABLE knowledge_documents ADD CONSTRAINT knowledge_documents_status_check CHECK (status IN ('uploaded', 'extracting', 'indexed', 'failed'))");
        DB::statement("ALTER TABLE knowledge_documents ADD CONSTRAINT knowledge_documents_content_source_check CHECK (content_source IN ('extracted', 'corrected'))");
        DB::statement("ALTER TABLE knowledge_documents ADD CONSTRAINT knowledge_documents_failure_code_check CHECK ((status = 'failed') = (failure_code IS NOT NULL))");
        DB::statement("ALTER TABLE knowledge_documents ADD CONSTRAINT knowledge_documents_correction_check CHECK ((content_source = 'corrected') = (corrected_at IS NOT NULL))");

        DB::statement(
            'CREATE UNIQUE INDEX knowledge_documents_hotel_content_hash_unique ON knowledge_documents (hotel_id, content_hash) '
            .'WHERE deleted_at IS NULL AND hotel_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX knowledge_documents_global_content_hash_unique ON knowledge_documents (content_hash) '
            .'WHERE deleted_at IS NULL AND hotel_id IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS knowledge_documents_global_content_hash_unique');
        DB::statement('DROP INDEX IF EXISTS knowledge_documents_hotel_content_hash_unique');
        DB::statement('ALTER TABLE knowledge_documents DROP CONSTRAINT IF EXISTS knowledge_documents_correction_check');
        DB::statement('ALTER TABLE knowledge_documents DROP CONSTRAINT IF EXISTS knowledge_documents_failure_code_check');
        DB::statement('ALTER TABLE knowledge_documents DROP CONSTRAINT IF EXISTS knowledge_documents_content_source_check');
        DB::statement('ALTER TABLE knowledge_documents DROP CONSTRAINT IF EXISTS knowledge_documents_status_check');
        DB::statement("ALTER TABLE knowledge_documents ALTER COLUMN status SET DEFAULT 'processing'");

        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropIndex(['hotel_id', 'status']);
            $table->dropIndex(['hotel_id', 'is_active']);
            $table->dropIndex(['deleted_at']);
            $table->dropConstrainedForeignId('corrected_by');
            $table->dropColumn([
                'content_hash', 'segments', 'content_source', 'corrected_at', 'failure_code', 'is_active',
                'page_count', 'scanned_page_count', 'chunk_count', 'index_fingerprint', 'indexed_at',
                'pending_path', 'pending_original_filename', 'pending_mime_type', 'pending_size', 'pending_content_hash',
            ]);
        });
    }
};
