<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('import_failures', function (Blueprint $table) {
            $table->id();
            // No ->constrained() here: this migration is dated before
            // 2025_05_11_103545_create_imports_table, so on a fresh database the
            // `imports` table does not exist yet. SQLite tolerated the dangling
            // reference; PostgreSQL rejects it outright, which made a fresh pgsql
            // install impossible. The foreign key is added afterwards by
            // 2025_05_11_103546_add_import_failures_import_foreign_key.
            $table->foreignId('import_id');
            $table->integer('row_number')->nullable();
            $table->text('raw_data'); // JSON-encoded original CSV row data
            $table->string('error_type')->index(); // 'validation_failed', 'duplicate', 'processing_error'
            $table->text('error_message');
            $table->json('error_details')->nullable(); // Structured error information
            $table->json('parsed_data')->nullable(); // Partially parsed data if available
            $table->json('metadata')->nullable(); // Additional context like headers, processing context
            $table->string('status')->default('pending'); // 'pending', 'reviewed', 'resolved', 'ignored'
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['import_id', 'error_type']);
            $table->index(['import_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_failures');
    }
};
