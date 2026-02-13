<?php

namespace App\Http\Controllers;

use App\Models\AnnouncementBoard;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AnnouncementBoardController extends Controller
{
    /**
     * Retrieve all active announcements
     */
    public function getAnnouncements()
    {
        $now = Carbon::now();

        $announcements = AnnouncementBoard::with('user:id,first_name,last_name')
            ->where('is_active', 1)
            ->where('is_archived', 0)
            ->where(function ($query) use ($now) {
                $query->whereNull('publish_at')
                    ->orWhere('publish_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('expire_at')
                    ->orWhere('expire_at', '>=', $now);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'isSuccess' => true,
            'data' => $announcements
        ]);
    }

    /**
     * Create new announcement
     */
    public function createAnnouncement(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'posted_by' => 'required|exists:users,id',
            'publish_at' => 'nullable|date',
            'expire_at' => 'nullable|date|after:publish_at',
        ]);

        $validated['is_active'] = 1;
        $validated['is_archived'] = 0;

        $announcement = AnnouncementBoard::create($validated);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Announcement created successfully.',
            'data' => $announcement
        ], 201);
    }

    /**
     * Get single announcement
     */
    public function getAnnouncementById($id)
    {
        $announcement = AnnouncementBoard::with('user:id,first_name,last_name')
            ->where('is_archived', 0)
            ->find($id);

        if (!$announcement) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Announcement not found.'
            ], 404);
        }

        return response()->json([
            'isSuccess' => true,
            'data' => $announcement
        ]);
    }

    /**
     * Update announcement
     */
    public function updateAnnouncement(Request $request, $id)
    {
        $announcement = AnnouncementBoard::where('is_archived', 0)->find($id);

        if (!$announcement) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Announcement not found.'
            ], 404);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'is_active' => 'sometimes|boolean',
            'publish_at' => 'nullable|date',
            'expire_at' => 'nullable|date|after:publish_at',
        ]);

        $announcement->update($validated);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Announcement updated successfully.',
            'data' => $announcement
        ]);
    }

    /**
     * Archive announcement (Soft Delete)
     */
    public function archiveAnnouncement($id)
    {
        $announcement = AnnouncementBoard::where('is_archived', 0)->find($id);

        if (!$announcement) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Announcement not found or already archived.'
            ], 404);
        }

        $announcement->update([
            'is_archived' => 1,
            'is_active' => 0
        ]);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Announcement archived successfully.'
        ]);
    }
}
