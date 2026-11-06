<?php

namespace Tests\Feature;

use App\Livewire\Forms\FormStep1;
use App\Livewire\Forms\FormStep2;
use App\Models\Setting;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\Translation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Livewire\Livewire;

class SurveyLanguageTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'cache.default' => 'array',
            'session.driver' => 'array',
        ]);
        $this->withoutVite();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    private function question(int $order): SurveyQuestion
    {
        return SurveyQuestion::create([
            'order' => $order,
            'question_type' => 'text',
            'question_title' => 'Test question',
            'question_content' => 'Test question',
        ]);
    }

    public function test_code_errors_use_english_setting_on_submission_and_typing(): void
    {
        Setting::create(['key' => 'locale', 'value' => 'en']);
        app()->setLocale('nl');
        $question = $this->question(1);
        Livewire::test(FormStep1::class, ['jsonQuestion' => $question, 'stepId' => 1])
            ->call('save')->assertHasErrors(['surveyCode' => 'required'])
            ->assertSee('The survey code is required.')
            ->set('surveyCode', 'AB')->assertHasErrors(['surveyCode' => 'min'])
            ->assertSee('The survey code must contain at least 3 characters.')
            ->set('surveyCode', 'UNKNOWN')->call('save')
            ->assertHasErrors('surveyCode')
            ->assertSee('This survey code does not exist. Please check the code and try again.');
    }

    public function test_dutch_setting_still_displays_dutch_errors(): void
    {
        Setting::create(['key' => 'locale', 'value' => 'nl']);
        app()->setLocale('en');
        Livewire::test(FormStep1::class, ['jsonQuestion' => $this->question(1), 'stepId' => 1])
            ->set('surveyCode', 'UNKNOWN')->call('save')
            ->assertHasErrors('surveyCode')
            ->assertSee('Deze code bestaat niet. Controleer de code en probeer het opnieuw.');
    }

    public function test_valid_code_still_starts_the_survey(): void
    {
        Setting::create(['key' => 'locale', 'value' => 'en']);
        $survey = Survey::create(['survey_code' => 'TEST01']);
        Livewire::test(FormStep1::class, ['jsonQuestion' => $this->question(1), 'stepId' => 1])
            ->set('surveyCode', 'TEST01')->call('save')
            ->assertHasNoErrors()->assertDispatched('step-up');
        $this->assertSame($survey->id, session('survey-id'));
    }

    public function test_first_name_required_message_uses_english_setting(): void
    {
        Setting::create(['key' => 'locale', 'value' => 'en']);
        app()->setLocale('nl');
        Livewire::test(FormStep2::class, ['jsonQuestion' => $this->question(2), 'stepId' => 2])
            ->call('save')->assertHasErrors(['name' => 'required'])
            ->assertSee('Your first name is required.');
    }

    public function test_translation_setup_restores_student_text_and_preserves_existing_data(): void
    {
        Setting::create(['key' => 'locale', 'value' => 'en']);
        $survey = Survey::create(['survey_code' => 'KEEP01']);
        $question = $this->question(1);
        Translation::create(['slug' => 'form.step_two', 'en' => 'Our custom instructions', 'nl' => 'Onze instructies']);

        $this->artisan('db:seed', ['--class' => 'TranslationsTableSeeder', '--force' => true])->assertExitCode(0);
        $count = Translation::count();
        $this->artisan('db:seed', ['--class' => 'TranslationsTableSeeder', '--force' => true])->assertExitCode(0);

        $this->assertSame($count, Translation::count());
        $this->assertStringContainsString('Welcome to this survey!', langDatabase('form.step_intro'));
        $this->assertSame('Our custom instructions', langDatabase('form.step_two'));
        $this->assertSame('What is your cultural background?', langDatabase('form.step_seven_country_of_origin'));
        $this->assertSame('Questions', langDatabase('nav.surveyquestiontable'));
        $this->assertSame('Administrator', langDatabase('roles.administrator'));
        $this->assertNotNull($survey->fresh());
        $this->assertNotNull($question->fresh());
    }
}
