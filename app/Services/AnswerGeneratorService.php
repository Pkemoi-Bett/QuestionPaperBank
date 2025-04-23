<?php

namespace App\Services;

use App\Models\QuestionPaper;
use App\Models\Question;
use App\Models\Answer;
use App\Services\AI\AIServiceInterface;
use App\Services\AI\AIServiceFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Dompdf\Dompdf;
use Dompdf\Options;
use Exception;

class AnswerGeneratorService
{
    protected $aiService;
    protected $paperFormatService;
    
    public function __construct()
    {
        // Default to OpenAI as the service
        $this->aiService = AIServiceFactory::create('openai');
        $this->paperFormatService = new PaperFormatService();
    }
    
    /**
     * Generate answers for a question paper
     *
     * @param int $questionPaperId The ID of the question paper
     * @param bool $regenerate Whether to regenerate existing answers
     * @return array The generated answers and metadata
     */
    public function generateAnswers(int $questionPaperId, bool $regenerate = false): array
    {
        try {
            // Get the question paper with questions
            $questionPaper = QuestionPaper::with(['questions' => function($query) {
                $query->whereNull('parent_id'); // Only top-level questions
            }, 'examiner', 'subject', 'class', 'curriculum'])
                ->findOrFail($questionPaperId);
            
            // Prepare questions for AI processing
            $questions = $this->prepareQuestionsForProcessing($questionPaper->questions, $regenerate);
            
            // If no questions need processing, return existing data
            if (empty($questions['to_process'])) {
                Log::info('No questions to process for paper ID: ' . $questionPaperId);
                return $this->formatResponse($questionPaper, $questions['all']);
            }
            
            // Process questions in batches to avoid token limits
            $processedQuestions = $this->processQuestionBatches($questions['to_process']);
            
            // Save the generated answers
            $this->saveGeneratedAnswers($processedQuestions);
            
            // Combine processed questions with existing ones
            $allQuestions = array_merge($processedQuestions, $questions['existing']);
            
            // Format and return the complete response
            return $this->formatResponse($questionPaper, $allQuestions);
            
        } catch (Exception $e) {
            Log::error('Answer generation failed: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to generate answers: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate a PDF with questions and answers
     *
     * @param int $questionPaperId The ID of the question paper
     * @return array The PDF file information
     */
    public function generatePDF(int $questionPaperId): array
    {
        try {
            // Get answers in JSON format
            $answerData = $this->generateAnswers($questionPaperId, false);
            
            if (!isset($answerData['success']) || !$answerData['success']) {
                return [
                    'success' => false,
                    'message' => 'Failed to generate answers data for PDF'
                ];
            }
            
            // Generate HTML content from answer data
            $html = $this->paperFormatService->generateHTMLFromAnswers($answerData);
            
            // Create PDF
            $options = new Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isPhpEnabled', false);
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            
            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4');
            $dompdf->render();
            
            // Generate filename
            $filename = 'answers_' . $questionPaperId . '_' . time() . '.pdf';
            $pdfPath = 'public/answers/' . $filename;
            
            // Save to storage
            Storage::put($pdfPath, $dompdf->output());
            
            return [
                'success' => true,
                'filename' => $filename,
                'download_url' => Storage::url($pdfPath),
                'message' => 'PDF generated successfully'
            ];
            
        } catch (Exception $e) {
            Log::error('PDF generation failed: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to generate PDF: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Prepare questions for processing
     *
     * @param \Illuminate\Database\Eloquent\Collection $questions The questions collection
     * @param bool $regenerate Whether to regenerate existing answers
     * @return array The prepared questions
     */
    protected function prepareQuestionsForProcessing($questions, bool $regenerate): array
    {
        $toProcess = [];
        $existing = [];
        $all = [];
        
        foreach ($questions as $question) {
            $questionData = $this->buildQuestionData($question);
            $all[] = $questionData;
            
            // Check if this question already has an answer
            $hasAnswer = $question->answer()->exists();
            
            if (!$hasAnswer || $regenerate) {
                $toProcess[] = $questionData;
            } else {
                $existing[] = $questionData;
            }
        }
        
        return [
            'to_process' => $toProcess,
            'existing' => $existing,
            'all' => $all
        ];
    }
    
    /**
     * Build complete question data including sub-questions
     *
     * @param Question $question The question model
     * @return array The question data
     */
    protected function buildQuestionData(Question $question): array
    {
        $questionData = [
            'id' => $question->id,
            'question_number' => $question->question_number,
            'content' => $question->content,
            'level' => $question->level,
            'marks' => $question->marks,
            'answer_format' => $question->answer_format
        ];
        
        // Add existing answer if available
        if ($question->answer) {
            $answer = $question->answer;
            $answerContent = $answer->content;
            
            // Parse JSON for points-type answers
            if ($answer->format === 'points' && $this->isJson($answerContent)) {
                $answerContent = json_decode($answerContent, true);
            }
            
            $questionData['answer'] = $answerContent;
        }
        
        // Process sub-questions recursively
        $subQuestions = $question->children;
        if ($subQuestions && count($subQuestions) > 0) {
            $questionData['sub_questions'] = [];
            foreach ($subQuestions as $subQuestion) {
                $questionData['sub_questions'][] = $this->buildQuestionData($subQuestion);
            }
        }
        
        return $questionData;
    }
    
    /**
     * Process questions in batches
     *
     * @param array $questions The questions to process
     * @return array The processed questions
     */
    protected function processQuestionBatches(array $questions): array
    {
        $processedQuestions = [];
        $batchSize = 5; // Process 5 questions at a time
        
        // Split questions into batches
        $batches = array_chunk($questions, $batchSize);
        
        foreach ($batches as $batch) {
            $batchResult = $this->processQuestionBatch($batch);
            $processedQuestions = array_merge($processedQuestions, $batchResult);
        }
        
        return $processedQuestions;
    }
    
    /**
     * Process a batch of questions
     *
     * @param array $batch The batch of questions
     * @return array The processed questions
     */
    protected function processQuestionBatch(array $batch): array
    {
        try {
            // Prepare prompt for AI service
            $prompt = $this->buildBatchPrompt($batch);
            
            // Send to AI service for processing
            $response = $this->aiService->generateContent($prompt);
            
            // Parse AI response
            if (isset($response['content'])) {
                $parsed = $this->parseAIResponse($response['content'], $batch);
                return $parsed;
            }
            
            // If we failed to get answers, return the original questions
            Log::error('Failed to get AI-generated answers for batch', ['response' => $response]);
            return $batch;
            
        } catch (Exception $e) {
            Log::error('Error processing question batch: ' . $e->getMessage());
            return $batch; // Return the original questions without answers
        }
    }
    
    /**
     * Build a prompt for a batch of questions
     *
     * @param array $batch The batch of questions
     * @return string The prompt
     */
    protected function buildBatchPrompt(array $batch): string
    {
        $prompt = "Generate comprehensive answers for the following exam questions. For each question, provide an answer in the format specified ('paragraph' or 'points').\n\n";
        
        foreach ($batch as $index => $question) {
            $questionNumber = $index + 1;
            $prompt .= "Question {$questionNumber}: {$question['content']}\n";
            $prompt .= "Answer format: {$question['answer_format']}\n";
            $prompt .= "Marks: " . ($question['marks'] ?? 'Not specified') . "\n\n";
        }
        
        $prompt .= "For each question, provide a thorough answer that would receive full marks. Format your response as a JSON object with the question indices as keys and the answers as values. For 'points' format answers, provide an array of points. For 'paragraph' format answers, provide a well-structured paragraph.";
        
        return $prompt;
    }
    
    /**
     * Parse the AI response and match with questions
     *
     * @param string $response The AI response
     * @param array $batch The batch of questions
     * @return array The processed questions
     */
    protected function parseAIResponse(string $response, array $batch): array
    {
        // Try to parse as JSON
        if ($this->isJson($response)) {
            $parsed = json_decode($response, true);
            
            // Match answers with questions
            foreach ($batch as $index => $question) {
                $key = (string)($index + 1); // Match with the key in the response
                
                if (isset($parsed[$key])) {
                    $batch[$index]['answer'] = $parsed[$key];
                }
            }
            
            return $batch;
        }
        
        // If not JSON, try to extract answers using regex
        $pattern = '/Question\s+(\d+)[\s\S]*?Answer:[\s\n]*(.+?)(?=Question\s+\d+|$)/i';
        if (preg_match_all($pattern, $response, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $questionIndex = (int)$match[1] - 1;
                if (isset($batch[$questionIndex])) {
                    $answer = trim($match[2]);
                    $batch[$questionIndex]['answer'] = $answer;
                }
            }
        }
        
        return $batch;
    }
    
    /**
     * Save generated answers to database
     *
     * @param array $questions The processed questions
     * @return void
     */
    protected function saveGeneratedAnswers(array $questions): void
    {
        foreach ($questions as $question) {
            if (isset($question['id']) && isset($question['answer'])) {
                $format = $question['answer_format'] ?? 'paragraph';
                $content = $question['answer'];
                
                // Convert array to JSON string for points-type answers
                if ($format === 'points' && is_array($content)) {
                    $content = json_encode($content);
                }
                
                // Update or create the answer
                Answer::updateOrCreate(
                    ['question_id' => $question['id']],
                    ['content' => $content, 'format' => $format]
                );
                
                // Process sub-questions recursively
                if (isset($question['sub_questions']) && is_array($question['sub_questions'])) {
                    $this->saveGeneratedAnswers($question['sub_questions']);
                }
            }
        }
    }
    
    /**
     * Format the final response
     *
     * @param QuestionPaper $questionPaper The question paper
     * @param array $questions The processed questions
     * @return array The formatted response
     */
    protected function formatResponse(QuestionPaper $questionPaper, array $questions): array
    {
        return [
            'success' => true,
            'metadata' => [
                'examiner' => $questionPaper->examiner->name ?? 'Unknown',
                'subject' => $questionPaper->subject->name ?? 'Unknown Subject',
                'class' => $questionPaper->class->name ?? 'Unknown Class',
                'curriculum' => $questionPaper->curriculum->name ?? '8-4-4',
                'year' => $questionPaper->year ?? date('Y'),
                'term' => $questionPaper->term,
                'paper_type' => $questionPaper->paper_type
            ],
            'questions' => $questions,
            'generated_at' => now()->toIso8601String(),
            'download_url' => route('answers.download', ['id' => $questionPaper->id])
        ];
    }
    
    /**
     * Set the AI provider for answer generation
     *
     * @param string $provider The provider name
     * @return self
     */
    public function setAIProvider(string $provider): self
    {
        if (in_array($provider, ['openai', 'deepseek'])) {
            $this->aiService = AIServiceFactory::create($provider);
        }
        return $this;
    }
    
    /**
     * Check if a string is valid JSON
     *
     * @param string $string The string to check
     * @return bool Whether the string is valid JSON
     */
    protected function isJson(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    public function processAndSaveAnswers($aiResponse, int $questionPaperId): array
    {
        try {
            // If response is a string (JSON), decode it
            if (is_string($aiResponse)) {
                // Remove any markdown code block indicators (```json, ```)
                $cleanedJson = preg_replace('/```json\s*|\s*```/', '', $aiResponse);
                $data = json_decode($cleanedJson, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error('Failed to parse AI response: ' . json_last_error_msg(), [
                        'response' => $aiResponse
                    ]);
                    return [
                        'success' => false,
                        'error' => 'Failed to parse AI response: ' . json_last_error_msg()
                    ];
                }
            } else {
                $data = $aiResponse;
            }
            
            // Check if the response has the expected structure
            if (!isset($data['questions']) || !is_array($data['questions'])) {
                Log::error('Invalid AI response structure: missing questions array', [
                    'response' => $data
                ]);
                return [
                    'success' => false,
                    'error' => 'Invalid AI response structure: missing questions array'
                ];
            }
            
            $savedAnswers = 0;
            $failedAnswers = 0;
            
            // Process each question and its answer
            foreach ($data['questions'] as $questionData) {
                // Find the question in the database
                $question = Question::where('question_paper_id', $questionPaperId)
                            ->where('question_number', $questionData['question_number'])
                            ->first();
                
                if (!$question) {
                    Log::warning('Question not found', [
                        'question_paper_id' => $questionPaperId,
                        'question_number' => $questionData['question_number']
                    ]);
                    $failedAnswers++;
                    continue;
                }
                
                // Save the main question's answer if it exists and isn't "Unknown"
                if (isset($questionData['answer']) && $questionData['answer'] !== 'Unknown') {
                    $content = is_array($questionData['answer']) 
                             ? implode("\n", $questionData['answer']) 
                             : $questionData['answer'];
                    
                    $format = isset($questionData['answer_format']) && $questionData['answer_format'] !== 'Unknown'
                            ? $questionData['answer_format']
                            : 'paragraph';
                    
                    $this->saveAnswer($question, $content, $format);
                    $savedAnswers++;
                }
                
                // Process sub-questions if they exist
                if (isset($questionData['sub_questions']) && is_array($questionData['sub_questions'])) {
                    foreach ($questionData['sub_questions'] as $subQuestionData) {
                        $subQuestion = Question::where('question_paper_id', $questionPaperId)
                                    ->where('question_number', $subQuestionData['question_number'])
                                    ->first();
                        
                        if (!$subQuestion) {
                            Log::warning('Sub-question not found', [
                                'question_paper_id' => $questionPaperId,
                                'question_number' => $subQuestionData['question_number']
                            ]);
                            $failedAnswers++;
                            continue;
                        }
                        
                        // Save the sub-question's answer if it exists and isn't "Unknown"
                        if (isset($subQuestionData['answer']) && $subQuestionData['answer'] !== 'Unknown') {
                            $content = is_array($subQuestionData['answer']) 
                                     ? implode("\n", $subQuestionData['answer']) 
                                     : $subQuestionData['answer'];
                            
                            $format = isset($subQuestionData['answer_format']) && $subQuestionData['answer_format'] !== 'Unknown'
                                    ? $subQuestionData['answer_format']
                                    : 'paragraph';
                            
                            $this->saveAnswer($subQuestion, $content, $format);
                            $savedAnswers++;
                        }
                    }
                }
            }
            
            // Update the question paper status
            $questionPaper = QuestionPaper::find($questionPaperId);
            if ($savedAnswers > 0 && $questionPaper) {
                $questionPaper->has_answers = true;
                $questionPaper->answers_generated_at = now();
                $questionPaper->save();
                
                Log::info("Updated question paper status: has_answers = true");
            }
            
            return [
                'success' => true,
                'saved_answers' => $savedAnswers,
                'failed_answers' => $failedAnswers,
                'message' => "Successfully saved $savedAnswers answers. Failed to save $failedAnswers answers."
            ];
            
        } catch (\Exception $e) {
            Log::error('Error processing and saving answers: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'error' => 'Error processing and saving answers: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Save an answer to the database
     * 
     * @param Question $question The question model
     * @param string $content The answer content
     * @param string $format The format of the answer (paragraph or points)
     * @return Answer The saved answer model
     */
    private function saveAnswer(Question $question, string $content, string $format): Answer
    {
        // Check if an answer already exists for this question
        $answer = Answer::where('question_id', $question->id)->first();
        
        if (!$answer) {
            $answer = new Answer();
            $answer->question_id = $question->id;
        }
        
        $answer->content = $content;
        $answer->format = in_array($format, ['paragraph', 'points']) ? $format : 'paragraph';
        $answer->generated_by = 'openai';
        $answer->generated_at = now();
        $answer->metadata = [
            'model' => 'gpt-4o',
            'timestamp' => now()->timestamp
        ];
        $answer->save();
        
        Log::info("Saved answer for question #{$question->question_number}", [
            'question_id' => $question->id,
            'answer_id' => $answer->id
        ]);
        
        return $answer;
    }
}