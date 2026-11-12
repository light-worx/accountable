<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tables that may only ever be INSERTed into. */
    private const APPEND_ONLY = ['transactions', 'ledger_lines', 'period_events', 'transaction_attachments'];

    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('seq');                // 1,2,3... per entity, gapless
            $table->string('type', 20);
            $table->date('date');
            $table->string('reference', 20);                  // R-000001, P-000001, T-000001
            $table->string('description')->nullable();
            $table->foreignId('payee_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reverses_transaction_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->char('prev_hash', 64)->nullable();        // hash chain: tamper evidence
            $table->char('hash', 64);
            $table->unique(['entity_id', 'seq']);
            $table->unique(['entity_id', 'reference']);
            $table->index(['entity_id', 'date']);
        });

        // Every line moves money in or out of one money account AND one fund by the
        // same amount (signed: + in, - out), so accounts and funds can never disagree.
        Schema::create('ledger_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('entity_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('money_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->foreignId('fund_id')->constrained('funds')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('memo')->nullable();
            $table->index(['entity_id', 'money_account_id']);
            $table->index(['entity_id', 'fund_id']);
            $table->index(['entity_id', 'category_id']);
        });

        Schema::create('transaction_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->char('sha256', 64)->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });

        // Month-end close / reopen history. Current state = latest event for the month.
        Schema::create('period_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('action', 10);                     // close | reopen
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['entity_id', 'year', 'month']);
        });

        // Counters for gapless receipt / payment numbers (uniqueness is enforced on transactions).
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            $table->string('key', 10);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->primary(['entity_id', 'key']);
        });

        foreach (self::APPEND_ONLY as $tableName) {
            $this->makeAppendOnly($tableName);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('period_events');
        Schema::dropIfExists('transaction_attachments');
        Schema::dropIfExists('ledger_lines');
        Schema::dropIfExists('transactions');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS ledger_append_only() CASCADE');
        }
    }

    /**
     * Block UPDATE and DELETE at database level. Even someone with database access
     * through the application's own credentials cannot quietly edit a posted figure.
     * (For production, ALSO give the app's DB user no UPDATE/DELETE rights on these tables.)
     */
    private function makeAppendOnly(string $table): void
    {
        $message = 'Ledger records are append-only';

        switch (DB::getDriverName()) {
            case 'sqlite':
                foreach (['UPDATE', 'DELETE'] as $op) {
                    $name = $table . '_no_' . strtolower($op);
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$op} ON {$table} BEGIN SELECT RAISE(ABORT, '{$message}'); END;");
                }
                break;

            case 'mysql':
            case 'mariadb':
                foreach (['UPDATE', 'DELETE'] as $op) {
                    $name = $table . '_no_' . strtolower($op);
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$op} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'");
                }
                break;

            case 'pgsql':
                DB::unprepared("CREATE OR REPLACE FUNCTION ledger_append_only() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION '{$message}'; END; \$\$ LANGUAGE plpgsql;");
                DB::unprepared("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ledger_append_only();");
                break;

            default:
                throw new RuntimeException('Unsupported database driver for append-only triggers: ' . DB::getDriverName());
        }
    }
};
