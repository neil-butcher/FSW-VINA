<?php

namespace App\Http\Controllers;

use App\Models\SurveyStudent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RosterClaimController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        abort_unless(config('app.roster_claim'), 404);
        if (! $request->session()->has('survey-id')) {
            return redirect('/');
        }

        return view('roster.claim');
    }

    public function claim(Request $request): RedirectResponse
    {
        abort_unless(config('app.roster_claim'), 404);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'secret_word' => ['required', 'string', 'regex:/\A[a-zA-Z]{5}\z/'],
        ]);
        $surveyId = $request->session()->get('survey-id');
        $rateKey = 'roster-claim:'.hash('sha256', $surveyId.':'.mb_strtolower($validated['name']));
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            throw ValidationException::withMessages([
                'secret_word' => __('Too many attempts. Please wait before trying again.'),
            ]);
        }
        $nonce = bin2hex(random_bytes(32));

        $student = DB::transaction(function () use ($validated, $surveyId, $nonce) {
            $student = SurveyStudent::where('survey_id', $surveyId)
                ->where('name', $validated['name'])
                ->lockForUpdate()
                ->first();

            if (! $student || $student->finished_at !== null || ! $student->claim_word_hash
                || ! Hash::check(strtolower($validated['secret_word']), $student->claim_word_hash)) {
                return null;
            }

            $student->claim_session_hash = hash('sha256', $nonce);
            $student->save();

            return $student;
        });

        if (! $student) {
            RateLimiter::hit($rateKey, 900);
            throw ValidationException::withMessages(['secret_word' => __('Incorrect name or secret word, or the questionnaire is complete.')]);
        }
        RateLimiter::clear($rateKey);

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put([
            'survey-id' => $student->survey_id,
            'student-id' => $student->id,
            'student-name' => $student->name,
            'roster-claim-nonce' => $nonce,
        ]);

        return redirect('/');
    }

    public function finish(Request $request): RedirectResponse
    {
        abort_unless(config('app.roster_claim'), 404);
        $studentId = $request->session()->get('student-id');
        $nonce = $request->session()->get('roster-claim-nonce');
        abort_unless($studentId && $nonce && $request->session()->get('roster-ready-to-finish'), 403);

        $student = SurveyStudent::with('survey')->whereKey($studentId)
            ->where('claim_session_hash', hash('sha256', $nonce))
            ->whereNull('finished_at')
            ->firstOrFail();
        $updated = SurveyStudent::whereKey($studentId)
            ->where('claim_session_hash', hash('sha256', $nonce))
            ->whereNull('finished_at')->update(['finished_at' => now(), 'claim_session_hash' => null]);
        abort_unless($updated === 1, 403);

        $link = null;
        if ($student->survey->qualtrics_id && $student->survey->qualtrics_param) {
            $link = 'https://survey.uu.nl/jfe/form/'.rawurlencode($student->survey->qualtrics_id)
                .'?'.http_build_query([$student->survey->qualtrics_param => $student->uuid]);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('roster-finished', true)->with('roster-qualtrics-link', $link);
    }

    public function leave(Request $request): RedirectResponse
    {
        abort_unless(config('app.roster_claim'), 404);
        $studentId = $request->session()->get('student-id');
        $nonce = $request->session()->get('roster-claim-nonce');
        abort_unless($studentId && $nonce, 403);

        SurveyStudent::whereKey($studentId)
            ->where('claim_session_hash', hash('sha256', $nonce))
            ->update(['claim_session_hash' => null]);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('roster-left', true);
    }
}
