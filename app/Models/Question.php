<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'question_paper_id',
        'parent_id',
        'question_number',
        'content',
        'marks',
        'level',
        'answer_format',
        'order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'marks' => 'integer',
        'order' => 'integer',
    ];

    /**
     * Get the question paper that owns the question.
     */
    public function questionPaper(): BelongsTo
    {
        return $this->belongsTo(QuestionPaper::class);
    }

    /**
     * Get the parent question.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'parent_id');
    }

    /**
     * Get the child questions.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Question::class, 'parent_id')->orderBy('order')->orderBy('question_number');
    }

    /**
     * Get the answer associated with the question.
     */
    public function answer(): HasOne
    {
        return $this->hasOne(Answer::class);
    }

    /**
     * Determine if this is a parent question (i.e., has sub-questions).
     * 
     * @return bool
     */
    public function getIsParentAttribute(): bool
    {
        return $this->children()->exists();
    }

    /**
     * Determine if this is a child question (i.e., has a parent).
     * 
     * @return bool
     */
    public function getIsChildAttribute(): bool
    {
        return !is_null($this->parent_id);
    }

    /**
     * Get the complete hierarchical path of question numbers.
     * 
     * @return string
     */
    public function getFullQuestionNumberAttribute(): string
    {
        if ($this->is_child) {
            $parent = $this->parent;
            return $parent->question_number . '.' . $this->question_number;
        }
        
        return $this->question_number;
    }

    /**
     * Calculate the total marks for this question, including sub-questions.
     * 
     * @return int
     */
    public function getTotalMarksAttribute(): int
    {
        $marks = $this->marks ?? 0;
        
        // Add marks from sub-questions
        if ($this->is_parent) {
            foreach ($this->children as $subQuestion) {
                $marks += $subQuestion->marks ?? 0;
            }
        }
        
        return $marks;
    }

    /**
     * Generate a prompt that can be used for AI answer generation.
     * 
     * @return string
     */
    public function generateAIPrompt(): string
    {
        $prompt = "Question {$this->question_number}: {$this->content}\n";
        $prompt .= "Answer format: {$this->answer_format}\n";
        
        if ($this->marks) {
            $prompt .= "Marks: {$this->marks}\n";
        }
        
        if ($this->level) {
            $prompt .= "Difficulty: {$this->level}\n";
        }
        
        return $prompt;
    }
}