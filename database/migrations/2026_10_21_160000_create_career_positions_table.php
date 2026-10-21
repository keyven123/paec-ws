<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_positions', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('title');
            $table->string('department')->nullable();
            $table->string('location')->nullable();
            $table->string('work_setup')->default('on-site');
            $table->string('employment_type')->default('full-time');
            $table->longText('description')->nullable();
            $table->longText('requirements')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'sort_order']);
        });

        Schema::create('career_applications', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignUuid('career_position_uuid')
                ->constrained('career_positions', 'uuid')
                ->cascadeOnDelete();
            $table->uuid('user_uuid')->nullable()->index();
            $table->string('full_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('salary_expectation')->nullable();
            $table->string('resume_url')->nullable();
            $table->string('resume_file_name')->nullable();
            $table->longText('cover_letter')->nullable();
            $table->string('status')->default('applied');
            $table->dateTime('interview_at')->nullable();
            $table->string('interview_location')->nullable();
            $table->timestamps();

            $table->unique(['career_position_uuid', 'user_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_applications');
        Schema::dropIfExists('career_positions');
    }
};
