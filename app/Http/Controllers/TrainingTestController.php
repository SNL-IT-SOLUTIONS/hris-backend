<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\EmployeeTrainingAttempt;
use App\Models\EmployeeTrainingAnswer;
use App\Models\TrainingChoice;
use App\Models\EmployeeModuleProgress;

class TrainingTestController extends Controller
{
    public function submitTest(Request $request)
    {
        $request->validate([
            'employee_id' => 'required',
            'module_id' => 'required',
            'answers' => 'required|array'
        ]);

        $attempt = EmployeeTrainingAttempt::create([
            'employee_id' => $request->employee_id,
            'module_id' => $request->module_id
        ]);

        $score = 0;
        $total = count($request->answers);

        foreach ($request->answers as $answer) {

            $choice = TrainingChoice::find($answer['choice_id']);

            $isCorrect = $choice->is_correct ? 1 : 0;

            if ($isCorrect) {
                $score++;
            }

            EmployeeTrainingAnswer::create([
                'attempt_id' => $attempt->id,
                'question_id' => $answer['question_id'],
                'choice_id' => $choice->id,
                'is_correct' => $isCorrect
            ]);
        }

        $percentage = ($score / $total) * 100;
        $passed = $percentage >= 70;

        $attempt->update([
            'score' => $percentage,
            'passed' => $passed,
            'completed_at' => now()
        ]);

        if ($passed) {
            EmployeeModuleProgress::updateOrCreate(
                [
                    'employee_id' => $request->employee_id,
                    'module_id' => $request->module_id
                ],
                [
                    'completed' => 1,
                    'completed_at' => now()
                ]
            );
        }

        return response()->json([
            'success' => true,
            'score' => $percentage,
            'passed' => $passed
        ]);
    }
}
