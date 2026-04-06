<?php

namespace CmrManagement\Autoresponder\Http\Controllers;

use CmrManagement\Autoresponder\Models\Enrollment;
use CmrManagement\Autoresponder\Models\SendLog;
use CmrManagement\Autoresponder\Models\Unsubscribe;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

use function CmrManagement\Autoresponder\ar_table;

class UnsubscribeController extends Controller
{
    public function show(string $token): View
    {
        try {
            $sendLog = SendLog::where('unsubscribe_token', $token)->first();

            if (! $sendLog) {
                return $this->renderView('invalid', 'en');
            }

            $lang = $sendLog->language ?? 'en';
            $email = $sendLog->to_email ?? $sendLog->email;

            $alreadyUnsubscribed = Unsubscribe::where('email', $email)->exists();

            if ($alreadyUnsubscribed) {
                return $this->renderView('already', $lang, ['email' => $email]);
            }

            return $this->renderView('form', $lang, [
                'email' => $email,
                'token' => $token,
            ]);
        } catch (\Throwable $e) {
            Log::error('Autoresponder unsubscribe show failed', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);

            return $this->renderView('error', 'en');
        }
    }

    public function process(Request $request, string $token): View
    {
        try {
            $sendLog = SendLog::where('unsubscribe_token', $token)->first();

            if (! $sendLog) {
                return $this->renderView('invalid', 'en');
            }

            $lang = $sendLog->language ?? 'en';
            $email = $sendLog->to_email ?? $sendLog->email;

            Unsubscribe::firstOrCreate(
                ['email' => $email],
                [
                    'send_log_id' => $sendLog->id,
                    'reason' => $request->input('reason'),
                    'unsubscribed_at' => now(),
                ]
            );

            Enrollment::where('email', $email)
                ->where('state', 'active')
                ->each(function (Enrollment $enrollment) {
                    $enrollment->markUnsubscribed();
                });

            return $this->renderView('success', $lang, ['email' => $email]);
        } catch (\Throwable $e) {
            Log::error('Autoresponder unsubscribe process failed', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);

            return $this->renderView('error', $sendLog->language ?? 'en');
        }
    }

    private function renderView(string $view, string $lang, array $data = []): View
    {
        $translations = config('autoresponder.unsubscribe_translations', []);
        $langTranslations = $translations[$lang] ?? $translations['en'] ?? [];

        return view("autoresponder::unsubscribe.{$view}", array_merge($data, [
            'translations' => $langTranslations,
            'language' => $lang,
            'languages' => config('autoresponder.languages', []),
        ]));
    }
}
