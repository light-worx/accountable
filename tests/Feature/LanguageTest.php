<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use App\Models\TranslationOverride;
use App\Models\User;
use App\Support\Translation\Strings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class LanguageTest extends TestCase
{
    use RefreshDatabase;

    /** Runs the middleware for a person (or nobody) with an optional browser language, and reports the language chosen. */
    private function chosen(?string $userLocale, ?string $browser): string
    {
        $user = $userLocale === null ? null : User::factory()->create(['locale' => $userLocale]);

        $request = Request::create('/', 'GET', [], [], [], $browser ? ['HTTP_ACCEPT_LANGUAGE' => $browser] : []);
        $request->setUserResolver(fn () => $user);

        (new SetLocale())->handle($request, fn () => response('ok'));

        return app()->getLocale();
    }

    public function test_the_persons_choice_comes_first_then_the_browser_then_english(): void
    {
        $this->assertSame('zu', $this->chosen('zu', null));
        $this->assertSame('zu', $this->chosen('zu', 'af'));             // their own choice beats the browser
        $this->assertSame('en', $this->chosen('xx', null));             // an unknown language is ignored
        $this->assertSame('af', $this->chosen(null, 'af-ZA,af;q=0.9')); // browser, when nobody is signed in
        $this->assertSame('en', $this->chosen(null, 'fr'));
        $this->assertSame('en', $this->chosen(null, null));
    }

    public function test_every_language_has_the_core_screens_translated(): void
    {
        $keys = [
            'Money in', 'Money out', 'Move money', 'Record money received', 'Save payment',
            'There is not enough money in “:account”. It has :balance and this takes out :amount.',
        ];

        foreach (['zu', 'st', 'af'] as $locale) {
            app()->setLocale($locale);

            foreach ($keys as $key) {
                $this->assertNotSame($key, __($key), "{$locale}: not translated: {$key}");
            }
        }
    }

    public function test_untranslated_text_falls_back_to_english_and_placeholders_are_filled(): void
    {
        app()->setLocale('zu');

        $this->assertSame('A sentence nobody has translated yet', __('A sentence nobody has translated yet'));
        $this->assertStringContainsString('R-000012', __('Saved as :reference', ['reference' => 'R-000012']));
    }

    public function test_translation_files_are_valid_and_keep_their_placeholders(): void
    {
        foreach (array_diff(array_keys(config('languages.locales')), ['en']) as $locale) {
            $path = lang_path("{$locale}.json");
            $this->assertFileExists($path);

            $strings = json_decode(file_get_contents($path), true);
            $this->assertIsArray($strings, "{$locale}.json is not valid JSON");

            foreach ($strings as $english => $translated) {
                $this->assertNotSame('', trim((string) $translated), "{$locale}: empty translation for: {$english}");
                $this->assertSame(
                    Strings::placeholders($english),
                    Strings::placeholders((string) $translated),
                    "{$locale}: placeholders differ for: {$english}",
                );
            }
        }
    }

    public function test_a_correction_made_on_screen_beats_the_file(): void
    {
        TranslationOverride::create(['locale' => 'zu', 'source' => 'Money in', 'translation' => 'Imali engenayo (kulungisiwe)']);

        app()->setLocale('zu');

        $this->assertSame('Imali engenayo (kulungisiwe)', __('Money in'));
    }

    public function test_the_code_scan_finds_texts_used_with_the_translation_helper(): void
    {
        $found = Strings::inCode();

        $this->assertArrayHasKey('Record money received', $found);
        $this->assertArrayHasKey('There is not enough money in “:account”. It has :balance and this takes out :amount.', $found);
    }
}