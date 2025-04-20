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
            $table->foreignId('parent_id')->nullable()->constrained('questions')->nullOnDelete();
            $table->integer('question_number');
            $table->text('content');
            $table->integer('level')->default(1); // 1 for main question, 2 for sub-question, 3 for sub-sub-question
            $table->float('marks')->nullable();
            $table->timestamps();
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
