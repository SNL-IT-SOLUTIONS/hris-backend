<?php

namespace App\Http\Controllers;

use App\Models\TrainingModule;
use Illuminate\Http\Request;

class TrainingModuleController extends Controller
{
    public function getModules()
    {
        $modules = TrainingModule::all();

        return response()->json([
            'success' => true,
            'modules' => $modules
        ]);
    }

    public function getModuleQuestions($moduleId)
    {
        $module = TrainingModule::with('questions.choices')
            ->findOrFail($moduleId);

        return response()->json([
            'success' => true,
            'module' => $module
        ]);
    }
}
