<?php

namespace App\Services;

use App\Models\Answer;
use App\Models\Classes;
use App\Models\Curriculum;
use App\Models\Examiner;
use App\Models\Image;
use App\Models\Question;
use App\Models\QuestionPaper;
use App\Models\Subject;
use App\Services\AI\AIServiceFactory;
use App\Services\AI\AIServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;
use Exception;
use Llama\LlamaModel;

class DocumentProcessorService
{
    protected $aiService;
    protected $answerProcessor;
    protected $primaryAIService;
    protected $fallbackAIService;
    protected $providers = ['openai', 'deepseek'];
    
    public function __construct()
    {
        // Default to OpenAI as primary, DeepSeek as fallback
        $this->primaryAIService = AIServiceFactory::create('openai');
        $this->fallbackAIService = AIServiceFactory::create('deepseek');
    }
    
    /**
     * Process an uploaded file - handles both single files and zip archives
     */
    public function processUploadedFile(UploadedFile $file, ?string $preferredProvider = null): array
    {
        // If provider specified, update the service
        if ($preferredProvider) {
            $this->setAIProvider($preferredProvider);
        }
        
        $results = [];
        $extension = strtolower($file->getClientOriginalExtension());
        
        try {
            if ($extension === 'zip') {
                $results = $this->processZipFile($file);
            } else {
                $filePath = $this->saveUploadedFile($file);
                $paperData = $this->processFile($filePath, $file->getClientOriginalName(), $extension);
                
                if (!isset($paperData['error'])) {
                    $results[] = $paperData;
                } else {
                    $results[] = ['error' => $paperData['error'], 'file' => $file->getClientOriginalName()];
                }
            }
            
            return [
                'success' => true,
                'message' => 'Files processed successfully',
                'results' => $results
            ];
        } catch (Exception $e) {
            Log::error('File processing error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to process file: ' . $e->getMessage(),
                'file' => $file->getClientOriginalName()
            ];
        }
    }
    
    /**
     * Process a ZIP file containing multiple documents
     */
    protected function processZipFile(UploadedFile $file): array
    {
        $results = [];
        $tempPath = storage_path('app/temp/' . Str::uuid() . '.zip');
        $extractPath = storage_path('app/temp/' . Str::uuid());
        
        // Save the zip file
        $file->move(dirname($tempPath), basename($tempPath));
        
        // Create extraction directory
        if (!is_dir($extractPath)) {
            mkdir($extractPath, 0755, true);
        }
        
        $zip = new ZipArchive();
        if ($zip->open($tempPath) === true) {
            $zip->extractTo($extractPath);
            $zip->close();
            
            // Process each file in the zip
            $files = $this->scanDirectory($extractPath);
            
            foreach ($files as $extractedFile) {
                $fileInfo = pathinfo($extractedFile);
                $extension = strtolower($fileInfo['extension'] ?? '');
                
                // Skip non-document files
                if (!in_array($extension, ['pdf', 'docx', 'doc', 'txt', 'jpg', 'jpeg', 'png'])) {
                    continue;
                }
                
                try {
                    $paperData = $this->processFile($extractedFile, basename($extractedFile), $extension);
                    
                    if (!isset($paperData['error'])) {
                        $results[] = $paperData;
                    } else {
                        $results[] = ['error' => $paperData['error'], 'file' => basename($extractedFile)];
                    }
                } catch (Exception $e) {
                    $results[] = [
                        'error' => 'Failed to process file: ' . $e->getMessage(),
                        'file' => basename($extractedFile)
                    ];
                }
            }
            
            // Clean up
            $this->removeDirectory($extractPath);
        } else {
            throw new Exception('Failed to open zip file');
        }
        
        // Clean up the temp zip file
        if (file_exists($tempPath)) {
            unlink($tempPath);
        }
        
        return $results;
    }
    
