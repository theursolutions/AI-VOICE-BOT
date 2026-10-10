<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Session;
use App\Models\Voice;
use App\Services\Conversation\SessionTokenService;
use App\Services\Voice\VoiceResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Which voice speaks: Google by default, the project's own choice when it
 * made one, an agent's voice above both. The tenant `voices` table lives
 * in an in-memory SQLite DB here so no real tenant DB is touched.
 */
class VoiceResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('tenant');
        Schema::connection('tenant')->create('voices', function (Blueprint $t) {
            $t->id();
            $t->integer('project_id');
            $t->string('provider');
            $t->string('name');
            $t->string('reference_url')->nullable();
            $t->string('external_id')->nullable();
            $t->string('language')->default('en');
            $t->string('status')->default('ready');
            $t->json('metadata')->nullable();
            $t->integer('deleted_at')->nullable();
        });
    }

    private function project(array $voiceSetting = []): Project
    {
        $project = new Project();
        $project->id = 1;
        $project->name = 'Acme';
        $project->json_data = $voiceSetting ? ['voice' => $voiceSetting] : [];

        return $project;
    }

    private function cloned(array $meta = []): Voice
    {
        return Voice::create(['project_id' => 1, 'provider' => 'coqui', 'name' => 'Umer Voice',
            'reference_url' => '/speakers/1/3.wav', 'language' => 'en', 'status' => 'ready', 'metadata' => $meta]);
    }

    public function test_nothing_chosen_means_the_default_google_voice(): void
    {
        $this->cloned();  // an un-starred upload is not a choice

        $choice = app(VoiceResolver::class)->forProject($this->project());

        $this->assertTrue($choice->isGoogle());
        $this->assertSame(config('services.voice.default_google_voice'), $choice->googleVoice);
    }

    public function test_the_project_can_pick_a_google_voice(): void
    {
        $choice = app(VoiceResolver::class)->forProject($this->project(['source' => 'google', 'google_voice' => 'Puck']));
        $this->assertSame('Puck', $choice->googleVoice);

        $unknown = app(VoiceResolver::class)->forProject($this->project(['source' => 'google', 'google_voice' => 'Nope']));
        $this->assertSame(config('services.voice.default_google_voice'), $unknown->googleVoice);
    }

    public function test_the_project_can_pick_its_own_voice(): void
    {
        $voice  = $this->cloned();
        $choice = app(VoiceResolver::class)->forProject($this->project(['source' => 'own', 'voice_id' => $voice->id]));

        $this->assertFalse($choice->isGoogle());
        $this->assertSame('/speakers/1/3.wav', $choice->speakerWav());
    }

    public function test_a_voice_starred_before_the_setting_existed_still_wins(): void
    {
        $this->cloned(['is_default' => true]);

        $this->assertFalse(app(VoiceResolver::class)->forProject($this->project())->isGoogle());
    }

    public function test_the_token_tells_the_voice_engine_which_voice_to_use(): void
    {
        $session = new Session(['channel' => 'phone']);
        $session->id = 5;
        $session->project_id = 1;
        $session->metadata = [];
        $session->setRelation('project', $this->project(['source' => 'google', 'google_voice' => 'Kore']));

        $claims = $this->claims(app(SessionTokenService::class)->mint($session));
        $this->assertSame('google', $claims['tts_provider']);
        $this->assertSame('Kore', $claims['tts_voice']);
        $this->assertNull($claims['speaker_wav']);

        // An agent's cloned voice overrides the project's Google voice.
        $session->voice_id = $this->cloned()->id;
        $claims = $this->claims(app(SessionTokenService::class)->mint($session));
        $this->assertSame('xtts', $claims['tts_provider']);
        $this->assertSame('/speakers/1/3.wav', $claims['speaker_wav']);
    }

    private function claims(string $jwt): array
    {
        $body = explode('.', $jwt)[1];

        return json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    }
}
