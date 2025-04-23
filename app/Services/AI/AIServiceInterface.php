<?php

namespace App\Services\AI;

interface AIServiceInterface
{
    /**
     * Analyze document content and extract structured information
     * 
     * @param string $text The document text to analyze
     * @return array The structured analysis result
     */
    public function analyzeDocumentContent(string $text): array;

    public function extractMetadata(string $text): array;
    
    /**
     * Extract questions from the document text
     *
     * @param string $text The document text
     * @return array The extracted questions
     */
    public function extractQuestions(string $text): array;
    

    /**
     * Generate content for answers
     *
     * @param string $prompt The prompt for answer generation
     * @return array The generated content
     */
    public function generateContent(string $prompt): array;
    
    /**
     * Update API key
     * 
     * @param string $apiKey The new API key
     * @return void
     */
    public function updateAPIKey(string $apiKey): void;
    //public function generateContent(string $prompt, Question $question): mixed;
}