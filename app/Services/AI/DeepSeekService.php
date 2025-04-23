<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeepSeekService implements AIServiceInterface
{
    /**
     * Extract metadata from the document text
     *
     * @param string $text The document text
     * @return array The extracted metadata
     */
    public function extractMetadata(string $text): array
    {
        $analysis = $this->analyzeDocumentContent($text);
        return $analysis['metadata'] ?? [];
    }

    /**
     * Extract questions from the document text
     *
     * @param string $text The document text
     * @return array The extracted questions
     */
    public function extractQuestions(string $text): array
    {
        $analysis = $this->analyzeDocumentContent($text);
        return $analysis['questions'] ?? [];
    }
    
    protected $apiKey;
    protected $apiEndpoint;
    
    public function __construct()
    {
        $this->apiKey = config('services.deepseek.api_key');
        $this->apiEndpoint = config('services.deepseek.endpoint', 'https://api.deepseek.com/v1');
        
        // Log partial key for debugging (careful with sensitive info in logs)
        $partialKey = substr($this->apiKey, 0, 8) . '...';
        Log::debug("Initialized DeepSeekService with key starting with: {$partialKey}");
    }
    
    /**
     * Analyze document content using DeepSeek AI
     * 
     * @param string $text The document text to analyze
     * @return array The analysis result
     */
    public function analyzeDocumentContent(string $text): array
    {
        try {
            Log::info('Sending request to DeepSeek API');
            
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(60)->post("{$this->apiEndpoint}/chat/completions", [
                'model' => 'deepseek-chat', // Using the default model, ensure this model exists
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->getSystemPrompt()
                    ],
                    [
                        'role' => 'user',
                        'content' => "Extract the following information from this exam paper document:\n\n{$text}"
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 4000
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                Log::info('Received successful response from DeepSeek');
                
                if (isset($data['choices'][0]['message']['content'])) {
                    $content = $data['choices'][0]['message']['content'];
                    return $this->parseAIResponse($content);
                }
                
                Log::error('Unexpected DeepSeek response format', ['response' => $data]);
                return ['error' => 'Invalid response format from DeepSeek API'];
            } else {
                $error = $response->json();
                Log::error('DeepSeek API error:', $error);
                return ['error' => 'Failed to analyze document with DeepSeek: ' . ($error['error']['message'] ?? 'Unknown error')];
            }
        } catch (\Exception $e) {
            Log::error('Exception in DeepSeek analysis:', ['message' => $e->getMessage()]);
            return ['error' => 'Failed to analyze document with DeepSeek: ' . $e->getMessage()];
        }
    
    }

    /**
     * Generate content based on a given prompt
     *
     * @param string $prompt The prompt for content generation
     * @return array The generated content
     */
    public function generateContent(string $prompt): array
    {
        try {
            Log::info('Sending content generation request to DeepSeek API');
            
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(60)->post("{$this->apiEndpoint}/chat/completions", [
                'model' => 'deepseek-chat',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.7,
                'max_tokens' => 1000
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                Log::info('Received successful response for content generation');
                
                if (isset($data['choices'][0]['message']['content'])) {
                    return ['content' => $data['choices'][0]['message']['content']];
                }
                
                Log::error('Unexpected response format for content generation', ['response' => $data]);
                return ['error' => 'Invalid response format from DeepSeek API'];
            } else {
                $error = $response->json();
                Log::error('DeepSeek API error during content generation:', $error);
                return ['error' => 'Failed to generate content with DeepSeek: ' . ($error['error']['message'] ?? 'Unknown error')];
            }
        } catch (\Exception $e) {
            Log::error('Exception during content generation:', ['message' => $e->getMessage()]);
            return ['error' => 'Failed to generate content with DeepSeek: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get the system prompt for document analysis
     */
    protected function getSystemPrompt(): string
    {
        return <<<PROMPT
You are an expert in analyzing examination papers. I will provide you with text from an exam paper, and you need to extract the following information in a structured JSON format:

1. Metadata about the exam:
   - examiner: the name of the examination board or institution
   - subject: the subject of the exam
   - class: the school class or form (e.g., Form 1, Grade 4)
   - curriculum: the curriculum system (e.g., 8-4-4, CBC)
   - year: the year of the exam
   - term: the term number (1, 2, or 3)
   - paper_type: whether it's Paper 1, Paper 2, etc.

2. Extract all questions with:
   - question_number: the question number
   - content: the full text of the question
   - level: 1 for main questions, 2 for sub-questions, 3 for sub-sub-questions
   - marks: the marks allocated to the question (if available)
   - sub_questions: an array of sub-questions (if any)
   - answer: the model answer or marking scheme for the question (if available)

Return the result as a JSON object with "metadata" and "questions" properties. Be as accurate as possible and include all questions found in the document.
PROMPT;
    }
    
    /**
     * Parse the AI response into a structured format
     */
    protected function parseAIResponse(string $response): array
    {
        try {
            // Extract JSON from response if it's surrounded by markdown code blocks
            if (preg_match('/```(?:json)?(.*?)```/s', $response, $matches)) {
                $jsonString = trim($matches[1]);
            } else {
                $jsonString = trim($response);
            }
            
            $data = json_decode($jsonString, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('JSON parsing error: ' . json_last_error_msg(), ['response' => $response]);
                return ['error' => 'Failed to parse AI response: ' . json_last_error_msg()];
            }
            
            // Validate the parsed data
            if (!isset($data['metadata']) || !isset($data['questions'])) {
                Log::error('Invalid AI response format', ['data' => $data]);
                return ['error' => 'Invalid AI response format'];
            }
            
            return $data;
        } catch (\Exception $e) {
            Log::error('Exception parsing AI response', ['message' => $e->getMessage(), 'response' => $response]);
            return ['error' => 'Failed to parse AI response: ' . $e->getMessage()];
        }
    }
    
    /**
     * Update API key
     * 
     * @param string $apiKey The new API key
     * @return void
     */
    public function updateAPIKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
        $partialKey = substr($this->apiKey, 0, 8) . '...';
        Log::info("Updated DeepSeekService API key to: {$partialKey}");
    }
}