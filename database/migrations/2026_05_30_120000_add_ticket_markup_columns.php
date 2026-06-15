<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_tickets', function (Blueprint $table) {
            $table->string('markup_type')->nullable()->after('price');
            $table->decimal('markup_value', 8, 2)->nullable()->after('markup_type');
        });

        foreach (['temp_transactions', 'transactions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('markup_type')->nullable()->after('sub_total');
                $table->decimal('markup_value', 8, 2)->nullable()->after('markup_type');
                $table->decimal('markup_amount', 12, 2)->default(0)->after('markup_value');
                $table->decimal('markup_discount', 12, 2)->default(0)->after('markup_amount');
            });
        }

        foreach (['temp_transaction_orders', 'transaction_orders'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('markup_type')->nullable()->after('price');
                $table->decimal('markup_value', 8, 2)->nullable()->after('markup_type');
                $table->decimal('markup', 12, 2)->default(0)->after('markup_value');
                $table->decimal('markup_discount', 12, 2)->default(0)->after('markup');
            });
        }

        Schema::table('transaction_compliances', function (Blueprint $table) {
            $table->string('applies_to')->default('merchandise')->after('amount');

            $table->dropUnique('txn_compliances_txn_rule_unique');
            $table->unique(
                ['transaction_uuid', 'activity_compliance_uuid', 'applies_to'],
                'txn_compliances_txn_rule_applies_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('transaction_compliances', function (Blueprint $table) {
            $table->dropUnique('txn_compliances_txn_rule_applies_unique');
            $table->unique(
                ['transaction_uuid', 'activity_compliance_uuid'],
                'txn_compliances_txn_rule_unique',
            );
            $table->dropColumn('applies_to');
        });

        foreach (['temp_transaction_orders', 'transaction_orders'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['markup_type', 'markup_value', 'markup', 'markup_discount']);
            });
        }

        foreach (['temp_transactions', 'transactions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['markup_type', 'markup_value', 'markup_amount', 'markup_discount']);
            });
        }

        Schema::table('event_tickets', function (Blueprint $table) {
            $table->dropColumn(['markup_type', 'markup_value']);
        });
    }
};
