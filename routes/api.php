<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\QuestionPaperController;
use App\Http\Controllers\AnswerController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::post('/question-papers/upload', [QuestionPaperController::class, 'apiUpload']);
Route::get('/question-papers', [QuestionPaperController::class, 'apiFetchQuestionPapers']);
Route::get('/question-papers/filters', [QuestionPaperController::class, 'apiGetFilters']);
Route::get('/question-papers/{id}', [QuestionPaperController::class, 'apiGetQuestionPaper']);
Route::get('papers/{id}/with-answers', [AnswerController::class, 'fetchPaperWithAnswers']);