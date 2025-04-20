<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

class AIServiceFactory
{
    /**
     * Create and return an instance of the specified AI service
     * 
     * @param string $provider The AI service provider name
     * @return AIServiceInterface The AI service instance
     */
    public static function create(string $provider): AIServiceInterface
    {
        Log::info("Creating {$provider} service");
        
        switch (strtolower($provider)) {
            case 'openai':
                return new OpenAIService();
            case 'deepseek':
                return new DeepSeekService();
            default:
                Log::warning("Unknown AI provider: {$provider}. Defaulting to OpenAI.");
                return new OpenAIService();
        }
    }
}