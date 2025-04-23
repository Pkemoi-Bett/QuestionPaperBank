<?php

namespace App\Http\Controllers;

use App\Models\QuestionPaper;
use App\Models\Subject;
use App\Models\Classes;
use App\Models\Curriculum;
use App\Services\DocumentProcessorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class QuestionPaperController extends Controller
{
    protected $documentProcessor;
    
    public function __construct(DocumentProcessorService $documentProcessor)
    {
        $this->documentProcessor = $documentProcessor;
    }
    
    /**
     * Display the upload form
     */
    public function index()
    {
        $recentPapers = QuestionPaper::with(['examiner', 'subject', 'class', 'curriculum'])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();
            
        return view('question_papers.index', compact('recentPapers'));
    }
    
    /**
     * Display the question paper details
     */
    public function show(QuestionPaper $questionPaper)
    {
        $questionPaper->load([
            'examiner', 
            'subject', 
            'class', 
            'curriculum',
            'questions' => function($query) {
                $query->whereNull('parent_id')->with(['subQuestions', 'answers']);
            }
        ]);
        
        return view('question_papers.show', compact('questionPaper'));
    }
    
    /**
     * Handle file upload
     */
    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:20480', // 20MB max
            'ai_provider' => 'nullable|in:openai,deepseek'
        ]);
        
        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }
        
        // Set AI provider if specified
        if ($request->filled('ai_provider')) {
            $this->documentProcessor->setAIProvider($request->ai_provider);
        }
        
        // Process the uploaded file
        $result = $this->documentProcessor->processUploadedFile($request->file('file'));
        
        if ($result['success'] ?? false) {
            return redirect()->back()->with('success', 'Files processed successfully!');
        } else {
            return redirect()->back()->with('error', $result['error'] ?? 'Failed to process file');
        }
    }
    
    /**
     * API endpoint for file upload
     */
    public function apiUpload(Request $request)
    {
        Log::info('apiUpload called.', ['request' => $request->all()]);
    
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:20480', // 20MB max
            'ai_provider' => 'nullable|in:openai,deepseek'
        ]);
    
        if ($validator->fails()) {
            Log::warning('Validation failed in apiUpload.', ['errors' => $validator->errors()]);
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        // Set AI provider if specified
        if ($request->filled('ai_provider')) {
            Log::info('AI provider set.', ['provider' => $request->ai_provider]);
            $this->documentProcessor->setAIProvider($request->ai_provider);
        }
    
        try {
            Log::info('Processing uploaded file.');
            $result = $this->documentProcessor->processUploadedFile($request->file('file'));
            Log::info('File processed successfully.', ['result' => $result]);
        } catch (\Exception $e) {
            Log::error('Error while processing file.', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to process file.',
                'error' => $e->getMessage()
            ], 500);
        }
    
        return response()->json($result);
    }
    /**
     * API endpoint to fetch processed question papers with their answers
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function apiFetchQuestionPapers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'nullable|integer|exists:question_papers,id',
            'subject_id' => 'nullable|integer|exists:subjects,id',
            'class_id' => 'nullable|integer|exists:classes,id',
            'curriculum_id' => 'nullable|integer|exists:curriculums,id',
            'year' => 'nullable|integer',
            'term' => 'nullable|integer|in:1,2,3',
            'paper_type' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:100',
            'with_answers' => 'nullable|boolean',
            'examiner_id' => 'nullable|integer|exists:examiners,id',
            'has_answers' => 'nullable|boolean', // New filter for papers with answers
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Build the query
        $query = QuestionPaper::with(['examiner', 'subject', 'class', 'curriculum']);
        
        // Apply filters if provided
        if ($request->filled('id')) {
            $query->where('id', $request->id);
        }
        
        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }
        
        if ($request->filled('class_id')) {
            $query->where('class_id', $request->class_id);
        }
        
        if ($request->filled('curriculum_id')) {
            $query->where('curriculum_id', $request->curriculum_id);
        }
        
        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }
        
        if ($request->filled('term')) {
            $query->where('term', $request->term);
        }
        
        if ($request->filled('paper_type')) {
            $query->where('paper_type', $request->paper_type);
        }
        
        if ($request->filled('examiner_id')) {
            $query->where('examiner_id', $request->examiner_id);
        }
        
        // Filter papers that have answers
        if ($request->filled('has_answers')) {
            $query->where('has_answers', $request->has_answers);
        }
        
        // Check if we should include questions and answers
        if ($request->filled('with_answers') && $request->with_answers) {
            $query->with([
                'questions' => function($q) {
                    $q->whereNull('parent_id')
                      ->orderBy('order')
                      ->orderBy('question_number')
                      ->with([
                        'answer', // Changed from 'answers' to 'answer' for the hasOne relationship
                        'children' => function($sq) { // Changed from 'subQuestions' to 'children' to match the relationship
                            $sq->orderBy('order')
                              ->orderBy('question_number')
                              ->with(['answer', 'children.answer']); // Similarly adjusted here
                        }
                    ]);
                }
            ]);
        } else {
            $query->with([
                'questions' => function($q) {
                    $q->whereNull('parent_id')
                      ->orderBy('order')
                      ->orderBy('question_number')
                      ->with([
                        'children' => function($sq) {
                            $sq->orderBy('order')
                              ->orderBy('question_number')
                              ->withCount('answer');
                        }
                    ])->withCount('answer');
                }
            ]);
        }
        
        // Set limit or use default
        $limit = $request->filled('limit') ? $request->limit : 10;
        
        // Get the results
        $questionPapers = $query->orderBy('created_at', 'desc')
                               ->paginate($limit);
        
        // Transform the data to include answer information
        $transformedData = $questionPapers->through(function($paper) use ($request) {
            $paperArray = $paper->toArray();
            
            // Calculate answer completion percentage
            if (isset($paperArray['questions']) && count($paperArray['questions']) > 0) {
                $totalQuestions = 0;
                $answeredQuestions = 0;
                
                foreach ($paperArray['questions'] as $question) {
                    $totalQuestions++;
                    if (isset($question['answer']) && $question['answer']) {
                        $answeredQuestions++;
                    }
                    
                    // Count sub-questions
                    if (isset($question['children']) && count($question['children']) > 0) {
                        foreach ($question['children'] as $subQuestion) {
                            $totalQuestions++;
                            if (isset($subQuestion['answer']) && $subQuestion['answer']) {
                                $answeredQuestions++;
                            }
                        }
                    }
                }
                
                $paperArray['answer_stats'] = [
                    'total_questions' => $totalQuestions,
                    'answered_questions' => $answeredQuestions,
                    'completion_percentage' => $totalQuestions > 0 ? 
                        round(($answeredQuestions / $totalQuestions) * 100) : 0,
                    'is_complete' => $totalQuestions > 0 && $answeredQuestions == $totalQuestions
                ];
            }
            
            return $paperArray;
        });
        
        return response()->json([
            'success' => true,
            'data' => $transformedData,
            'meta' => [
                'total' => $questionPapers->total(),
                'per_page' => $questionPapers->perPage(),
                'current_page' => $questionPapers->currentPage(),
                'last_page' => $questionPapers->lastPage(),
            ]
        ]);
    }
    
    /**
     * API endpoint to get available filters for question papers
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function apiGetFilters()
    {
        $subjects = Subject::select('id', 'name', 'code', 'curriculum_id')->get();
        $classes = Classes::select('id', 'name', 'curriculum_id')->get();
        $curriculums = Curriculum::select('id', 'name')->get();
        
        // Get unique years and terms from question papers
        $years = QuestionPaper::distinct()->orderBy('year', 'desc')->pluck('year');
        $terms = QuestionPaper::distinct()->whereNotNull('term')->pluck('term');
        $paperTypes = QuestionPaper::distinct()->whereNotNull('paper_type')->pluck('paper_type');
        
        return response()->json([
            'success' => true,
            'filters' => [
                'subjects' => $subjects,
                'classes' => $classes,
                'curriculums' => $curriculums,
                'years' => $years,
                'terms' => $terms,
                'paper_types' => $paperTypes
            ]
        ]);
    }
    
    /**
     * API endpoint to get a specific question paper with all details
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function apiGetQuestionPaper($id)
    {
        try {
            $questionPaper = QuestionPaper::with([
                'examiner', 
                'subject', 
                'class', 
                'curriculum',
                'questions' => function($query) {
                    $query->whereNull('parent_id')
                          ->orderBy('question_number')
                          ->with([
                              'answers',
                              'subQuestions' => function($subQuery) {
                                  $subQuery->orderBy('question_number')
                                          ->with(['answers', 'subQuestions.answers']);
                              }
                          ]);
                }
            ])->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $questionPaper
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Question paper not found or an error occurred',
                'message' => $e->getMessage()
            ], 404);
        }
    }
}