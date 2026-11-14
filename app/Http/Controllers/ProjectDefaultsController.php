<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveProjectDefaultsRequest;
use App\Services\Media\MediaBackground;
use App\Services\Media\ProjectDefaults;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectDefaultsController extends Controller
{
    public function __construct(private ProjectDefaults $defaults) {}

    public function show(): Response
    {
        return Inertia::render('settings/defaults', [
            'frame' => 'projectDefaults',
            'defaults' => $this->defaults->present(),
            'recommended' => $this->defaults->recommended(),
            'providerVoice' => $this->defaults->voiceDefaults(),
            'voices' => config('media.voices'),
            'backgroundReady' => app(MediaBackground::class)->available(),
            'saveUrl' => route('admin.settings.defaults.save', absolute: false),
        ]);
    }

    public function save(SaveProjectDefaultsRequest $request): JsonResponse
    {
        $this->defaults->save($request->validated('settings'), $request->boolean('inherit_voice'), $request->integer('revision'), $request->user('admin')->id);

        return response()->json(['defaults' => $this->defaults->present()])->header('Cache-Control', 'no-store');
    }
}
