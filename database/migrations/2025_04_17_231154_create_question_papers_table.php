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
        Schema::create('question_papers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examiner_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subject_id')->constrained()->onDelete('cascade');
            $table->foreignId('class_id')->constrained()->onDelete('cascade');
            $table->foreignId('curriculum_id')->constrained('curriculums')->onDelete('cascade');
            $table->integer('term')->nullable();
            $table->integer('year');
            $table->string('paper_type')->nullable(); // Paper 1, Paper 2
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('file_type');
            $table->boolean('is_processed')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_papers');
    }
};
