<?php
namespace App\Http\Controllers;

use App\Models\Test;
use App\Models\Question;
use App\Models\Answer;
use App\Models\Achievement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TestController extends Controller
{
    public function index()
    {
        return Test::with('questions.answers')->get();
    }

    public function show($id)
    {
        $test = Test::with('questions.answers')->find($id);
        if (!$test) {
            return response()->json(['message' => 'Тест не найден'], 404);
        }
        return $test;
    }

    public function store(Request $request)
    {
        Log::info('POST /api/tests called', ['request' => $request->all()]);

        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'achievement_name' => 'required|string|max:255', // Валидация звания
            'questions' => 'required|array|min:1',
            'questions.*.text' => 'required|string',
            'questions.*.options' => 'required|array|size:4',
            'questions.*.options.*' => 'required|string',
            'questions.*.correctAnswer' => 'required|integer|between:0,3',
        ]);

        $test = Test::create([
            'title' => $request->title,
            'description' => $request->description,
            'achievement_name' => $request->achievement_name,
            'created_by' => auth()->id(),
        ]);

        foreach ($request->questions as $questionData) {
            $question = Question::create([
                'test_id' => $test->id,
                'text' => $questionData['text'],
            ]);

            foreach ($questionData['options'] as $index => $optionText) {
                Answer::create([
                    'question_id' => $question->id,
                    'text' => $optionText,
                    'is_correct' => $index === $questionData['correctAnswer'],
                ]);
            }
        }

        return response()->json(['message' => 'Test created successfully', 'test' => $test], 201);
    }

    public function submit(Request $request, Test $test)
    {
        $request->validate([
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|exists:questions,id',
            'answers.*.answer_id' => 'required|exists:answers,id',
        ]);

        $user = auth()->user();
        $correctAnswers = 0;
        $totalQuestions = $test->questions()->count();

        foreach ($request->answers as $answerData) {
            $answer = Answer::find($answerData['answer_id']);
            if ($answer && $answer->is_correct) {
                $correctAnswers++;
            }

            \App\Models\UserTestAnswer::create([
                'user_id' => $user->id,
                'test_id' => $test->id,
                'question_id' => $answerData['question_id'],
                'answer_id' => $answerData['answer_id'],
            ]);
        }

        if ($correctAnswers === $totalQuestions && $test->achievement_name) {
            $achievement = Achievement::firstOrCreate(
                ['name' => $test->achievement_name],
                ['description' => "Звание за успешное прохождение теста: {$test->title}"]
            );

            $user->achievements()->syncWithoutDetaching([$achievement->id]);
        }

        return response()->json([
            'correct_answers' => $correctAnswers,
            'total_questions' => $totalQuestions,
            'achievement_earned' => $correctAnswers === $totalQuestions && $test->achievement_name ? $test->achievement_name : null,
        ]);
    }
}
