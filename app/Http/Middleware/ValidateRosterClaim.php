<?php

namespace App\Http\Middleware;

use App\Models\SurveyStudent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateRosterClaim
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.roster_claim') && $request->session()->has('student-id')) {
            $nonce = $request->session()->get('roster-claim-nonce');
            $valid = is_string($nonce) && SurveyStudent::whereKey($request->session()->get('student-id'))
                ->where('survey_id', $request->session()->get('survey-id'))
                ->where('claim_session_hash', hash('sha256', $nonce))
                ->whereNull('finished_at')
                ->exists();
            if (! $valid) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                abort(403, 'This participant session has ended. Enter the participant code again.');
            }
        }

        $response = $next($request);
        if (config('app.roster_claim')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
