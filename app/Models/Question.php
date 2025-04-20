<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Question extends Model
{
    use HasFactory;

    protected $fillable = [
        'question_paper_id', 'parent_id', 'question_number',
        'content', 'level', 'marks'
    ];

    public function questionPaper(): BelongsTo
    {
        return $this->belongsTo(QuestionPaper::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'parent_id');
    }

    public function subQuestions(): HasMany
    {
        return $this->hasMany(Question::class, 'parent_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable');
    }
}