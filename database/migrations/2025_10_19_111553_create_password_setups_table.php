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
        Schema::create('password_setups', function (Blueprint $table) {
            $table->uuid('uuid')->primary()->comment('The unique id of the record');
            $table->string('email', 140)->index()->comment('The email address of the user');
            $table->string('type', 50)->nullable()->comment('The type of the password setup of the user');
            $table->timestamp('confirmed_at')->comment('The timestamp where the email address has been confirmed.')->nullable();
            $table->timestamp('expires_at')->nullable()->comment('The timestamp where the transaction will be expired.');
            $table->timestamps();
            $table->index(['created_at', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_setups');
    }
};
