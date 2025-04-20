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
}