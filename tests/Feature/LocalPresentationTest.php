<?php

namespace Tests\Feature;

use Tests\TestCase;

class LocalPresentationTest extends TestCase
{
    public function test_local_survey_defaults_to_english_without_debug_output(): void
    {
        if (config('app.default_session_locale') !== 'en' || config('app.debug') || ! config('app.hide_environment_ribbon')) {
            $this->markTestSkipped('Requires the standalone local Compose presentation settings.');
        }

        $this->get('/')
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('Welcome to this survey!')
            ->assertDontSee('StepID:')
            ->assertDontSee('StudentName:')
            ->assertDontSee('ribbon-wrapper-green');
    }

    public function test_explicit_language_selection_overrides_default(): void
    {
        if (config('app.default_session_locale') !== 'en') {
            $this->markTestSkipped('Requires the standalone local Compose presentation settings.');
        }

        $this->get('/auth/local/login?lang=nl')
            ->assertOk()
            ->assertSee('lang="nl"', false);
    }
}
