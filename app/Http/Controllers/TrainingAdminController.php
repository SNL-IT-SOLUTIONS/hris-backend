<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TrainingModule;
use App\Models\TrainingQuestion;
use App\Models\TrainingChoice;

class TrainingAdminController extends Controller
{

    // Create Module
    public function createModule(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string'
        ]);

        $module = TrainingModule::create([
            'title' => $request->title,
            'description' => $request->description
        ]);

        return response()->json([
            'success' => true,
            'module' => $module
        ]);
    }


    // Add Question to Module
    public function createQuestion(Request $request)
    {
        $request->validate([
            'module_id' => 'required|exists:training_modules,id',
            'question' => 'required|string'
        ]);

        $question = TrainingQuestion::create([
            'module_id' => $request->module_id,
            'question' => $request->question
        ]);

        return response()->json([
            'success' => true,
            'question' => $question
        ]);
    }


    // Add Choices to Question
    public function createChoices(Request $request)
    {
        $request->validate([
            'question_id' => 'required|exists:training_questions,id',
            'choices' => 'required|array'
        ]);

        $choices = [];

        foreach ($request->choices as $choice) {

            $choices[] = TrainingChoice::create([
                'question_id' => $request->question_id,
                'choice_text' => $choice['choice_text'],
                'is_correct' => $choice['is_correct']
            ]);
        }

        return response()->json([
            'success' => true,
            'choices' => $choices
        ]);
    }


    // Get full module structure
    public function getModule($id)
    {
        $module = TrainingModule::with('questions.choices')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'module' => $module
        ]);
    }
}
