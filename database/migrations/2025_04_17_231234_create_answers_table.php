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
        Schema::create('answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->onDelete('cascade');
            $table->longText('content'); // Using longText instead of text for potentially longer answers
            $table->enum('format', ['paragraph', 'points'])->default('paragraph'); // Store the format of the answer
            $table->string('generated_by')->default('openai'); // Track which AI provider generated the answer
            $table->json('metadata')->nullable(); // Store any additional metadata about the answer
            $table->boolean('is_verified')->default(false); // Allow for teacher verification of answers
            $table->timestamp('generated_at')->nullable(); // When the answer was generated
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('answers');
    }
};