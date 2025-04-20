<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Examiner extends Model
{
    /** @use HasFactory<\Database\Factories\ExaminerFactory> */
    use HasFactory;

    protected $fillable = ['name', 'email', 'description'];

    public function questionPapers(): HasMany
    {
        return $this->hasMany(QuestionPaper::class);
    }
}
