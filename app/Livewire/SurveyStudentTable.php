<?php

namespace App\Livewire;

use App\Models\SurveyStudent;
use DateTime;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class SurveyStudentTable extends Component
{
    use AuthorizesRequests, WithPagination;

    //DataTable props
    public ?string $query = null;

    public ?string $resultCount;

    public string $orderBy = 'created_at';

    public string $orderAsc = 'desc';

    public int $perPage = 15;

    public ?array $selected = [];

    //Create, Edit, Delete, View SurveyStudent props
    public ?string $name = null;

    public ?DateTime $finished_at = null;

    public ?DateTime $exported_at = null;

    public ?int $survey_id = null;

    public ?DateTime $created_at = null;

    public ?DateTime $updated_at = null;

    public ?int $surveystudent_id = null;

    public ?SurveyStudent $surveystudent = null;

    public ?int $claimStudentId = null;

    public string $claimWord = '';

    //Multiple Selection props
    public array $selectedSurveystudents = [];

    public bool $bulkDisabled = true;

    //Update & Store Rules
    protected function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('survey_students', 'name')
                    ->where('survey_id', $this->survey_id)
                    ->ignore($this->surveystudent?->id),
            ],
            'survey_id' => ['required', 'integer', 'exists:surveys,id'],
        ];
    }

    protected array $messages = [
        //
    ];

    protected string $paginationTheme = 'bootstrap';

    public function render()
    {
        $paginatedSurveyStudents = $this->search($this->query)->orderBy($this->orderBy, $this->orderAsc)->paginate($this->perPage);
        //results count available with search only
        $this->resultCount = empty($this->query) ? null :
            $paginatedSurveyStudents->count().' '.Str::plural('surveystudent', $paginatedSurveyStudents->count()).' found';

        return view('livewire.surveystudent.components.table', compact('paginatedSurveyStudents'));
    }

    //Toggle the $bulkDisabled on or off based on the count of selected posts
    public function updatedselectedSurveyStudents()
    {
        $this->bulkDisabled = count($this->selectedSurveyStudents) < 2;
        $this->surveystudent = null;
    }

    public function store()
    {
        $validatedData = $this->validate();
        $validatedData['uuid'] = \Illuminate\Support\Str::uuid();

        \DB::transaction(function () use ($validatedData) {
            SurveyStudent::create($validatedData);
        });
        $this->refresh('SurveyStudent successfully created!');
    }

    public function prepareClaimWord(SurveyStudent $student): void
    {
        $this->authorize('administrate');
        abort_unless(config('app.roster_claim'), 404);
        $this->claimStudentId = $student->id;
        $this->claimWord = '';
        $this->resetErrorBag('claimWord');
    }

    public function saveClaimWord(): void
    {
        $this->authorize('administrate');
        abort_unless(config('app.roster_claim'), 404);
        $this->validate([
            'claimStudentId' => ['required', 'integer', 'exists:survey_students,id'],
            'claimWord' => ['required', 'string', 'regex:/\A[a-zA-Z]{5}\z/'],
        ]);
        $student = DB::transaction(function () {
            $student = SurveyStudent::whereKey($this->claimStudentId)->lockForUpdate()->firstOrFail();
            if ($student->finished_at !== null) {
                return null;
            }

            $student->claim_word_hash = Hash::make(strtolower($this->claimWord));
            $student->claim_code_hash = null;
            $student->claim_session_hash = null;
            $student->save();

            return $student;
        });
        if (! $student) {
            $this->addError('claimWord', __('A completed participant cannot receive a new secret word.'));

            return;
        }

        RateLimiter::clear('roster-claim:'.hash('sha256', $student->survey_id.':'.mb_strtolower($student->name)));
        $this->reset(['claimWord', 'claimStudentId']);
        $this->refresh(__('Secret word saved. Give it privately to the participant.'));
    }

    //Get & assign selected post props
    public function initData(SurveyStudent $surveystudent)
    {
        // assign values to public props
        $this->surveystudent = $surveystudent;
        $this->surveystudent_id = $surveystudent->id;
        $this->name = $surveystudent->name;
        $this->finished_at = $surveystudent->finished_at;
        $this->exported_at = $surveystudent->exported_at;
        $this->survey_id = $surveystudent->survey_id;
        $this->created_at = $surveystudent->created_at;
        $this->updated_at = $surveystudent->updated_at;

        $this->selectedSurveyStudent = [];
    }

    public function update()
    {
        $validatedData = $this->validate();
        $this->surveystudent->update($validatedData);
        $this->refresh('SurveyStudent successfully updated!');
    }

    public function delete()
    {
        if (! empty($this->selectedSurveyStudents)) {
            DB::transaction(function () {
                SurveyStudent::destroy($this->selectedSurveyStudents);
            });
        }

        if (! empty($this->surveystudent)) {
            DB::transaction(function () {
                $this->surveystudent->delete();
            });
        }
        $this->refresh('Successfully deleted!');
    }

    public function refresh($message)
    {
        session()->flash('message', $message);
        $this->clearFields();

        //Close the active modal
        $this->dispatch('hideModal');
    }

    public function mount() {}

    public function hydrate()
    {
        $this->resetErrorBag();
    }

    public function clearFields()
    {
        $this->reset([
            //            'selectedSurveyStudents',
            'bulkDisabled',
            'surveystudent_id',
            'name',
            'finished_at',
            'exported_at',
            'survey_id',
            'created_at',
            'updated_at',
        ]);
    }

    /**
     * This method make more sense the model file.
     **/
    public function search($query)
    {
        $surveystudent = new SurveyStudent;

        return empty($query) ? $surveystudent :
            $surveystudent->where(function ($q) use ($query) {
                $q->where('name', 'like', '%'.$query.'%')
                    ->orWhere('uuid', 'like', '%'.$query.'%');
            });
    }

    public function redirectToDetail(string $name, $id)
    {
        return redirect()->route($name, $id);
    }
}
