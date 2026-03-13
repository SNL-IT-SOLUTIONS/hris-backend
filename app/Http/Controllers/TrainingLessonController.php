<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TrainingLesson;

class TrainingLessonController extends Controller
{

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
    public function getLesson($id)
    {
        $lesson = TrainingLesson::find($id);

        if (!$lesson) {
            return response()->json([
                'success' => false,
                'message' => 'Lesson not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'lesson' => $lesson
        ]);
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
        $lesson = TrainingLesson::find($id);

        if (!$lesson) {
            return response()->json([
                'success' => false,
                'message' => 'Lesson not found'
            ], 404);
        }

        $lesson->update([
            'lesson_title' => $request->lesson_title ?? $lesson->lesson_title,
            'lesson_description' => $request->lesson_description ?? $lesson->lesson_description
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lesson updated successfully',
            'lesson' => $lesson
        ]);
    }


    // Delete lesson
    public function deleteLesson($id)
    {
        $lesson = TrainingLesson::find($id);

        if (!$lesson) {
            return response()->json([
                'success' => false,
                'message' => 'Lesson not found'
            ], 404);
        }

        $lesson->delete();

        return response()->json([
            'success' => true,
            'message' => 'Lesson deleted successfully'
        ]);
    }
}
