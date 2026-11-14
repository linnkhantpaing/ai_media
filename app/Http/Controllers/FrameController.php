<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FrameController extends Controller
{
    public function projects(Request $request): Response
    {
        $frames = [
            'draft' => 'drafts',
            'awaiting_review' => 'awaitingReview',
            'processing' => 'processing',
            'completed' => 'completed',
            'needs_attention' => 'needsAttention',
        ];
        $status = $request->query('status');
        abort_if($status !== null && (! is_string($status) || ! isset($frames[$status])), 404);

        return Inertia::render('frame', ['frame' => $status === null ? 'allProjects' : $frames[$status]]);
    }

    public function show(Request $request): Response
    {
        return Inertia::render('frame', ['frame' => $request->route()->defaults['frame']]);
    }
}
