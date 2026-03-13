<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TrainingModule;
use App\Models\TrainingQuestion;
use App\Models\TrainingChoice;

class TrainingAdminController extends Controller
{

    // Create Module under a Lesson
    public function createModule(Request $request)
    {
        $request->validate([
            'lesson_id'   => 'required|exists:training_lessons,id',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string'
        ]);

        $module = TrainingModule::create([
            'lesson_id'   => $request->lesson_id,
            'title'       => $request->title,
            'description' => $request->description
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Module created successfully',
            'module'  => $module
        ]);
    }


    // Get modules by lesson
    public function getModulesByLesson($lessonId)
    {
        $modules = TrainingModule::where('lesson_id', $lessonId)
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'modules' => $modules
        ]);
    }


    // Add Question to Module
    public function createQuestion(Request $request)
    {
        $request->validate([
            'module_id' => 'required|exists:training_modules,id',
            'question'  => 'required|string'
        ]);

        $question = TrainingQuestion::create([
            'module_id' => $request->module_id,
            'question'  => $request->question
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Question created successfully',
            'question' => $question
        ]);
    }


    // Add Choices to Question
    public function createChoices(Request $request)
    {
        $request->validate([
            'question_id' => 'required|exists:training_questions,id',
            'choices'     => 'required|array'
        ]);

        $choices = [];

        foreach ($request->choices as $choice) {

            $choices[] = TrainingChoice::create([
                'question_id' => $request->question_id,
                'choice_text' => $choice['choice_text'],
                'is_correct'  => $choice['is_correct']
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Choices created successfully',
            'choices' => $choices
        ]);
    }


    // Get full module structure with questions + choices
    public function getModule($id)
    {
        $module = TrainingModule::with('questions.choices')
            ->where('id', $id)
            ->first();

        if (!$module) {
            return response()->json([
                'success' => false,
                'message' => 'Module not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'module' => $module
        ]);
    }
}
