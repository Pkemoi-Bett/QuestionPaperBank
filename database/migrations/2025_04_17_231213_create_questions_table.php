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
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_paper_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('parent_id')->nullable(); // For sub-questions
            $table->string('question_number'); // E.g. "1", "1a", "1.1", etc.
            $table->longText('content'); // The question text
            $table->integer('marks')->nullable(); // How many marks the question is worth
            $table->enum('level', ['easy', 'medium', 'hard'])->nullable(); // Difficulty level
            $table->enum('answer_format', ['paragraph', 'points'])->default('paragraph'); // Preferred answer format
            $table->integer('order')->default(0); // For sorting questions
            $table->timestamps();
            
            // Foreign key constraint for self-referencing
            $table->foreign('parent_id')->references('id')->on('questions')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};