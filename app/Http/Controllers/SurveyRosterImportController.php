<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use League\Csv\Reader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SurveyRosterImportController extends Controller
{
    public function show(): View
    {
        abort_unless(config('app.roster_claim'), 404);

        return view('survey.import');
    }

    public function example(): BinaryFileResponse
    {
        abort_unless(config('app.roster_claim'), 404);

        return response()->download(base_path('examples/survey-roster.csv'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(config('app.roster_claim'), 404);
        $request->validate([
            'roster' => ['required', 'file', 'max:256', 'extensions:csv'],
        ]);

        $csv = Reader::from($request->file('roster')->getRealPath(), 'r');
        $csv->setEscape('');
        $rows = $csv->getRecords();
        $format = null;
        $participantHeader = false;
        $surveyCode = null;
        $participants = [];
        $names = [];

        foreach ($rows as $offset => $row) {
            $line = $offset + 1;
            if ($format === null) {
                if ($row === ['survey_code', 'name', 'secret_word']) {
                    $format = 'legacy';
                    $participantHeader = true;
                } elseif (count($row) === 1 || (count($row) === 2 && $row[0] === 'survey_code')) {
                    $format = count($row) === 1 ? 'roster' : 'labelled';
                    $participantHeader = $format === 'roster';
                    $surveyCode = trim($row[$format === 'roster' ? 0 : 1]);
                    if (! mb_check_encoding($surveyCode, 'UTF-8') || Validator::make(['survey_code' => $surveyCode], [
                        'survey_code' => ['required', 'string', 'min:3', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/', 'not_regex:/\A[=+\-@]/'],
                    ])->fails()) {
                        $this->invalid(__('The survey code on the first row is invalid.'));
                    }
                } else {
                    $this->invalid(__('The first row must contain only the survey code.'));
                }

                continue;
            }

            if (! $participantHeader) {
                if ($row !== ['name', 'secret_word']) {
                    $this->invalid(__('The participant header must be name,secret_word.'));
                }
                $participantHeader = true;

                continue;
            }

            if (count($row) !== ($format === 'legacy' ? 3 : 2) || count($participants) >= 500) {
                $this->invalid(__('Row :row has an incorrect number of fields, or the file exceeds 500 participants.', ['row' => $line]));
            }

            if ($format === 'legacy') {
                [$code, $name, $word] = array_map('trim', $row);
            } else {
                [$name, $word] = array_map('trim', $row);
                $code = $surveyCode;
            }
            if (! mb_check_encoding($code.$name.$word, 'UTF-8')) {
                $this->invalid(__('Row :row must use UTF-8 encoding.', ['row' => $line]));
            }
            $validator = Validator::make(
                ['survey_code' => $code, 'name' => $name, 'secret_word' => $word],
                [
                    'survey_code' => ['required', 'string', 'min:3', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/', 'not_regex:/\A[=+\-@]/'],
                    'name' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/', 'not_regex:/\A[=+\-@]/'],
                    'secret_word' => ['required', 'regex:/\A[a-zA-Z]{5}\z/'],
                ]
            );
            if ($validator->fails()) {
                $this->invalid(__('Row :row has an invalid survey code, participant name, or five-letter secret word.', ['row' => $line]));
            }
            if ($surveyCode !== null && $surveyCode !== $code) {
                $this->invalid(__('Every row must have the same survey code; check row :row.', ['row' => $line]));
            }
            $surveyCode = $code;
            $nameKey = mb_strtolower($name);
            if (isset($names[$nameKey])) {
                $this->invalid(__('Participant names must be unique; check row :row.', ['row' => $line]));
            }
            $names[$nameKey] = true;
            $participants[] = ['name' => $name, 'secret_word' => strtolower($word)];
        }

        if ($format === null || ! $participantHeader || $participants === []) {
            $this->invalid(__('The CSV must include a survey code and at least one participant.'));
        }

        if (Validator::make(['survey_code' => $surveyCode], [
            'survey_code' => [Rule::unique('surveys', 'survey_code')],
        ])->fails()) {
            $this->invalid(__('A survey with this code already exists. Choose a new survey code.'));
        }

        try {
            DB::transaction(function () use ($surveyCode, $participants) {
                $survey = Survey::create(['survey_code' => $surveyCode]);
                foreach ($participants as $participant) {
                    $student = $survey->surveyStudents()->make([
                        'name' => $participant['name'],
                        'uuid' => (string) Str::uuid(),
                    ]);
                    $student->claim_word_hash = Hash::make($participant['secret_word']);
                    $student->save();
                }
            });
        } catch (UniqueConstraintViolationException $exception) {
            $this->invalid(__('The survey code or a participant name conflicts with an existing entry. Nothing was imported.'));
        }

        return redirect('/surveytable')->with('message', __('Survey and :count participants imported.', ['count' => count($participants)]));
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['roster' => $message]);
    }
}
