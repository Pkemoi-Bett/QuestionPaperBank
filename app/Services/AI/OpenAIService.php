<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService implements AIServiceInterface
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
        $this->apiKey = config('services.openai.api_key');
        $this->apiEndpoint = config('services.openai.endpoint', 'https://api.openai.com/v1');
        
        // Log partial key for debugging (careful with sensitive info in logs)
        $partialKey = substr($this->apiKey, 0, 8) . '...';
        Log::debug("Initialized OpenAIService with key starting with: {$partialKey}");
    }
    
    /**
     * Analyze document content using OpenAI
     * 
     * @param string $text The document text to analyze
     * @return array The analysis result
     */
    public function analyzeDocumentContent(string $text): array
    {
        try {
            Log::info('Sending request to OpenAI API');
            
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(60)->post("{$this->apiEndpoint}/chat/completions", [
                'model' => 'gpt-4o',
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
                Log::info('Received successful response from OpenAI');
                
                if (isset($data['choices'][0]['message']['content'])) {
                    $content = $data['choices'][0]['message']['content'];
                    return $this->parseAIResponse($content);
                }
                
                Log::error('Unexpected OpenAI response format', ['response' => $data]);
                return ['error' => 'Invalid response format from OpenAI API'];
            } else {
                $error = $response->json();
                Log::error('OpenAI API error:', $error);
                return ['error' => 'Failed to analyze document with OpenAI: ' . ($error['error']['message'] ?? 'Unknown error')];
            }
        } catch (\Exception $e) {
            Log::error('Exception in OpenAI analysis:', ['message' => $e->getMessage()]);
            return ['error' => 'Failed to analyze document with OpenAI: ' . $e->getMessage()];
        }
    }
    

    /**
     * Get the system prompt for document analysis with enhanced answer formatting
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
    - year: IMPORTANT: Must be a numeric value only (e.g., 2023). If unknown, use the current year.
    - term: IMPORTANT: Must be a numeric value only (e.g., 1, 2, or 3). If unknown, use null.
    - paper_type: whether it's Paper 1, Paper 2, etc.

    2. Extract all questions with:
    - question_number: the question number
    - content: the full text of the question
    - level: 1 for main questions, 2 for sub-questions, 3 for sub-sub-questions
    - marks: the marks allocated to the question (if available)
    - sub_questions: an array of sub-questions (if any)
    - answer_format: determine if the question requires "paragraph" or "points" format based on analysis below
    - answer: the model answer or marking scheme for the question (if available)

    IMPORTANT INSTRUCTIONS FOR ANSWER FORMATTING:
    - Carefully analyze each question to determine the appropriate answer format.
    - Set "answer_format": "paragraph" for questions that require essay-type, explanatory, or analytical responses.
    Examples: "Explain...", "Discuss...", "Analyze...", "Describe...", "Examine..."
    - Set "answer_format": "points" for questions that require listing, enumeration, or identification.
    Examples: "List...", "State...", "Identify...", "Outline...", "Mention..."
    - Format answers according to the answer_format:
    * For "paragraph" format: Provide a well-structured paragraph or multiple paragraphs as a string.
    * For "points" format: Provide an array of strings, each representing a distinct point.
    - If the question has model answers or marking schemes available, format them accordingly.
    - For complex questions with multiple parts, determine the appropriate format for each part.

    IMPORTANT INSTRUCTIONS FOR METADATA:
    - For any field where the information is not available or unclear, use null instead of "Not specified" for numeric fields (year, term).
    - For text fields, if information is not available, use "Unknown" instead of "Not specified".
    - The term field must be a numeric value (1, 2, or 3) or null, not a string like "Term 1" or "Not specified".
    - The year field must be a 4-digit numeric value (e.g., 2023), not a string or "Not specified".

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
            
            // Process and validate answer formats recursively
            $this->validateAndFormatAnswers($data['questions']);
            
            return $data;
        } catch (\Exception $e) {
            Log::error('Exception parsing AI response', ['message' => $e->getMessage(), 'response' => $response]);
            return ['error' => 'Failed to parse AI response: ' . $e->getMessage()];
        }
    }
    
    /**
     * Validate and format answers recursively
     * 
     * @param array &$questions Reference to questions array
     * @return void
     */
    protected function validateAndFormatAnswers(array &$questions): void
    {
        foreach ($questions as &$question) {
            // Set default answer format if not set
            if (!isset($question['answer_format'])) {
                // Detect from question wording
                $content = strtolower($question['content']);
                if (preg_match('/(list|state|identify|outline|mention|name|give)/i', $content)) {
                    $question['answer_format'] = 'points';
                } else {
                    $question['answer_format'] = 'paragraph';
                }
            }
            
            // Format answer based on answer_format
            if (isset($question['answer']) && !empty($question['answer'])) {
                if ($question['answer_format'] === 'points') {
                    // If answer is a string but should be points, convert to array
                    if (is_string($question['answer'])) {
                        // Split by common separators or bullet points
                        $points = preg_split('/(?:\r\n|\r|\n|•|\-|\d+\.)+/', $question['answer']);
                        $points = array_filter(array_map('trim', $points));
                        $question['answer'] = array_values($points);
                    }
                } else if ($question['answer_format'] === 'paragraph') {
                    // If answer is an array but should be paragraph, convert to string
                    if (is_array($question['answer'])) {
                        $question['answer'] = implode("\n\n", $question['answer']);
                    }
                }
            }
            
            // Process sub-questions recursively
            if (isset($question['sub_questions']) && is_array($question['sub_questions'])) {
                $this->validateAndFormatAnswers($question['sub_questions']);
            }
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
        Log::info("Updated OpenAIService API key to: {$partialKey}");
    }
}