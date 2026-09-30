<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\ExamPaperController;
use App\Http\Controllers\Api\ExamRoomController;
use App\Http\Controllers\Api\InvigilationController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\ScoreController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/register', [AuthController::class, 'register']);
});

Route::middleware(['api', 'auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });

    Route::prefix('questions')->group(function () {
        Route::get('/', [QuestionController::class, 'index']);
        Route::post('/', [QuestionController::class, 'store']);
        Route::get('/categories', [QuestionController::class, 'categories']);
        Route::post('/categories', [QuestionController::class, 'storeCategory']);
        Route::get('/{question}', [QuestionController::class, 'show']);
        Route::put('/{question}', [QuestionController::class, 'update']);
        Route::delete('/{question}', [QuestionController::class, 'destroy']);
    });

    Route::prefix('exam-papers')->group(function () {
        Route::get('/', [ExamPaperController::class, 'index']);
        Route::post('/', [ExamPaperController::class, 'store']);
        Route::get('/{examPaper}', [ExamPaperController::class, 'show']);
        Route::put('/{examPaper}', [ExamPaperController::class, 'update']);
        Route::delete('/{examPaper}', [ExamPaperController::class, 'destroy']);
        Route::post('/{examPaper}/questions', [ExamPaperController::class, 'addQuestions']);
        Route::delete('/{examPaper}/questions/{question}', [ExamPaperController::class, 'removeQuestion']);
    });

    Route::prefix('exams')->group(function () {
        Route::get('/', [ExamController::class, 'index']);
        Route::post('/{examPaper}/start', [ExamController::class, 'start']);
        Route::get('/{examPaper}/questions', [ExamController::class, 'getQuestions']);
        Route::post('/{examPaper}/submit', [ExamController::class, 'submit']);
        Route::get('/records', [ExamController::class, 'myRecords']);
        Route::get('/records/{record}', [ExamController::class, 'showRecord']);

        // 线下机房座位：学生查询本人座位、签到、进度心跳
        Route::get('/seats/my', [InvigilationController::class, 'mySeat']);
        Route::post('/seats/checkin', [InvigilationController::class, 'checkin']);
        Route::post('/seats/progress', [InvigilationController::class, 'progress']);
    });

    // 教务：机房与场次管理、座位导入
    Route::prefix('exam-rooms')->group(function () {
        Route::get('/', [ExamRoomController::class, 'roomsIndex']);
        Route::post('/', [ExamRoomController::class, 'roomsStore']);
        Route::put('/{examRoom}', [ExamRoomController::class, 'roomsUpdate']);
        Route::delete('/{examRoom}', [ExamRoomController::class, 'roomsDestroy']);
    });

    Route::prefix('exam-sessions')->group(function () {
        Route::get('/', [ExamRoomController::class, 'sessionsIndex']);
        Route::post('/', [ExamRoomController::class, 'sessionsStore']);
        Route::get('/{examSession}', [ExamRoomController::class, 'sessionsShow']);
        Route::put('/{examSession}', [ExamRoomController::class, 'sessionsUpdate']);
        Route::delete('/{examSession}', [ExamRoomController::class, 'sessionsDestroy']);
        Route::get('/{examSession}/seats', [ExamRoomController::class, 'seatsIndex']);
        Route::post('/{examSession}/seats/import', [ExamRoomController::class, 'seatsImport']);
        Route::delete('/{examSession}/seats/{seat}', [ExamRoomController::class, 'seatsDestroy']);
    });

    // 巡考：扫码查看、异常、换座、监考日志
    Route::prefix('invigilation')->group(function () {
        Route::get('/sessions', [InvigilationController::class, 'sessionsIndex']);
        Route::post('/seats/lookup', [InvigilationController::class, 'seatLookup']);
        Route::get('/sessions/{examSession}/seats/{seatNo}', [InvigilationController::class, 'seatShow']);
        Route::post('/seats/{seat}/anomalies', [InvigilationController::class, 'reportAnomaly']);
        Route::post('/seats/{seat}/change', [InvigilationController::class, 'changeSeat']);
        Route::post('/anomalies/{anomaly}/resolve', [InvigilationController::class, 'resolveAnomaly']);
        Route::get('/sessions/{examSession}/anomalies', [InvigilationController::class, 'anomaliesIndex']);
        Route::get('/sessions/{examSession}/logs', [InvigilationController::class, 'logsIndex']);
    });

    Route::prefix('scores')->group(function () {
        Route::get('/statistics', [ScoreController::class, 'statistics']);
        Route::get('/ranking/{examPaper}', [ScoreController::class, 'ranking']);
        Route::get('/analysis/{examPaper}', [ScoreController::class, 'analysis']);
    });
});
