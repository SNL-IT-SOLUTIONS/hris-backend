<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TrainingLesson;

class TrainingLessonController extends Controller
{

    // Get lesson structure with modules, questions, and choices
    public function getLessonStructure($lessonId)
    {
        try {

            $lesson = TrainingLesson::with([
                'modules.questions.choices'
            ])->findOrFail($lessonId);

            return response()->json([
                'success' => true,
                'lesson' => $lesson
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Lesson not found'
            ], 404);
        }
    }


    // Get all lessons
    public function getLessons()
    {
        $lessons = TrainingLesson::orderBy('id', 'asc')->get();

        return response()->json([
            'success' => true,
            'lessons' => $lessons
        ]);
    }


    // Get single lesson
    public function getLessonById($id)
    {
        try {

            $lesson = TrainingLesson::findOrFail($id);

            return response()->json([
                'success' => true,
                'lesson' => $lesson
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Lesson not found'
            ], 404);
        }
    }


    // Create lesson
    public function createLesson(Request $request)
    {
        $request->validate([
            'lesson_title' => 'required|string|max:255',
            'lesson_description' => 'nullable|string'
        ]);

        $lesson = TrainingLesson::create([
            'lesson_title' => $request->lesson_title,
            'lesson_description' => $request->lesson_description
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lesson created successfully',
            'lesson' => $lesson
        ]);
    }


    // Update lesson
    public function updateLesson(Request $request, $id)
    {
        try {

            $lesson = TrainingLesson::findOrFail($id);

            $lesson->update([
                'lesson_title' => $request->lesson_title ?? $lesson->lesson_title,
                'lesson_description' => $request->lesson_description ?? $lesson->lesson_description
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Lesson updated successfully',
                'lesson' => $lesson
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Lesson not found'
            ], 404);
        }
    }


    // Delete lesson
    public function deleteLesson($id)
    {
        try {

            $lesson = TrainingLesson::findOrFail($id);

            $lesson->delete();

            return response()->json([
                'success' => true,
                'message' => 'Lesson deleted successfully'
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Lesson not found'
            ], 404);
        }
    }
}
