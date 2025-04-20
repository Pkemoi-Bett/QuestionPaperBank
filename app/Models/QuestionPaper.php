<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionPaper extends Model
{
    use HasFactory;

    protected $fillable = [
        'examiner_id', 'subject_id', 'class_id', 'curriculum_id',
        'term', 'year', 'paper_type', 'original_filename',
        'file_path', 'file_type', 'is_processed', 'metadata'
    ];

    protected $casts = [
        'is_processed' => 'boolean',
        'metadata' => 'array',
    ];

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(Examiner::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(Classes::class);
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }
}