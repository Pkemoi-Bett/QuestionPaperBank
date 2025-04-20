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

class DocumentProcessorService
{
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
        
        if (isset($analysisResult['error'])) {
            return $analysisResult;
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
     * Store question paper and its metadata in the database
     */
    // protected function storeQuestionPaper(array $analysisResult, string $originalFilename, string $filePath, string $extension): QuestionPaper
    // {
    //     $metadata = $analysisResult['metadata'] ?? [];
        
    //     // Get or create examiner
    //     $examiner = null;
    //     if (!empty($metadata['examiner'])) {
    //         $examiner = Examiner::firstOrCreate(['name' => $metadata['examiner']]);
    //     }
        
    //     // Get or create curriculum
    //     $curriculumName = $metadata['curriculum'] ?? '8-4-4'; // Default to 8-4-4 if not specified
    //     $curriculum = Curriculum::firstOrCreate(['name' => $curriculumName]);
        
    //     // Get or create subject
    //     $subject = null;
    //     if (!empty($metadata['subject'])) {
    //         $subject = Subject::firstOrCreate(
    //             ['name' => $metadata['subject'], 'curriculum_id' => $curriculum->id],
    //             ['code' => substr(strtoupper($metadata['subject']), 0, 3)]
    //         );
    //     } else {
    //         // Create a default subject if none is found
    //         $subject = Subject::firstOrCreate(
    //             ['name' => 'Unknown Subject', 'curriculum_id' => $curriculum->id],
    //             ['code' => 'UNK']
    //         );
    //     }
        
    //     // Get or create class
    //     $class = null;
    //     if (!empty($metadata['class'])) {
    //         $class = Classes::firstOrCreate(
    //             ['name' => $metadata['class'], 'curriculum_id' => $curriculum->id]
    //         );
    //     } else {
    //         // Create a default class if none is found
    //         $class = Classes::firstOrCreate(
    //             ['name' => 'Unknown Class', 'curriculum_id' => $curriculum->id]
    //         );
    //     }
        
    //     // Create question paper record
    //     $questionPaper = QuestionPaper::create([
    //         'examiner_id' => $examiner ? $examiner->id : null,
    //         'subject_id' => $subject->id,
    //         'class_id' => $class->id,
    //         'curriculum_id' => $curriculum->id,
    //         'term' => $metadata['term'] ?? null,
    //         'year' => $metadata['year'] ?? date('Y'),
    //         'paper_type' => $metadata['paper_type'] ?? null,
    //         'original_filename' => $originalFilename,
    //         'file_path' => $filePath,
    //         'file_type' => $extension,
    //         'is_processed' => true,
    //         'metadata' => $analysisResult['metadata'] ?? null
    //     ]);
        
    //     return $questionPaper;
    // }
    /**
 * Store question paper and its metadata in the database
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
/**
 * Process and store questions and answers recursively with proper formatting
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
}