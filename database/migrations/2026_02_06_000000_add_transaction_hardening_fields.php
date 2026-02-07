<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds manual review tracking, currency exchange, GoCardless identifiers to transactions,
     * creates gocardless_sync_failures table, and adds bank identity fields to accounts.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'needs_manual_review')) {
                $table->boolean('needs_manual_review')->default(false)->after('is_reconciled');
            }
            if (! Schema::hasColumn('transactions', 'review_reason')) {
                $table->string('review_reason')->nullable()->after('needs_manual_review');
            }
            if (! Schema::hasColumn('transactions', 'exchange_rate')) {
                $table->decimal('exchange_rate', 12, 6)->nullable()->after('original_currency');
            }
            if (! Schema::hasColumn('transactions', 'internal_transaction_id')) {
                $table->string('internal_transaction_id')->nullable()->after('transaction_id');
            }
            if (! Schema::hasColumn('transactions', 'entry_reference')) {
                $table->string('entry_reference')->nullable()->after('internal_transaction_id');
            }
            if (! Schema::hasColumn('transactions', 'bank_transaction_code')) {
                $table->string('bank_transaction_code')->nullable()->after('type');
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'needs_manual_review')) {
                $table->index(['account_id', 'needs_manual_review'], 'idx_transactions_review');
            }
        });

        if (! Schema::hasTable('gocardless_sync_failures')) {
            Schema::create('gocardless_sync_failures', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->onDelete('cascade');
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->string('external_transaction_id')->nullable();
                $table->string('error_type');
                $table->string('error_code')->nullable();
                $table->text('error_message');
                $table->json('raw_data');
                $table->json('validation_errors')->nullable();
                $table->integer('retry_count')->default(0);
                $table->timestamp('last_retry_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->string('resolution')->nullable();
                $table->timestamps();
                $table->index(['account_id', 'resolved_at']);
            });
        }

        Schema::table('accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts', 'gocardless_institution_id')) {
                $table->string('gocardless_institution_id')->nullable()->after('gocardless_account_id');
            }
            if (! Schema::hasColumn('accounts', 'bic')) {
                $table->string('bic', 11)->nullable()->after('gocardless_institution_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $columns = [
                'needs_manual_review', 'review_reason', 'exchange_rate',
                'internal_transaction_id', 'entry_reference', 'bank_transaction_code',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('transactions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('gocardless_sync_failures');

        Schema::table('accounts', function (Blueprint $table) {
            if (Schema::hasColumn('accounts', 'gocardless_institution_id')) {
                $table->dropColumn('gocardless_institution_id');
            }
            if (Schema::hasColumn('accounts', 'bic')) {
                $table->dropColumn('bic');
            }
        });
    }
};
