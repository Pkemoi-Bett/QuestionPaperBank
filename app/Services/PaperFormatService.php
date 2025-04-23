<?php

namespace App\Services;

use App\Models\QuestionPaper;
use Illuminate\Support\HtmlString;

class PaperFormatService
{
    /**
     * Generate HTML content for PDF from answer data
     *
     * @param array $answerData The answer data
     * @return string The HTML content
     */
    public function generateHTMLFromAnswers(array $answerData): string
    {
        $metadata = $answerData['metadata'] ?? [];
        $questions = $answerData['questions'] ?? [];
        
        $html = $this->getHTMLHeader($metadata);
        $html .= $this->renderQuestions($questions);
        $html .= $this->getHTMLFooter();
        
        return $html;
    }
    
    /**
     * Generate HTML header with metadata
     *
     * @param array $metadata The metadata
     * @return string The HTML header
     */
    protected function getHTMLHeader(array $metadata): string
    {
        $title = $metadata['subject'] ?? 'Exam';
        $examiner = $metadata['examiner'] ?? 'Unknown';
        $class = $metadata['class'] ?? 'Unknown';
        $curriculum = $metadata['curriculum'] ?? 'Unknown';
        $year = $metadata['year'] ?? date('Y');
        $term = isset($metadata['term']) ? "Term {$metadata['term']}" : 'Not specified';
        $paperType = $metadata['paper_type'] ?? 'Not specified';
    
        $html = <<<HTML
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>{$title} Answers</title>
        <style>
            body {
                font-family: 'DejaVu Sans', Arial, sans-serif;
                margin: 20px;
                font-size: 12pt;
                line-height: 1.5;
            }
            .header {
                text-align: center;
                margin-bottom: 20px;
                border-bottom: 1px solid #000;
                padding-bottom: 10px;
            }
            .metadata {
                margin-bottom: 20px;
                display: flex;
                flex-wrap: wrap;
                justify-content: space-between;
            }
            .metadata div {
                margin-bottom: 5px;
                width: 50%;
            }
            .question {
                margin-bottom: 20px;
            }
            .question-header {
                font-weight: bold;
                margin-bottom: 5px;
            }
            .question-content {
                margin-bottom: 10px;
            }
            .answer {
                margin-left: 20px;
                margin-bottom: 15px;
                padding: 10px;
                background-color: #f5f5f5;
                border-left: 5px solid #007bff;
            }
            .points-answer li {
                margin-bottom: 5px;
            }
            .sub-question {
                margin-left: 20px;
                margin-top: 10px;
            }
            .footer {
                margin-top: 30px;
                padding-top: 10px;
                border-top: 1px solid #000;
                text-align: center;
                font-size: 10pt;
            }
            .marks {
                font-style: italic;
                font-size: 10pt;
                color: #777;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>{$title} Answers</h1>
            <h2>{$examiner}</h2>
            <h3>{$class} {$curriculum} {$year} {$term} {$paperType}</h3>
        </div>
        <div class="metadata">
            <div><strong>Subject:</strong> {$title}</div>
            <div><strong>Class:</strong> {$class}</div>
            <div><strong>Curriculum:</strong> {$curriculum}</div>
            <div><strong>Year:</strong> {$year}</div>
            <div><strong>Term:</strong> {$term}</div>
            <div><strong>Paper Type:</strong> {$paperType}</div>
        </div>
    HTML;
    
        return $html;
    }
    
    
    /**
     * Generate HTML for questions and answers
     *
     * @param array $questions The questions
     * @param int $level The question level
     * @return string The HTML content
     */
    protected function renderQuestions(array $questions, int $level = 1): string
    {
        $html = '';
        
        foreach ($questions as $question) {
            $questionNumber = $question['question_number'] ?? '';
            $content = $question['content'] ?? '';
            $marks = $question['marks'] ?? null;
            $marksText = $marks ? " ({$marks} marks)" : '';
            
            $html .= '<div class="question">';
            
            // Question header with number
            $html .= "<div class='question-header'>{$questionNumber}. <span class='marks'>{$marksText}</span></div>";
            
            // Question content
            $html .= "<div class='question-content'>{$content}</div>";
            
            // Answer
            if (isset($question['answer']) && !empty($question['answer'])) {
                $answerFormat = $question['answer_format'] ?? 'paragraph';
                $answer = $question['answer'];
                
                if ($answerFormat === 'points') {
                    $html .= "<div class='answer'>";
                    $html .= "<strong>Answer:</strong>";
                    $html .= "<ul class='points-answer'>";
                    
                    if (is_array($answer)) {
                        foreach ($answer as $point) {
                            $html .= "<li>{$point}</li>";
                        }
                    } else {
                        // Try to split string into points
                        $points = preg_split('/(?:\r\n|\r|\n|•|\-|\d+\.)+/', $answer);
                        $points = array_filter(array_map('trim', $points));
                        
                        foreach ($points as $point) {
                            $html .= "<li>{$point}</li>";
                        }
                    }
                    
                    $html .= "</ul>";
                    $html .= "</div>";
                } else {
                    $html .= "<div class='answer'>";
                    $html .= "<strong>Answer:</strong>";
                    $html .= "<p>{$answer}</p>";
                    $html .= "</div>";
                }
            }
            
            // Process sub-questions recursively
            if (isset($question['sub_questions']) && is_array($question['sub_questions']) && !empty($question['sub_questions'])) {
                $html .= "<div class='sub-question'>";
                $html .= $this->renderQuestions($question['sub_questions'], $level + 1);
                $html .= "</div>";
            }
            
            $html .= '</div>';
        }
        
        return $html;
    }
    
    /**
     * Generate HTML footer
     *
     * @return string The HTML footer
     */
    protected function getHTMLFooter(): string
    {
        $date = date('Y-m-d H:i:s');
        
        $html = <<<HTML
            <div class="footer">
                <p>Generated on {$date}</p>
            </div>
        </body>
        </html>
        HTML;
        
        return $html;
    }
}