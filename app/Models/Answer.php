<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Answer extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'question_id',
        'content',
        'format',
        'generated_by',
        'metadata',
        'is_verified',
        'generated_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'is_verified' => 'boolean',
        'generated_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['formatted_content'];

    /**
     * Get the question that owns the answer.
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * Get the formatted content of the answer.
     * 
     * @return mixed
     */
    public function getFormattedContentAttribute()
    {
        // If format is points and content is JSON, decode it
        if ($this->format === 'points' && $this->isJson($this->content)) {
            return json_decode($this->content, true);
        }
        
        return $this->content;
    }

    /**
     * Set the content attribute with automatic formatting.
     * 
     * @param mixed $value
     * @return void
     */
    public function setContentAttribute($value): void
    {
        // If value is an array and format is points, encode as JSON
        if (is_array($value) && $this->format === 'points') {
            $this->attributes['content'] = json_encode($value);
        } else {
            $this->attributes['content'] = $value;
        }
    }

    /**
     * Check if the answer is complete.
     * 
     * @return bool
     */
    public function getIsCompleteAttribute(): bool
    {
        if (empty($this->content)) {
            return false;
        }
        
        if ($this->format === 'points') {
            $content = $this->formatted_content;
            return is_array($content) && count($content) > 0;
        }
        
        return strlen(trim($this->content)) > 0;
    }

    /**
     * Get the question paper associated with this answer.
     * 
     * @return QuestionPaper|null
     */
    public function getQuestionPaperAttribute(): ?QuestionPaper
    {
        return $this->question ? $this->question->questionPaper : null;
    }

    /**
     * Check if a string is valid JSON.
     * 
     * @param string $string
     * @return bool
     */
    protected function isJson(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Boot the model.
     * 
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        // Set generated_at if not set
        static::creating(function ($answer) {
            if (empty($answer->generated_at)) {
                $answer->generated_at = now();
            }
        });

        // Update the question paper's has_answers flag when an answer is created or deleted
        static::saved(function ($answer) {
            if ($answer->question && $answer->question->questionPaper) {
                $answer->question->questionPaper->updateHasAnswersFlag();
            }
        });

        static::deleted(function ($answer) {
            if ($answer->question && $answer->question->questionPaper) {
                $answer->question->questionPaper->updateHasAnswersFlag();
            }
        });
    }
}