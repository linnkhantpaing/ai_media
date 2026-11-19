<?php

namespace App\Http\Controllers;

use App\Exceptions\MediaFailure;
use App\Models\User;
use App\Services\CustomerProfile;
use App\Services\Media\GeminiClient;
use App\Services\Media\ProviderSettings;
use App\Services\StudioPreferences;
use App\Support\NameDictionary;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PreferencesController extends Controller
{
    /** Short sentences used for narrator samples, one per narration language. */
    private const SAMPLES = [
        'my' => 'မင်္ဂလာပါ။ ဒီဇာတ်လမ်းက တိတ်ဆိတ်တဲ့ ညတစ်ညမှာ စတင်ခဲ့ပါတယ်။',
        'en' => 'Hello. This story begins on a quiet, rainy night.',
        'th' => 'สวัสดีครับ เรื่องราวนี้เริ่มต้นในคืนที่เงียบสงบ',
        'zh' => '你好。这个故事开始于一个安静的雨夜。',
        'hi' => 'नमस्ते। यह कहानी एक शांत, बरसाती रात में शुरू होती है।',
        'ko' => '안녕하세요. 이 이야기는 조용한 비 오는 밤에 시작됩니다.',
        'ja' => 'こんにちは。この物語は静かな雨の夜に始まります。',
        'es' => 'Hola. Esta historia comienza en una noche tranquila y lluviosa.',
        'id' => 'Halo. Kisah ini dimulai pada malam yang sunyi dan hujan.',
        'vi' => 'Xin chào. Câu chuyện này bắt đầu vào một đêm mưa yên tĩnh.',
    ];

    public function __construct(private StudioPreferences $preferences, private CustomerProfile $profile) {}

    public function show(Request $request): Response
    {
        return Inertia::render('settings/preferences', ['frame' => 'preferences', 'preferences' => $this->payload($request->user(), $request->session()->getId())]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['sometimes', Rule::in(['my', 'en', 'th'])],
            ...StudioPreferences::rules(),
            ...NameDictionary::rules(),
            ...CustomerProfile::rules(),
        ]);
        DB::transaction(function () use ($request, $data): void {
            $this->preferences->save($request->user(), $data);
            $this->profile->saveProfile($request->user(), $data['profile']);
        });
        if (isset($data['locale'])) {
            Cookie::queue(cookie('ui_locale', $data['locale'], 60 * 24 * 365, '/', null, $request->isSecure() || app()->isProduction(), true, false, 'lax'));
        }

        return $this->json($request->user()->fresh());
    }

    public function storePreset(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], ...StudioPreferences::rules()]);
        $preset = $this->preferences->savePreset($request->user(), $data['name'], $data['settings']);

        return $this->json($request->user(), ['preset_id' => $preset->id], 201);
    }

    public function updatePreset(Request $request, int $preset): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], ...StudioPreferences::rules()]);
        $this->preferences->savePreset($request->user(), $data['name'], $data['settings'], $preset);

        return $this->json($request->user());
    }

    public function duplicatePreset(Request $request, int $preset): JsonResponse
    {
        $copy = $this->preferences->duplicatePreset($request->user(), $preset);

        return $this->json($request->user(), ['preset_id' => $copy->id], 201);
    }

    public function destroyPreset(Request $request, int $preset): JsonResponse
    {
        $this->preferences->deletePreset($request->user(), $preset);

        return $this->json($request->user());
    }

    public function orderPresets(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:'.StudioPreferences::MAX_PRESETS], 'ids.*' => ['integer', 'distinct']]);
        $this->preferences->orderPresets($request->user(), array_map('intval', $data['ids']));

        return $this->json($request->user());
    }

    public function defaultPreset(Request $request): JsonResponse
    {
        $data = $request->validate(['preset_id' => ['present', 'nullable', 'integer']]);
        $this->preferences->setDefaultPreset($request->user(), $data['preset_id'] === null ? null : (int) $data['preset_id']);

        return $this->json($request->user());
    }

    /**
     * A short narrator sample, generated once per voice, language and speech model.
     */
    public function voiceSample(Request $request, ProviderSettings $providers, GeminiClient $gemini): HttpResponse
    {
        $data = $request->validate(['voice' => ['required', Rule::in(config('media.voices'))], 'lang' => ['required', Rule::in(array_keys(self::SAMPLES))]]);
        $features = $this->preferences->features($request->user());
        if ($features !== null && ! $features['voice_preview']) {
            return response()->json(['code' => 'feature_unavailable'], 403);
        }
        if (! $providers->ready()) {
            return response()->json(['code' => 'ai_key_missing'], 503);
        }
        $path = 'voice-samples/'.hash('sha256', implode('|', [$data['voice'], $data['lang'], $providers->resolved()['speech_model'] ?? '', self::SAMPLES[$data['lang']]])).'.wav';
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            try {
                Cache::lock('voice-sample:'.$path, 60)->block(45, function () use ($disk, $path, $gemini, $data): void {
                    if ($disk->exists($path)) {
                        return;
                    }
                    $disk->makeDirectory('voice-samples');
                    $temporary = $path.'.part';
                    $gemini->speech(self::SAMPLES[$data['lang']], $data['voice'], 'Warm, clear narration for a short sample.', $disk->path($temporary), $data['lang']);
                    $disk->move($temporary, $path);
                });
            } catch (MediaFailure $failure) {
                return response()->json(['code' => $failure->reason], $failure->reason === 'provider_rate_limit' ? 429 : 503);
            }
        }

        return $disk->response($path, 'voice-sample.wav', ['Content-Type' => 'audio/wav', 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function changeEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254'], 'password' => ['required', 'string', 'max:255']]);
        $this->profile->changeEmail($request->user(), $data['email'], $data['password']);
        $this->profile->signOutOtherDevices($request->user(), $request->session()->getId());
        $this->keepCurrentSession($request);

        return $this->json($request->user()->fresh());
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults(), function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail(__('auth.password_bytes'));
                }
            }],
        ]);
        $this->profile->changePassword($request->user(), $data['current_password'], $data['password']);
        $this->profile->signOutOtherDevices($request->user(), $request->session()->getId());
        $this->keepCurrentSession($request);

        return $this->json($request->user()->fresh());
    }

    public function savePhoto(Request $request): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp']]);
        $this->profile->saveAvatar($request->user(), $request->file('photo'));

        return $this->json($request->user()->fresh());
    }

    public function removePhoto(Request $request): JsonResponse
    {
        $this->profile->removeAvatar($request->user());

        return $this->json($request->user()->fresh());
    }

    public function avatar(Request $request): HttpResponse
    {
        return $this->profile->avatarResponse($request->user());
    }

    public function signOutDevice(Request $request, string $device): JsonResponse
    {
        $this->profile->signOutDevice($request->user(), $device, $request->session()->getId());

        return $this->json($request->user());
    }

    public function signOutOtherDevices(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Your current password is incorrect.']);
        }
        $this->profile->signOutOtherDevices($request->user(), $request->session()->getId());
        $this->keepCurrentSession($request);

        return $this->json($request->user()->fresh());
    }

    /** Keeps this browser signed in after the account's session version or password changed. */
    private function keepCurrentSession(Request $request): void
    {
        $user = $request->user()->fresh();
        $request->session()->put('customer_auth_version:'.$user->id, $user->auth_version);
        $request->session()->put('password_hash_'.Auth::getDefaultDriver(), $user->getAuthPassword());
        Auth::guard('web')->setUser($user);
    }

    /** @return array<string, mixed> */
    private function payload(User $user, ?string $session = null): array
    {
        return [
            'profile' => $this->profile->present($user, $session ?? request()->session()->getId()),
            'locale' => $user->preferred_locale ?? app()->getLocale(),
            'baseline' => $this->preferences->baseline(),
            'saved' => $this->preferences->pick($user->studio_preferences ?? []),
            'dictionary' => $user->recap_name_dictionary ?? [],
            'presets' => $this->preferences->presets($user),
            'features' => $this->preferences->features($user),
            'voices' => config('media.voices'),
            'voiceSampleReady' => app(ProviderSettings::class)->ready(),
            'limits' => ['presets' => StudioPreferences::MAX_PRESETS, 'dictionary' => NameDictionary::MAX_ENTRIES],
            'endpoints' => [
                'save' => route('settings.preferences.save', absolute: false),
                'presets' => route('settings.preferences.presets.store', absolute: false),
                'presetOrder' => route('settings.preferences.presets.order', absolute: false),
                'defaultPreset' => route('settings.preferences.presets.default', absolute: false),
                'voiceSample' => route('settings.preferences.voice-sample', absolute: false),
                'newProject' => route('projects.create', absolute: false),
                'billing' => route('account.billing', absolute: false),
                'plan' => route('account.plan', absolute: false),
                'email' => route('settings.preferences.email', absolute: false),
                'password' => route('settings.preferences.password', absolute: false),
                'photo' => route('settings.preferences.photo', absolute: false),
                'devices' => route('settings.preferences.devices.others', absolute: false),
            ],
        ];
    }

    private function json(User $user, array $extra = [], int $status = 200): JsonResponse
    {
        return response()->json([...$extra, 'preferences' => $this->payload($user)], $status)->header('Cache-Control', 'private, no-store');
    }
}
