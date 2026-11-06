<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Livewire\Forms\FormStep1;
use App\Livewire\Forms\FormStepSelectStudents;
use App\Livewire\SurveyStudentTable;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\SurveyStudent;
use App\Models\User;
use Database\Seeders\SurveySeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class RosterClaimTest extends TestCase
{
    private SurveyStudent $student;

    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $testDatabase = getenv('VINA_ROSTER_TEST_DATABASE');
        if (! $testDatabase || $testDatabase !== $app['config']->get('database.connections.mysql.database')) {
            throw new \RuntimeException('Set VINA_ROSTER_TEST_DATABASE and DB_DATABASE to the same dedicated test database.');
        }

        $app->bind(SurveySeeder::class, fn () => new class extends SurveySeeder
        {
            public function run(): void {}
        });
        Artisan::call('db:reset');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        config(['app.roster_claim' => true]);
        $survey = Survey::factory()->create();
        $this->student = SurveyStudent::factory()->create([
            'survey_id' => $survey->id,
            'name' => 'Alice',
        ]);
        $this->student->claim_word_hash = Hash::make('apple');
        $this->student->save();
        RateLimiter::clear('127.0.0.1');
    }

    public function test_claim_requires_correct_name_and_word_for_the_selected_survey(): void
    {
        $this->withSession(['survey-id' => $this->student->survey_id])
            ->post('/roster/claim', ['name' => 'Alice', 'secret_word' => 'grape'])
            ->assertSessionHasErrors('secret_word');

        $this->withSession(['survey-id' => $this->student->survey_id])
            ->post('/roster/claim', ['name' => 'Bob', 'secret_word' => 'apple'])
            ->assertSessionHasErrors('secret_word');
        $this->withSession(['survey-id' => $this->student->survey_id + 9999])
            ->post('/roster/claim', ['name' => 'Alice', 'secret_word' => 'apple'])
            ->assertSessionHasErrors('secret_word');
        $this->withSession(['survey-id' => $this->student->survey_id])
            ->post('/roster/claim', ['name' => 'Alice', 'secret_word' => 'abc1'])
            ->assertSessionHasErrors('secret_word')
            ->assertSessionMissing('_old_input.secret_word');
    }

    public function test_wrong_words_are_limited_per_participant(): void
    {
        $key = 'roster-claim:'.hash('sha256', $this->student->survey_id.':alice');
        RateLimiter::clear($key);
        for ($i = 0; $i < 5; $i++) {
            $this->withSession(['survey-id' => $this->student->survey_id])
                ->post('/roster/claim', ['name' => 'Alice', 'secret_word' => 'grape'])
                ->assertSessionHasErrors('secret_word');
        }
        $this->withSession(['survey-id' => $this->student->survey_id])
            ->post('/roster/claim', ['name' => 'Alice', 'secret_word' => 'apple'])
            ->assertSessionHasErrors(['secret_word' => 'Too many attempts. Please wait before trying again.']);
        RateLimiter::clear($key);
    }

    public function test_claim_page_asks_for_name_and_secret_word(): void
    {
        $this->withSession(['survey-id' => $this->student->survey_id])
            ->get('/roster/claim')
            ->assertOk()
            ->assertSee('name="name"', false)
            ->assertSee('name="secret_word"', false)
            ->assertSee('maxlength="5"', false);
    }

    public function test_legacy_codes_are_not_accepted_until_a_word_is_set(): void
    {
        $this->student->claim_word_hash = null;
        $this->student->claim_code_hash = hash('sha256', 'abcdef1234567890');
        $this->student->save();
        $this->withSession(['survey-id' => $this->student->survey_id])
            ->post('/roster/claim', ['name' => 'Alice', 'secret_word' => 'apple'])
            ->assertSessionHasErrors('secret_word');
    }

    public function test_survey_code_step_redirects_to_claim_screen(): void
    {
        Livewire::test(FormStep1::class, [
            'jsonQuestion' => SurveyQuestion::where('order', 1)->firstOrFail(),
            'stepId' => 1,
        ])->set('surveyCode', $this->student->survey->survey_code)
            ->call('save')
            ->assertRedirect(route('roster.claim'));
    }

    public function test_claim_resumes_roster_entry_and_invalidates_previous_session(): void
    {
        $this->withSession(['survey-id' => $this->student->survey_id])
            ->post('/roster/claim', ['name' => 'Alice', 'secret_word' => 'APPLE'])
            ->assertRedirect('/')
            ->assertSessionHas('student-id', $this->student->id);

        $nonce = session('roster-claim-nonce');
        $this->assertSame(hash('sha256', $nonce), $this->student->fresh()->claim_session_hash);
        $response = $this->get('/')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->student->forceFill(['claim_session_hash' => hash('sha256', 'another-session')])->save();
        $this->get('/')->assertForbidden();
    }

    public function test_only_completed_flow_can_finish_and_clears_session(): void
    {
        $this->withSession(['survey-id' => $this->student->survey_id])
            ->post('/roster/claim', ['name' => 'Alice', 'secret_word' => 'apple'])->assertRedirect('/');
        $this->post('/roster/finish')->assertForbidden();
        $this->withSession(['roster-ready-to-finish' => true])
            ->post('/roster/finish')
            ->assertRedirect('/')
            ->assertSessionMissing('student-id');
        $this->assertNotNull($this->student->fresh()->finished_at);
        $this->withSession(['survey-id' => $this->student->survey_id])
            ->post('/roster/claim', ['name' => 'Alice', 'secret_word' => 'apple'])->assertSessionHasErrors('secret_word');
    }

    public function test_leaving_clears_the_tablet_but_the_participant_can_resume(): void
    {
        $this->withSession(['survey-id' => $this->student->survey_id])
            ->post('/roster/claim', ['name' => 'Alice', 'secret_word' => 'apple'])->assertRedirect('/');
        $this->post('/roster/leave')->assertRedirect('/')->assertSessionMissing('student-id');
        $this->assertNull($this->student->fresh()->finished_at);
        $this->assertNull($this->student->fresh()->claim_session_hash);
        $this->withSession(['survey-id' => $this->student->survey_id])
            ->post('/roster/claim', ['name' => 'Alice', 'secret_word' => 'apple'])
            ->assertRedirect('/')->assertSessionHas('student-id', $this->student->id);
    }

    public function test_only_admins_can_set_words_and_changing_one_revokes_session(): void
    {
        $admin = User::where('email', 'daan@test.nl')->firstOrFail();
        $normal = User::where('email', 'tom@test.nl')->firstOrFail();
        $this->student->forceFill(['claim_session_hash' => hash('sha256', 'old')])->save();
        $this->student->claim_code_hash = hash('sha256', 'abcdef1234567890');
        $this->student->save();
        Livewire::actingAs($normal)->test(SurveyStudentTable::class)
            ->call('prepareClaimWord', $this->student->id)->assertForbidden();
        Livewire::actingAs($normal)->test(SurveyStudentTable::class)
            ->set('claimStudentId', $this->student->id)->set('claimWord', 'grape')
            ->call('saveClaimWord')->assertForbidden();
        Livewire::actingAs($admin)->test(SurveyStudentTable::class)
            ->call('prepareClaimWord', $this->student->id)
            ->set('claimWord', 'tree')
            ->call('saveClaimWord')->assertHasErrors('claimWord')
            ->set('claimWord', 'Grape')->call('saveClaimWord')->assertHasNoErrors()
            ->assertSet('claimWord', '');
        $this->assertTrue(Hash::check('grape', $this->student->fresh()->claim_word_hash));
        $this->assertNull($this->student->fresh()->claim_session_hash);
        $this->assertNull($this->student->fresh()->claim_code_hash);
    }

    public function test_roster_names_must_be_unique_within_the_survey(): void
    {
        $admin = User::where('email', 'daan@test.nl')->firstOrFail();
        Livewire::actingAs($admin)->test(SurveyStudentTable::class)
            ->set('name', $this->student->name)
            ->set('survey_id', $this->student->survey_id)
            ->call('store')
            ->assertHasErrors(['name' => 'unique']);
        Livewire::actingAs($admin)->test(SurveyStudentTable::class)
            ->set('name', 'Another participant')
            ->set('survey_id', $this->student->survey_id)
            ->call('store')->assertHasNoErrors();
        $this->assertDatabaseHas('survey_students', [
            'name' => 'Another participant', 'survey_id' => $this->student->survey_id,
        ]);
    }

    public function test_first_participant_can_select_the_rest_of_the_roster(): void
    {
        $peer = SurveyStudent::factory()->create(['survey_id' => $this->student->survey_id]);
        $outsider = SurveyStudent::factory()->create(['survey_id' => Survey::factory()->create()->id]);
        $this->withSession(['survey-id' => $this->student->survey_id])
            ->post('/roster/claim', ['name' => 'Alice', 'secret_word' => 'apple'])->assertRedirect('/');
        $component = Livewire::test(FormStepSelectStudents::class, [
            'jsonQuestion' => SurveyQuestion::where('form_type', 'select_students')->firstOrFail(),
            'stepId' => 3,
            'savedAnswers' => null,
        ]);
        $ids = array_column($component->get('students'), 'id');
        $this->assertContains($peer->id, $ids);
        $this->assertNotContains($this->student->id, $ids);
        $this->assertNotContains($outsider->id, $ids);
    }
}
