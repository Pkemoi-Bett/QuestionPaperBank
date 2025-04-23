<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionPaper extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'examiner_id',
        'subject_id',
        'class_id',
        'curriculum_id',
        'term',
        'year',
        'paper_type',
        'title',
        'original_filename',
        'file_path',
        'file_type',
        'is_processed',
        'has_answers',
        'total_marks',
        'duration',
        'metadata',
        'answers_generated_at',
        'answers_pdf_path',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'is_processed' => 'boolean',
        'has_answers' => 'boolean',
        'answers_generated_at' => 'datetime',
        'year' => 'integer',
        'term' => 'integer',
        'total_marks' => 'integer',
        'duration' => 'integer',
    ];

    /**
     * Get the examiner that created the question paper.
     */
    public function examiner(): BelongsTo
    {
        return $this->belongsTo(Examiner::class);
    }

    /**
     * Get the subject of the question paper.
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }


    /**
     * Get the curriculum of the question paper.
     */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    /**
     * Get the questions for the question paper.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->whereNull('parent_id');
    }

    /**
     * Get all questions including sub-questions.
     */
    public function allQuestions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    /**
     * Calculate the total number of answers generated.
     * 
     * @return int
     */
    public function getAnswerCountAttribute(): int
    {
        return $this->allQuestions()
            ->whereHas('answer')
            ->count();
    }

    /**
     * Calculate the completion percentage of answers.
     * 
     * @return float
     */
    public function getAnswerCompletionPercentageAttribute(): float
    {
        $totalQuestions = $this->allQuestions()->count();
        
        if (!$totalQuestions) {
            return 0;
        }
        
        $answeredQuestions = $this->answer_count;
        
        return round(($answeredQuestions / $totalQuestions) * 100, 1);
    }

    /**
     * Check if all questions have answers.
     * 
     * @return bool
     */
    public function getIsCompleteAttribute(): bool
    {
        $totalQuestions = $this->allQuestions()->count();
        
        if (!$totalQuestions) {
            return false;
        }
        
        return $this->answer_count === $totalQuestions;
    }

    /**
     * Update the has_answers flag based on answer existence.
     * 
     * @return void
     */
    public function updateHasAnswersFlag(): void
    {
        $hasAnswers = $this->answer_count > 0;
        
        if ($this->has_answers !== $hasAnswers) {
            $this->has_answers = $hasAnswers;
            $this->save();
        }
    }
}