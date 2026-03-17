<?php

namespace Modules\HexawebBaseTools\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->isAdmin()) {
            abort(403);
        }

        $logName  = $request->get('log', 'all');
        $search   = $request->get('q', '');
        $perPage  = 100;

        $query = \DB::table('activity_logs')->orderBy('id', 'desc');

        if ($logName !== 'all') {
            $query->where('log_name', $logName);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', '%' . $search . '%')
                  ->orWhere('properties', 'like', '%' . $search . '%');
            });
        }

        $total = $query->count();
        $logs  = $query->limit($perPage)->get();

        $logNames = \DB::table('activity_logs')
            ->select('log_name')
            ->distinct()
            ->orderBy('log_name')
            ->pluck('log_name');

        $descLabels = [
            'watcher_added'         => ['color' => '#5cb85c', 'label' => 'Watcher Added'],
            'watcher_removed'       => ['color' => '#f0ad4e', 'label' => 'Watcher Removed'],
            'watcher_toggle_failed' => ['color' => '#d9534f', 'label' => 'Watcher FAILED'],
            'assignee_changed'      => ['color' => '#337ab7', 'label' => 'Assignee Changed'],
            'status_changed'        => ['color' => '#9b59b6', 'label' => 'Status Changed'],
            'reply_sent'            => ['color' => '#337ab7', 'label' => 'Reply Sent'],
            'note_added'            => ['color' => '#f0ad4e', 'label' => 'Note Added'],
            'conversation_created'  => ['color' => '#5cb85c', 'label' => 'Conversation Created'],
            'error_fetching_email'  => ['color' => '#d9534f', 'label' => 'Fetch Error'],
            'login'                 => ['color' => '#777',    'label' => 'Login'],
        ];

        return view('hexawebbasetools::activity_log', compact('logs', 'logNames', 'logName', 'search', 'total', 'descLabels', 'perPage'));
    }
}