    /**
     * Recursively scan a directory for files
     */
    protected function scanDirectory(string $dir): array
    {
        $result = [];
        $files = scandir($dir);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            
            if (is_dir($path)) {
                $result = array_merge($result, $this->scanDirectory($path));
            } else {
                $result[] = $path;
            }
        }
        
        return $result;
    }
    
    /**
     * Recursively remove a directory and its contents
     */
    protected function removeDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = scandir($dir);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }
    
    /**
     * Save an uploaded file to storage
     */
    protected function saveUploadedFile(UploadedFile $file): string
    {
        $uniqueFilename = time() . '_' . Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('exam_papers', $uniqueFilename, 'public');
        return storage_path('app/public/' . $path);
    }
    
    /**
     * Process a single file and extract its content
     */
    protected function processFile(string $filePath, string $originalFilename, string $extension): array
    {
        // Extract text from the file based on its extension
        $text = $this->extractTextFromFile($filePath, $extension);
        
        if (empty($text)) {
            return ['error' => 'Could not extract text from file'];
        }
        
        // Use AI to analyze the document content with fallback
        $analysisResult = $this->analyzeDocumentWithFallback($text);
        
        // If both AI services failed, use basic extraction
        if (isset($analysisResult['error']) && strpos($analysisResult['error'], 'All AI services failed') !== false) {
            Log::warning('Using basic extraction fallback');
            $analysisResult = $this->basicExtractionFallback($text, $originalFilename);
        }
        
        // Save the parsed data to the database
        try {
            DB::beginTransaction();
            
            // Store exam paper metadata
            $questionPaper = $this->storeQuestionPaper($analysisResult, $originalFilename, $filePath, $extension);
            
            // Process questions and answers
            if (isset($analysisResult['questions']) && is_array($analysisResult['questions'])) {
                $this->processQuestions($analysisResult['questions'], $questionPaper);
            }
            
            DB::commit();
            
            return [
                'success' => true,
                'question_paper_id' => $questionPaper->id,
                'metadata' => [
                    'examiner' => $questionPaper->examiner->name ?? null,
                    'subject' => $questionPaper->subject->name ?? null,
                    'class' => $questionPaper->class->name ?? null,
                    'curriculum' => $questionPaper->curriculum->name ?? null,
                    'year' => $questionPaper->year,
                    'term' => $questionPaper->term,
                    'paper_type' => $questionPaper->paper_type
                ]
            ];
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Database error while processing file: ' . $e->getMessage());
            return ['error' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Basic extraction fallback when AI services fail
     * 
     * @param string $text Document text content
     * @param string $filename Original filename
     * @return array Extracted metadata and questions
     */
    protected function basicExtractionFallback(string $text, string $filename): array
    {
        // Basic metadata extraction from filename and content
        $metadata = $this->extractBasicMetadata($filename, $text);
        
        // Basic question extraction using regex patterns
        $questions = $this->extractBasicQuestions($text);
        
        return [
            'metadata' => $metadata,
            'questions' => $questions
        ];
    }
    
    /**
     * Extract basic metadata from filename and text content
     * 
     * @param string $filename Original filename
     * @param string $text Document text content
     * @return array Extracted metadata
     */
    protected function extractBasicMetadata(string $filename, string $text): array
    {
        $metadata = [
            'examiner' => 'Unknown',
            'subject' => 'Unknown Subject',
            'class' => 'Unknown Class',
            'curriculum' => '8-4-4',
            'year' => date('Y'),
            'term' => null,
            'paper_type' => null
        ];
        
        // Extract subject from filename
        if (preg_match('/(math|english|kiswahili|science|social|geography|history|biology|chemistry|physics)/i', $filename, $matches)) {
            $metadata['subject'] = ucfirst(strtolower($matches[1]));
        }
        
        // Extract paper type
        if (preg_match('/pp(\d+)|paper\s*(\d+)/i', $filename, $matches)) {
            $metadata['paper_type'] = 'Paper ' . ($matches[1] ?: $matches[2]);
        }
        
        // Try to extract year from content or filename
        if (preg_match('/\b(20\d{2})\b/', $text . ' ' . $filename, $yearMatch)) {
            $metadata['year'] = (int)$yearMatch[1];
        }
        
        // Try to extract term
        if (preg_match('/\bterm\s*(\d)\b/i', $text . ' ' . $filename, $termMatch)) {
            $metadata['term'] = (int)$termMatch[1];
        }
        
        // Try to extract class/form
        if (preg_match('/\b(form|class|grade)\s*(\d+)\b/i', $text . ' ' . $filename, $classMatch)) {
            $metadata['class'] = ucfirst(strtolower($classMatch[1])) . ' ' . $classMatch[2];
        }
        
        // Try to extract examiner/exam board
        $possibleExaminers = ['KNEC', 'KCSE', 'KCPE', 'MOCK', 'INTERNAL', 'TERM', 'ENDTERM', 'MIDTERM'];
        foreach ($possibleExaminers as $examiner) {
            if (stripos($text . ' ' . $filename, $examiner) !== false) {
                $metadata['examiner'] = $examiner;
                break;
            }
        }
        
        return $metadata;
    }
    
    /**
     * Extract questions using regex patterns
     * 
     * @param string $text Document text content
     * @return array Extracted questions
     */
    protected function extractBasicQuestions(string $text): array
    {
        $questions = [];
        
        // Pattern for main questions like "1. What is..."
        if (preg_match_all('/(?:\n|\A)(\d+)\.\s+([^\n]+(?:\n(?!\d+\.).+)*)/s', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $questionNumber = (int)$match[1];
                $content = trim($match[2]);
                
                // Extract marks if present
                $marks = null;
                if (preg_match('/\((\d+)\s*(?:marks|mark)\)/i', $content, $markMatch)) {
                    $marks = (int)$markMatch[1];
                }
                
                // Try to extract sub-questions
                $subQuestions = $this->extractSubQuestions($content);
                
                $questions[] = [
                    'question_number' => $questionNumber,
                    'content' => $content,
                    'level' => 1,
                    'marks' => $marks,
                    'answer_format' => $this->detectAnswerFormat($content),
                    'answer' => null, // No automatic answer generation in basic mode
                    'sub_questions' => $subQuestions
                ];
            }
        }
        
        return $questions;
    }
    
    /**
     * Extract sub-questions from question content
     * 
     * @param string $content Question content
     * @return array Extracted sub-questions
     */
    protected function extractSubQuestions(string $content): array
    {
        $subQuestions = [];
        
        // Pattern for sub-questions like "a) What is..." or "(a) What is..."
        if (preg_match_all('/(?:\n|\A)(?:(?:\()?([a-z])(?:\))?|([ivxlcdm]+)(?:[\.\)])|([a-z])(?:[\.\)]))\s+([^\n]+(?:\n(?!(?:\()?[a-z](?:\))?|[ivxlcdm]+(?:[\.\)])|[a-z](?:[\.\)]))(?![\d])(?!\n\d+\.).+)*)/si', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $letter = !empty($match[1]) ? $match[1] : (!empty($match[2]) ? $match[2] : $match[3]);
                $subContent = trim($match[4]);
                
                // Extract marks if present
                $marks = null;
                if (preg_match('/\((\d+)\s*(?:marks|mark)\)/i', $subContent, $markMatch)) {
                    $marks = (int)$markMatch[1];
                }
                
                $subQuestions[] = [
                    'question_number' => $letter,
                    'content' => $subContent,
                    'level' => 2,
                    'marks' => $marks,
                    'answer_format' => $this->detectAnswerFormat($subContent),
                    'answer' => null, // No automatic answer generation in basic mode
                    'sub_questions' => [] // Third-level questions not implemented in basic extraction
                ];
            }
        }
        
        return $subQuestions;
    }
    
    /**
     * Detect appropriate answer format based on question content
     * 
     * @param string $content Question content
     * @return string Answer format (points or paragraph)
     */
    protected function detectAnswerFormat(string $content): string
    {
        $content = strtolower($content);
        
        // Check for point-based question indicators
        if (preg_match('/(list|state|identify|outline|mention|name|give|provide|specify|enumerate)/i', $content)) {
            return 'points';
        }
        
        // Check for paragraph-based question indicators
        if (preg_match('/(explain|describe|discuss|analyze|examine|evaluate|compare|contrast|elaborate|justify|illustrate|assess)/i', $content)) {
            return 'paragraph';
        }
        
        // Default to paragraph if not determined
        return 'paragraph';
    }
    
    /**
     * Analyze document content with fallback between AI services
     * 
     * @param string $text The document text to analyze
     * @return array The analysis result
     */
    protected function analyzeDocumentWithFallback(string $text): array
    {
        $errors = [];
        
        // Try with primary service first
        try {
            Log::info('Attempting to analyze document with primary AI service');
            $result = $this->primaryAIService->analyzeDocumentContent($text);
            
            // If successful, return the result
            if (!isset($result['error'])) {
                Log::info('Document successfully analyzed with primary AI service');
                return $result;
            }
            
            // Store error and try fallback
            $errors['primary'] = $result['error'];
            Log::warning('Primary AI service failed: ' . $result['error'] . '. Trying fallback service.');
            
        } catch (Exception $e) {
            $errors['primary'] = $e->getMessage();
            Log::error('Exception with primary AI service: ' . $e->getMessage() . '. Trying fallback service.');
        }
        
        // Try with fallback service
        try {
            Log::info('Attempting to analyze document with fallback AI service');
            $result = $this->fallbackAIService->analyzeDocumentContent($text);
            
            // If successful, return the result
            if (!isset($result['error'])) {
                Log::info('Document successfully analyzed with fallback AI service');
                return $result;
            }
            
            // Store error
            $errors['fallback'] = $result['error'];
            Log::warning('Fallback AI service also failed: ' . $result['error']);
            
        } catch (Exception $e) {
            $errors['fallback'] = $e->getMessage();
            Log::error('Exception with fallback AI service: ' . $e->getMessage());
        }
        
        // If we get here, all services failed
        Log::error('All AI services failed to analyze document', ['errors' => $errors]);
        return [
            'error' => 'All AI services failed to analyze document',
            'details' => $errors
        ];
    }
    
    /**
     * Extract text from various file types
     */
    protected function extractTextFromFile(string $filePath, string $extension): string
    {
        switch (strtolower($extension)) {
            case 'pdf':
                return $this->extractTextFromPdf($filePath);
            case 'docx':
                return $this->extractTextFromDocx($filePath);
            case 'doc':
                return $this->extractTextFromDoc($filePath);
            case 'txt':
                return file_get_contents($filePath);
            case 'jpg':
            case 'jpeg':
            case 'png':
                return $this->extractTextFromImage($filePath);
            default:
                return '';
        }
    }
    
    /**
     * Extract text from PDF files
     */
    protected function extractTextFromPdf(string $filePath): string
    {
        // Using pdftotext command-line tool if available
        if (function_exists('shell_exec')) {
            $tempOutput = tempnam(sys_get_temp_dir(), 'pdf_');
            shell_exec("pdftotext -layout \"$filePath\" \"$tempOutput\"");
            
            if (file_exists($tempOutput)) {
                $text = file_get_contents($tempOutput);
                unlink($tempOutput);
                return $text;
            }
        }
        
        // Fallback to a PHP library like Smalot\PdfParser
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filePath);
            return $pdf->getText();
        } catch (Exception $e) {
            Log::error('PDF parsing error: ' . $e->getMessage());
            return '';
        }
    }
    
    /**
     * Extract text from DOCX files
     */
    protected function extractTextFromDocx(string $filePath): string
    {
        try {
            $content = '';
            $zip = new ZipArchive();
            
            if ($zip->open($filePath) === true) {
                if (($index = $zip->locateName('word/document.xml')) !== false) {
                    $data = $zip->getFromIndex($index);
                    $zip->close();
                    
                    // Simple XML parsing - can be improved with a dedicated library
                    $xml = simplexml_load_string($data);
                    $namespaces = $xml->getNamespaces(true);
                    
                    $xml->registerXPathNamespace('w', $namespaces['w']);
                    $paragraphs = $xml->xpath('//w:p');
                    
                    foreach ($paragraphs as $paragraph) {
                        $texts = $paragraph->xpath('.//w:t');
                        foreach ($texts as $text) {
                            $content .= (string)$text . ' ';
                        }
                        $content .= "\n";
                    }
                }
            }
            
            return $content;
        } catch (Exception $e) {
            Log::error('DOCX parsing error: ' . $e->getMessage());
            return '';
        }
    }
    
    /**
     * Extract text from DOC files (legacy Word format)
     */
    protected function extractTextFromDoc(string $filePath): string
    {
        // Using antiword command-line tool if available
        if (function_exists('shell_exec')) {
            return shell_exec("antiword \"$filePath\"");
        }
        
        // Fallback to a simple approach that may or may not work well
        $content = @file_get_contents($filePath);
        
        if ($content !== false) {
            // Strip binary content as much as possible
            $content = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
            $content = preg_replace('/[^\x20-\x7E\r\n]/', '', $content);
            
            // Try to extract readable text
            $pattern = '/([\x20-\x7E\r\n]{5,})/';
            preg_match_all($pattern, $content, $matches);
            
            return implode("\n", $matches[0]);
        }
        
        return '';
    }
    
    /**
     * Extract text from image files
     */
    protected function extractTextFromImage(string $filePath): string
    {
        // Using Tesseract OCR if available
        if (function_exists('shell_exec')) {
            $tempOutput = tempnam(sys_get_temp_dir(), 'ocr_');
            shell_exec("tesseract \"$filePath\" \"$tempOutput\" -l eng");
            
            if (file_exists($tempOutput . '.txt')) {
                $text = file_get_contents($tempOutput . '.txt');
                unlink($tempOutput . '.txt');
                unlink($tempOutput);
                return $text;
            }
        }
        
        // For a production system, integrate with a cloud OCR API
        // as fallback if Tesseract is not available
        
        return '';
    }
    /**
     * Store question paper metadata in the database
     */
    protected function storeQuestionPaper(array $analysisResult, string $originalFilename, string $filePath, string $extension): QuestionPaper
    {
        $metadata = $analysisResult['metadata'] ?? [];
        
        // Get or create examiner
        $examiner = null;
        if (!empty($metadata['examiner']) && $metadata['examiner'] !== 'Not specified') {
            $examiner = Examiner::firstOrCreate(['name' => $metadata['examiner']]);
        }
        
        // Get or create curriculum
        $curriculumName = $metadata['curriculum'] ?? '8-4-4'; // Default to 8-4-4 if not specified
        $curriculumName = $curriculumName === 'Not specified' ? '8-4-4' : $curriculumName;
        $curriculum = Curriculum::firstOrCreate(['name' => $curriculumName]);
        
        // Get or create subject
        $subject = null;
        if (!empty($metadata['subject']) && $metadata['subject'] !== 'Not specified') {
            $subject = Subject::firstOrCreate(
                ['name' => $metadata['subject'], 'curriculum_id' => $curriculum->id],
                ['code' => substr(strtoupper($metadata['subject']), 0, 3)]
            );
        } else {
            // Create a default subject if none is found
            $subject = Subject::firstOrCreate(
                ['name' => 'Unknown Subject', 'curriculum_id' => $curriculum->id],
                ['code' => 'UNK']
            );
        }
        
        // Get or create class
        $class = null;
        if (!empty($metadata['class']) && $metadata['class'] !== 'Not specified') {
            $class = Classes::firstOrCreate(
                ['name' => $metadata['class'], 'curriculum_id' => $curriculum->id]
            );
        } else {
            // Create a default class if none is found
            $class = Classes::firstOrCreate(
                ['name' => 'Unknown Class', 'curriculum_id' => $curriculum->id]
            );
        }
        
        // Process term data to ensure it's an integer or null
        $term = null;
        if (isset($metadata['term']) && $metadata['term'] !== 'Not specified') {
            // Extract numeric value if it's something like "Term 1"
            if (preg_match('/(\d+)/', $metadata['term'], $matches)) {
                $term = (int)$matches[1];
            } 
            // If it's already a numeric value
            else if (is_numeric($metadata['term'])) {
                $term = (int)$metadata['term'];
            }
        }
        
        // Process year data to ensure it's an integer
        $year = date('Y'); // Default to current year
        if (isset($metadata['year']) && is_numeric($metadata['year'])) {
            $year = (int)$metadata['year'];
        }
        
        // Create question paper record
        $questionPaper = QuestionPaper::create([
            'examiner_id' => $examiner ? $examiner->id : null,
            'subject_id' => $subject->id,
            'class_id' => $class->id,
            'curriculum_id' => $curriculum->id,
            'term' => $term, // Will be null if "Not specified" or not numeric
            'year' => $year,
            'paper_type' => $metadata['paper_type'] ?? null,
            'original_filename' => $originalFilename,
            'file_path' => $filePath,
            'file_type' => $extension,
            'is_processed' => true,
            'metadata' => $analysisResult['metadata'] ?? null
        ]);
        
        return $questionPaper;
    }

    
    /**
     * Process and store questions and answers recursively
     */

protected function processQuestions(array $questions, QuestionPaper $questionPaper, int $parentId = null): void
{
    foreach ($questions as $questionData) {
        // Create main question
        $question = Question::create([
            'question_paper_id' => $questionPaper->id,
            'parent_id' => $parentId,
            'question_number' => $questionData['question_number'] ?? 0,
            'content' => $questionData['content'] ?? '',
            'level' => $questionData['level'] ?? 1,
            'marks' => $questionData['marks'] ?? null,
            'answer_format' => $questionData['answer_format'] ?? 'paragraph' // Store the required answer format
        ]);
        
        // Create answer if available, with proper formatting
        if (isset($questionData['answer']) && !empty($questionData['answer'])) {
            $answerContent = $questionData['answer'];
            
            // If it's a points-type answer and stored as array, convert to JSON
            if (isset($questionData['answer_format']) && 
                $questionData['answer_format'] === 'points' && 
                is_array($answerContent)) {
                $answerContent = json_encode($answerContent);
            }
            
            Answer::create([
                'question_id' => $question->id,
                'content' => $answerContent,
                'format' => $questionData['answer_format'] ?? 'paragraph'
            ]);
        }
        
        // Process sub-questions recursively
        if (isset($questionData['sub_questions']) && is_array($questionData['sub_questions']) && !empty($questionData['sub_questions'])) {
            $this->processQuestions($questionData['sub_questions'], $questionPaper, $question->id);
        }
    }
}
    
    /**
     * Set the AI service provider to use
     * 
     * @param string $provider The provider name (openai or deepseek)
     * @return self
     */
    public function setAIProvider(string $provider): self
    {
        if (in_array(strtolower($provider), $this->providers)) {
            Log::info('AI provider set.', ['provider' => $provider]);
            
            // Set as primary provider, the other becomes fallback
            if (strtolower($provider) === 'openai') {
                $this->primaryAIService = AIServiceFactory::create('openai');
                $this->fallbackAIService = AIServiceFactory::create('deepseek');
            } else {
                $this->primaryAIService = AIServiceFactory::create('deepseek');
                $this->fallbackAIService = AIServiceFactory::create('openai');
            }
        }
        
        return $this;
    }
    
    /**
     * Update the API keys for the services
     * 
     * @param string $provider The provider to update (openai or deepseek)
     * @param string $apiKey The new API key
     * @return self
     */
    public function updateAPIKey(string $provider, string $apiKey): self
    {
        // This method assumes you've added a method to update API keys in your service classes
        // You would need to implement updateAPIKey() in your AIServiceInterface and service classes
        
        if (strtolower($provider) === 'openai') {
            // Update OpenAI API key in config
            config(['services.openai.api_key' => $apiKey]);
            
            // Recreate the service with the new key
            $this->primaryAIService = AIServiceFactory::create('openai');
            
            Log::info('OpenAI API key updated');
        } else if (strtolower($provider) === 'deepseek') {
            // Update DeepSeek API key in config
            config(['services.deepseek.api_key' => $apiKey]);
            
            // Recreate the service with the new key
            $this->fallbackAIService = AIServiceFactory::create('deepseek');
            
            Log::info('DeepSeek API key updated');
        }
        
        return $this;
    }

    public function generateAnswers(QuestionPaper $questionPaper): array
    {
        try {
            Log::info("Starting answer generation for question paper #{$questionPaper->id}");
            
            // Get all questions for this paper
            $questions = $questionPaper->questions()->whereNull('parent_id')->get();
            
            if ($questions->isEmpty()) {
                return [
                    'success' => false,
                    'error' => 'No questions found for this paper'
                ];
            }
            
            // Create a combined prompt for all questions
            $prompt = $this->createPrompt($questionPaper, $questions);
            
            // Send to AI service
            Log::info("Sending combined questions to AI service");
            $result = $this->aiService->generateContent($prompt);
            
            if (!isset($result['success']) || !$result['success']) {
                Log::error("AI service failed to generate answers", [
                    'error' => $result['error'] ?? 'Unknown error'
                ]);
                return $result;
            }
            
            // Process and save the answers
            Log::info("AI response received, processing answers");
            $saveResult = $this->answerProcessor->processAndSaveAnswers(
                $result['content'],
                $questionPaper->id
            );
            
            return $saveResult;
            
        } catch (\Exception $e) {
            Log::error('Error generating answers: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'error' => 'Error generating answers: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Create a prompt for the AI service
     * 
     * @param QuestionPaper $questionPaper The question paper
     * @param \Illuminate\Database\Eloquent\Collection $questions The questions
     * @return string The formatted prompt
     */
    private function createPrompt(QuestionPaper $questionPaper, $questions): string
    {
        $subject = $questionPaper->subject->name ?? 'Unknown Subject';
        $class = $questionPaper->class->name ?? 'Unknown Class';
        $curriculum = $questionPaper->curriculum->name ?? 'Unknown Curriculum';
        
        $prompt = "You are an expert teacher for {$subject} for {$class} in the {$curriculum} curriculum.\n\n";
        $prompt .= "Please provide detailed and accurate answers for the following exam questions.\n";
        $prompt .= "Format your response as a JSON object with a 'questions' array containing each question and its answer.\n\n";
        
        $prompt .= "For each question include:\n";
        $prompt .= "- question_number (e.g., '1', '1a')\n";
        $prompt .= "- content (the question text)\n";
        $prompt .= "- answer (your detailed answer)\n";
        $prompt .= "- answer_format ('paragraph' or 'points')\n\n";
        
        $prompt .= "For questions that require point-form answers, provide an array of points. For paragraph answers, provide a detailed paragraph.\n\n";
        
        $prompt .= "Questions:\n\n";
        
        foreach ($questions as $question) {
            $prompt .= "Question {$question->question_number}: {$question->content}\n";
            
            // Include sub-questions if any
            $subQuestions = $question->subQuestions;
            foreach ($subQuestions as $subQuestion) {
                $prompt .= "Question {$subQuestion->question_number}: {$subQuestion->content}\n";
            }
            
            $prompt .= "\n";
        }
        
        $prompt .= "Please format your response as a valid JSON object.";
        
        return $prompt;
    }

    
}