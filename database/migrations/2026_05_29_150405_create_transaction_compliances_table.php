<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_compliances', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('transaction_uuid')->index();
            $table->uuid('activity_compliance_uuid')->index();
            $table->decimal('percentage', 5, 2)->default(0);
            $table->decimal('amount', 12, 4)->default(0);
            $table->string('applies_to')->default('merchandise');

            $table->unique(
                ['transaction_uuid', 'activity_compliance_uuid', 'applies_to'],
                'txn_compliances_txn_rule_applies_unique',
            );
            $table->timestamps();

            $table->foreign('transaction_uuid')
                ->references('uuid')
                ->on('transactions')
                ->cascadeOnDelete();

            $table->foreign('activity_compliance_uuid')
                ->references('uuid')
                ->on('activity_compliances')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_compliances');
    }
};
