<?php
namespace App\Http\Controllers;

use App\Models\QuestionPaper;
use App\Services\AnswerGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;


class AnswerController extends Controller
{
    protected $answerGenerator;
    
    public function __construct(AnswerGeneratorService $answerGenerator)
    {
        $this->answerGenerator = $answerGenerator;
    }
    
    /**
     * Generate answers for a question paper
     *
     * @param Request $request
     * @param int $id Question paper ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function generate(Request $request, int $id)
    {
        $regenerate = $request->input('regenerate', false);
        $provider = $request->input('provider', 'openai');
        
        // Set the AI provider if specified
        if ($provider) {
            $this->answerGenerator->setAIProvider($provider);
        }
        
        $result = $this->answerGenerator->generateAnswers($id, $regenerate);
        
        if (isset($result['success']) && $result['success']) {
            return response()->json($result);
        }
        
        return response()->json($result, 500);
    }
    
    /**
     * Generate and download answers as PDF
     *
     * @param int $id Question paper ID
     * @return \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function download(int $id)
    {
        $result = $this->answerGenerator->generatePDF($id);
        
        if (isset($result['success']) && $result['success']) {
            $path = 'public/answers/' . $result['filename'];
            
            // Check if file exists
            if (Storage::exists($path)) {
                $file = Storage::path($path);
                
                // Get question paper details for filename
                $paper = QuestionPaper::with(['subject', 'class'])->find($id);
                $subject = $paper->subject->name ?? 'Unknown';
                $class = $paper->class->name ?? '';
                $year = $paper->year ?? date('Y');
                
                $downloadName = Str::slug("{$subject} {$class} {$year} Answers") . '.pdf';
                
                return response()->download($file, $downloadName);
            }
            
            return response()->json(['error' => 'PDF file not found'], 404);
        }
        
        return response()->json($result, 500);
    }
    
    /**
     * Check if answers are available for a question paper
     *
     * @param int $id Question paper ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkAvailability(int $id)
    {
        try {
            $paper = QuestionPaper::with(['questions.answer'])->findOrFail($id);
            
            // Count questions with answers
            $totalQuestions = $paper->questions->count();
            $answeredQuestions = $paper->questions->filter(function ($q) {
                return $q->answer !== null;
            })->count();
            
            return response()->json([
                'success' => true,
                'total_questions' => $totalQuestions,
                'answered_questions' => $answeredQuestions,
                'has_answers' => $answeredQuestions > 0,
                'is_complete' => $totalQuestions > 0 && $answeredQuestions == $totalQuestions,
                'completion_percentage' => $totalQuestions > 0 ? 
                    round(($answeredQuestions / $totalQuestions) * 100) : 0
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check answer availability: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * View generated answers
     *
     * @param int $id Question paper ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function view(int $id)
    {
        try {
            $result = $this->answerGenerator->generateAnswers($id, false);
            
            if (isset($result['success']) && $result['success']) {
                return response()->json($result);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'No answers available for this question paper'
            ], 404);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve answers: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Delete generated answers for a question paper
     *
     * @param int $id Question paper ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete(int $id)
    {
        try {
            $paper = QuestionPaper::with(['questions.answer'])->findOrFail($id);
            
            // Delete all answers associated with this paper's questions
            foreach ($paper->questions as $question) {
                if ($question->answer) {
                    $question->answer->delete();
                }
            }
            
            // Delete any generated PDF files
            $files = Storage::files('public/answers');
            foreach ($files as $file) {
                if (strpos($file, 'answers_' . $id . '_') !== false) {
                    Storage::delete($file);
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Answers deleted successfully'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete answers: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Set the AI provider for answer generation
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function setProvider(Request $request)
    {
        $provider = $request->input('provider');
        
        if (!in_array($provider, ['openai', 'deepseek'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid AI provider specified'
            ], 400);
        }
        
        try {
            $this->answerGenerator->setAIProvider($provider);
            
            return response()->json([
                'success' => true,
                'message' => 'AI provider set to ' . $provider
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to set AI provider: ' . $e->getMessage()
            ], 500);
        }
    }


    public function fetchPaperWithAnswers(int $id)
    {
        try {
            // Fetch the question paper with all related data
            $paper = QuestionPaper::with([
                'questions' => function($query) {
                    $query->orderBy('question_number');
                },
                'questions.answer',
                'questions.children',
                'questions.children.answer',
                'examiner',
                'subject',
                'class',
                'curriculum'
            ])->findOrFail($id);
            
            // Format the data for the response
            $formattedQuestions = [];
            
            foreach ($paper->questions->whereNull('parent_id') as $question) {
                $formattedQuestions[] = $this->formatQuestionWithAnswer($question);
            }
            
            return response()->json([
                'success' => true,
                'paper' => [
                    'id' => $paper->id,
                    'title' => $paper->title,
                    'examiner' => $paper->examiner ? $paper->examiner->name : null,
                    'subject' => $paper->subject ? $paper->subject->name : null,
                    'class' => $paper->class ? $paper->class->name : null,
                    'curriculum' => $paper->curriculum ? $paper->curriculum->name : null,
                    'year' => $paper->year,
                    'term' => $paper->term,
                    'paper_type' => $paper->paper_type,
                    'total_marks' => $paper->total_marks,
                    'duration' => $paper->duration,
                    'created_at' => $paper->created_at,
                    'updated_at' => $paper->updated_at,
                ],
                'questions' => $formattedQuestions,
                'has_answers' => count(array_filter($formattedQuestions, function($q) {
                    return isset($q['answer']) && !empty($q['answer']);
                })) > 0,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch question paper with answers: ' . $e->getMessage()
            ], 500);
        }
    }


    protected function formatQuestionWithAnswer($question): array
    {
        $formattedQuestion = [
            'id' => $question->id,
            'question_number' => $question->question_number,
            'content' => $question->content,
            'level' => $question->level,
            'marks' => $question->marks,
            'answer_format' => $question->answer_format,
        ];
        
        // Add answer if available
        if ($question->answer) {
            $answerContent = $question->answer->content;
            
            // Parse JSON content for points-type answers
            if ($question->answer->format === 'points' && $this->isJson($answerContent)) {
                $answerContent = json_decode($answerContent, true);
            }
            
            $formattedQuestion['answer'] = $answerContent;
            $formattedQuestion['answer_format'] = $question->answer->format;
        }
        
        // Process sub-questions recursively
        if ($question->children && $question->children->count() > 0) {
            $formattedQuestion['sub_questions'] = [];
            foreach ($question->children as $subQuestion) {
                $formattedQuestion['sub_questions'][] = $this->formatQuestionWithAnswer($subQuestion);
            }
        }
        
        return $formattedQuestion;
    }


    protected function isJson(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}