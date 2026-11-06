<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Survey;
use App\Models\SurveyStudent;
use App\Models\User;
use Database\Seeders\SurveySeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SurveyRosterImportTest extends TestCase
{
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
    }

    private function admin(): User
    {
        return User::where('email', 'daan@test.nl')->firstOrFail();
    }

    private function upload(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('roster.csv', $contents);
    }

    public function test_admin_can_import_example_and_claim_as_participant(): void
    {
        $file = file_get_contents(base_path('examples/survey-roster.csv'));
        $this->actingAs($this->admin())->post('/survey/import', ['roster' => $this->upload($file)])
            ->assertRedirect('/surveytable')->assertSessionHas('message');
        $survey = Survey::where('survey_code', 'class-2026')->firstOrFail();
        $this->assertSame(3, $survey->surveyStudents()->count());
        $student = $survey->surveyStudents()->where('name', 'Alice')->firstOrFail();
        $this->assertTrue(Hash::check('apple', $student->claim_word_hash));
        $this->assertNotSame('apple', $student->claim_word_hash);
        $this->assertNotEmpty($student->uuid);
        $this->flushSession();
        $this->withSession(['survey-id' => $survey->id])
            ->post('/roster/claim', ['name' => 'Alice', 'secret_word' => 'APPLE'])
            ->assertRedirect('/')->assertSessionHas('student-id', $student->id);
    }

    public function test_access_is_limited_to_administrators_and_enabled_roster_mode(): void
    {
        $this->get('/survey/import')->assertRedirect();
        $normal = User::where('email', 'tom@test.nl')->firstOrFail();
        $this->actingAs($normal)->get('/survey/import')->assertForbidden();
        $this->actingAs($normal)->get('/survey/import/example')->assertForbidden();
        $this->actingAs($normal)->post('/survey/import', ['roster' => $this->upload("survey_code,name,secret_word\nclass,Alice,apple\n")])->assertForbidden();
        $this->actingAs($this->admin())->get('/survey/import')->assertOk()->assertSee('name="roster"', false);
        $this->actingAs($this->admin())->get('/survey/import/example')->assertOk();
        config(['app.roster_claim' => false]);
        $this->actingAs($this->admin())->get('/survey/import')->assertNotFound();
        $this->actingAs($this->admin())->post('/survey/import', ['roster' => $this->upload("survey_code,name,secret_word\nclass,Alice,apple\n")])->assertNotFound();
    }

    public function test_invalid_files_never_create_partial_surveys(): void
    {
        $files = [
            "ab\n\nAlice,apple\n",
            "=FORMULA\n\nAlice,apple\n",
            "class\n\n",
            "class\n\nAlice,apple\nBob,bad\n",
            "class\n\nAlice,apple\nalice,grape\n",
            "class\n\nAlice,apple\nBob,grape,extra\n",
            "class\n\n=FORMULA,apple\n",
            "class\n\n\xFF,apple\n",
            "wrong,class\n\nname,secret_word\nAlice,apple\n",
            "survey_code,ab\n\nname,secret_word\nAlice,apple\n",
            "survey_code,=FORMULA\n\nname,secret_word\nAlice,apple\n",
            "survey_code,class\n\nwrong,secret_word\nAlice,apple\n",
            "survey_code,class\n\nname,secret_word\n",
            "survey_code,class\n\nname,secret_word\nAlice,apple\nBob,bad\n",
            "survey_code,class\n\nname,secret_word\nAlice,apple\nalice,grape\n",
            "survey_code,class\n\nname,secret_word\nAlice,apple\nBob,grape,extra\n",
            "survey_code,class\n\nname,secret_word\n=FORMULA,apple\n",
            "survey_code,class\n\nname,secret_word\n\xFF,apple\n",
            "wrong,name,secret_word\nclass,Alice,apple\n",
            "survey_code,name,secret_word\n",
            "survey_code,name,secret_word\nclass,Alice,apple\nclass,Bob,bad\n",
            "survey_code,name,secret_word\nclass,Alice,apple\nother,Bob,grape\n",
            "survey_code,name,secret_word\nclass,Alice,apple\nclass,alice,grape\n",
            "survey_code,name,secret_word\nclass,Alice,apple\nclass,Bob,grape,extra\n",
            "survey_code,name,secret_word\nclass,=FORMULA,apple\n",
            "survey_code,name,secret_word\n=FORMULA,Alice,apple\n",
            "survey_code,name,secret_word\nclass,\xFF,apple\n",
        ];
        foreach ($files as $csv) {
            $this->actingAs($this->admin())->post('/survey/import', ['roster' => $this->upload($csv)])
                ->assertSessionHasErrors('roster');
            $this->assertSame(0, Survey::count());
            $this->assertSame(0, SurveyStudent::count());
        }
    }

    public function test_upload_size_and_participant_limits_are_enforced(): void
    {
        $csv = "class\n\n";
        for ($i = 1; $i <= 501; $i++) {
            $csv .= "Student $i,apple\n";
        }
        $this->actingAs($this->admin())->post('/survey/import', ['roster' => $this->upload($csv)])
            ->assertSessionHasErrors('roster');
        $this->actingAs($this->admin())->post('/survey/import', [
            'roster' => UploadedFile::fake()->create('roster.csv', 257),
        ])->assertSessionHasErrors('roster');
        $this->assertSame(0, Survey::count());
    }

    public function test_maximum_roster_size_imports_successfully(): void
    {
        config(['hashing.bcrypt.rounds' => 4]);
        $csv = "class\n\n";
        for ($i = 1; $i <= 500; $i++) {
            $csv .= "Student $i,apple\n";
        }
        $this->actingAs($this->admin())->post('/survey/import', ['roster' => $this->upload($csv)])
            ->assertRedirect('/surveytable');
        $this->assertSame(500, Survey::where('survey_code', 'class')->firstOrFail()->surveyStudents()->count());
    }

    public function test_database_collation_conflicts_roll_back_all_records(): void
    {
        $csv = "class\n\nJose,apple\nJosé,grape\n";
        $this->actingAs($this->admin())->post('/survey/import', ['roster' => $this->upload($csv)])
            ->assertSessionHasErrors('roster');
        $this->assertSame(0, Survey::count());
        $this->assertSame(0, SurveyStudent::count());
    }

    public function test_existing_survey_is_not_modified(): void
    {
        $survey = Survey::create(['survey_code' => 'class']);
        $csv = "class\n\nAlice,apple\n";
        $this->actingAs($this->admin())->post('/survey/import', ['roster' => $this->upload($csv)])
            ->assertSessionHasErrors('roster');
        $this->assertSame(1, Survey::count());
        $this->assertSame(0, $survey->surveyStudents()->count());
    }

    public function test_bom_quoted_names_and_case_insensitive_words_are_supported(): void
    {
        $csv = "\xEF\xBB\xBFclass\r\n\r\n\"Smith, Alice\",ApPlE\r\n";
        $this->actingAs($this->admin())->post('/survey/import', ['roster' => $this->upload($csv)])
            ->assertRedirect('/surveytable');
        $student = SurveyStudent::where('name', 'Smith, Alice')->firstOrFail();
        $this->assertTrue(Hash::check('apple', $student->claim_word_hash));
    }

    public function test_previous_format_is_still_supported(): void
    {
        $csv = "survey_code,name,secret_word\nclass,Alice,apple\nclass,Bob,grape\n";
        $this->actingAs($this->admin())->post('/survey/import', ['roster' => $this->upload($csv)])
            ->assertRedirect('/surveytable');
        $this->assertSame(2, Survey::where('survey_code', 'class')->firstOrFail()->surveyStudents()->count());
    }

    public function test_blank_separator_is_optional(): void
    {
        $csv = "class\nAlice,apple\n";
        $this->actingAs($this->admin())->post('/survey/import', ['roster' => $this->upload($csv)])
            ->assertRedirect('/surveytable');
        $this->assertSame(1, Survey::where('survey_code', 'class')->firstOrFail()->surveyStudents()->count());
    }

    public function test_labelled_format_is_still_supported(): void
    {
        $csv = "survey_code,class\n\nname,secret_word\nAlice,apple\n";
        $this->actingAs($this->admin())->post('/survey/import', ['roster' => $this->upload($csv)])
            ->assertRedirect('/surveytable');
        $this->assertSame(1, Survey::where('survey_code', 'class')->firstOrFail()->surveyStudents()->count());
    }
}
