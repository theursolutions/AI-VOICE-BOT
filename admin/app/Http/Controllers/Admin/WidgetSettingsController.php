<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Flow;
use App\Models\Project;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

/**
 * Per-project widget customization. Config lives in
 * projects.schema['widget'] (JSON). The end-customer widget loads
 * /api/widget/config to render with the project's branding.
 */
class WidgetSettingsController extends Controller
{
    use \App\Http\Controllers\Concerns\EnforcesPlanFeatures;

    /** Defaults applied when a project hasn't customised yet. */
    public const DEFAULTS = [
        'primary_color'   => '#1a365d',
        'accent_color'    => '#3b82f6',
        'bot_name'        => 'Assistant',
        'welcome_title'   => 'Welcome to our Support',
        'welcome_message' => "Hi there! \u{1F44B} How can I help you today?",
        'position'        => 'bottom-right', // bottom-right | bottom-left

        // Per-button visibility. Every key defaults to true so existing
        // projects keep their current look until the owner toggles
        // anything off.
        'show_voice'         => true,  // mic button next to send (talk-to-bot)
        'show_emoji'         => true,  // emoji picker button
        'show_attach'        => true,  // attachment / file-upload button
        'show_theme_toggle'  => true,  // light/dark switch in header
        'show_reply_toggle'  => true,  // text-vs-voice reply switch in header
        'show_expand_button' => true,  // expand-to-fullscreen button in header
        'show_visitor_modes' => true,  // "New visitor" / "Returning customer" tiles on home tab
        'show_history_tab'   => true,  // bottom nav tab listing past conversations
        'show_powered_by'    => true,  // small "Powered by Serve AI" line in footer

        // Home screen. The widget opens here unless visitor modes are off,
        // in which case it goes straight to the conversation — a home screen
        // with nothing to choose on it is a click in the way.
        'home_bg_url'     => null,   // optional background image behind the greeting
        'home_greeting'   => 'Hello there.',
        'home_subtitle'   => 'How can we help?',
        'home_cta_title'  => 'Ask a question',
        'home_cta_text'   => 'Our AI agent answers instantly',

        // FAQs shown in the widget's Help tab. Each entry is
        // ['q' => '…', 'a' => '…']. Empty list hides the tab entirely, so a
        // project that never fills these in does not show an empty section.
        'faqs'            => [],
        'show_faq_tab'    => true,

        // Logo URL (web-accessible). When empty the widget falls back
        // to the emoji avatar below.
        'logo_url'        => null,
        'avatar_emoji'    => "\u{1F916}",
        'opening_hours'   => '24/7',
        'placeholder'     => 'Type your message...',
        // Domains that may load this project's widget. CORS rejects
        // any other origin. Empty list = allow all (good for dev,
        // tighten before prod). One origin per entry, scheme + host,
        // no path: "https://acme.com", "https://www.acme.com".
        'allowed_origins' => [],

        // Phase 2 — Conversation Flow that runs at the start of every
        // chat session. Same flow_id the customer bound to a phone
        // number works here. Null = no flow, free-form AI from message
        // one (original behaviour).
        'default_flow_id' => null,
    ];

    public function index(Request $request, Client $client): View
    {
        $projects = Project::where('client_id', $client->id)
            ->orderBy('name')
            ->get(['id', 'name', 'project_api_key', 'json_data']);

        $projectId = (int) ($request->query('project_id') ?: optional($projects->first())->id);
        $project = $projects->firstWhere('id', $projectId);

        $config = self::DEFAULTS;
        $flows = collect();
        if ($project) {
            $stored = data_get($project->json_data, 'widget', []);
            if (is_array($stored)) {
                $config = array_merge($config, $stored);
            }
            // Active flows for the default_flow_id dropdown. Drafts and
            // archived flows can't run live, so they're not bindable.
            app(TenantManager::class)->useFor($project);
            $flows = Flow::where('project_id', $project->id)
                ->where('status', Flow::STATUS_ACTIVE)
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'name', 'language']);
        }

        $embedSnippet = $project
            ? $this->buildEmbedSnippet($project)
            : null;

        return view('widget-settings.index', compact(
            'client', 'projects', 'project', 'projectId', 'config', 'embedSnippet', 'flows'
        ));
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id'         => 'required|integer',
            'primary_color'      => 'required|string|max:32',
            'accent_color'       => 'required|string|max:32',
            'bot_name'           => 'required|string|max:60',
            'welcome_title'      => 'required|string|max:120',
            'welcome_message'    => 'required|string|max:500',
            'position'           => 'required|in:bottom-right,bottom-left',
            'show_voice'         => 'nullable|boolean',
            'show_emoji'         => 'nullable|boolean',
            'show_attach'        => 'nullable|boolean',
            'show_theme_toggle'  => 'nullable|boolean',
            'show_reply_toggle'  => 'nullable|boolean',
            'show_expand_button' => 'nullable|boolean',
            'show_visitor_modes' => 'nullable|boolean',
            'show_history_tab'   => 'nullable|boolean',
            'show_powered_by'    => 'nullable|boolean',
            'avatar_emoji'       => 'nullable|string|max:8',
            'opening_hours'      => 'nullable|string|max:80',
            'placeholder'        => 'nullable|string|max:120',
            'logo'               => 'nullable|file|mimetypes:image/png,image/jpeg,image/gif,image/webp,image/svg+xml|max:2048',
            'remove_logo'        => 'nullable|boolean',

            'home_greeting'      => 'nullable|string|max:80',
            'home_subtitle'      => 'nullable|string|max:120',
            'home_cta_title'     => 'nullable|string|max:60',
            'home_cta_text'      => 'nullable|string|max:120',
            // Photographs only — an SVG background is an XSS vector, and this
            // one is rendered full-bleed behind the greeting.
            'home_bg'            => 'nullable|file|mimetypes:image/png,image/jpeg,image/webp|max:4096',
            'remove_home_bg'     => 'nullable|boolean',

            'show_faq_tab'       => 'nullable|boolean',
            'faq_q'              => 'nullable|array',
            'faq_q.*'            => 'nullable|string|max:200',
            'faq_a'              => 'nullable|array',
            'faq_a.*'            => 'nullable|string|max:2000',
            // Bulk import. Same accepted types as a data source, minus the
            // binary ones — an FAQ list is text by nature.
            'faq_file'           => 'nullable|file|max:1024',
            'allowed_origins'    => 'nullable|string|max:2000',
            'default_flow_id'    => 'nullable|integer',
        ]);

        // Normalise the textarea into an array of trimmed origins,
        // stripping trailing slashes and empty lines.
        $origins = [];
        foreach (preg_split('/\r?\n/', (string) ($data['allowed_origins'] ?? '')) as $line) {
            $line = rtrim(trim($line), '/');
            if ($line !== '') $origins[] = $line;
        }

        $project = Project::where('client_id', $client->id)
            ->where('id', $data['project_id'])
            ->firstOrFail();

        $json = is_array($project->json_data) ? $project->json_data : [];
        $existing = (array) ($json['widget'] ?? []);

        // Handle logo upload (or removal). Stored under public/uploads
        // so it's served by Apache directly without going through Laravel.
        $logoUrl = $existing['logo_url'] ?? null;
        if ($request->boolean('remove_logo')) {
            $this->unlinkLogo($logoUrl);
            $logoUrl = null;
        }
        if ($request->hasFile('logo')) {
            $this->unlinkLogo($logoUrl);  // out with the old
            $logoUrl = $this->storeLogo($request->file('logo'), $project->id);
        }

        // Same lifecycle for the home background.
        $homeBgUrl = $existing['home_bg_url'] ?? null;
        if ($request->boolean('remove_home_bg')) {
            $this->unlinkLogo($homeBgUrl);
            $homeBgUrl = null;
        }
        if ($request->hasFile('home_bg')) {
            $this->unlinkLogo($homeBgUrl);
            $homeBgUrl = $this->storeLogo($request->file('home_bg'), $project->id, 'home-bg');
        }

        // FAQs: the typed rows, plus anything imported from a file. The file
        // is additive rather than replacing — someone uploading a list has
        // usually already typed a couple by hand, and silently discarding
        // those is the kind of data loss a form should never do.
        $faqs = $this->faqRows($data['faq_q'] ?? [], $data['faq_a'] ?? []);
        if ($request->hasFile('faq_file')) {
            $faqs = array_merge($faqs, $this->parseFaqFile($request->file('faq_file')));
        }
        $faqs = $this->dedupeFaqs($faqs);

        $json['widget'] = array_merge(self::DEFAULTS, [
            'primary_color'      => $data['primary_color'],
            'accent_color'       => $data['accent_color'],
            'bot_name'           => $data['bot_name'],
            'welcome_title'      => $data['welcome_title'],
            'welcome_message'    => $data['welcome_message'],
            'position'           => $data['position'],
            // Booleans — every toggle reads as `1`/missing from the form,
            // hence the `?? false` rather than ?? DEFAULTS to preserve
            // user-off state.
            'show_voice'         => (bool) ($data['show_voice']         ?? false),
            'show_emoji'         => (bool) ($data['show_emoji']         ?? false),
            'show_attach'        => (bool) ($data['show_attach']        ?? false),
            'show_theme_toggle'  => (bool) ($data['show_theme_toggle']  ?? false),
            'show_reply_toggle'  => (bool) ($data['show_reply_toggle']  ?? false),
            'show_expand_button' => (bool) ($data['show_expand_button'] ?? false),
            'show_visitor_modes' => (bool) ($data['show_visitor_modes'] ?? false),
            'show_history_tab'   => (bool) ($data['show_history_tab']   ?? false),
            // Removing the "Powered by" badge is a paid feature
            // (`remove_branding`, Growth+). Forced back ON for plans without
            // it, rather than refusing the whole save — every other setting on
            // this form is legitimately theirs to change, and rejecting the
            // submit over one checkbox would lose all of it.
            'show_powered_by'    => $this->planAllows($client, 'remove_branding')
                                        ? (bool) ($data['show_powered_by'] ?? false)
                                        : true,
            'home_bg_url'        => $homeBgUrl,
            'home_greeting'      => ($data['home_greeting'] ?? '') ?: self::DEFAULTS['home_greeting'],
            'home_subtitle'      => ($data['home_subtitle'] ?? '') ?: self::DEFAULTS['home_subtitle'],
            'home_cta_title'     => ($data['home_cta_title'] ?? '') ?: self::DEFAULTS['home_cta_title'],
            'home_cta_text'      => ($data['home_cta_text'] ?? '') ?: self::DEFAULTS['home_cta_text'],
            'faqs'               => $faqs,
            'show_faq_tab'       => (bool) ($data['show_faq_tab'] ?? false),
            'avatar_emoji'       => $data['avatar_emoji']   ?? self::DEFAULTS['avatar_emoji'],
            'opening_hours'      => $data['opening_hours']  ?? self::DEFAULTS['opening_hours'],
            'placeholder'        => $data['placeholder']    ?? self::DEFAULTS['placeholder'],
            'logo_url'           => $logoUrl,
            'allowed_origins'    => $origins,
            'default_flow_id'    => !empty($data['default_flow_id']) ? (int) $data['default_flow_id'] : null,
        ]);

        $project->json_data = $json;
        $project->save();

        // The phone-call welcome audio is cached per (welcome_text +
        // voice). If the user changed the welcome message we wipe the
        // cache so the next call regenerates with the new wording.
        try {
            app(\App\Services\Telephony\WelcomeAudioService::class)
                ->invalidateForProject($project->id);
        } catch (\Throwable $e) {
            // Non-fatal — cache will still self-invalidate on next call
            // because the hash key in the filename includes the text.
        }

        return redirect()
            ->route('widget-settings.index', ['client' => $client->slug])
            ->withInput(['project_id' => $project->id])
            ->with('success', 'Widget settings saved.');
    }

    private function buildEmbedSnippet(Project $project): string
    {
        $apiKey = $project->project_api_key ?: '<your-project-api-key>';
        $base   = config('app.url', url('/'));
        return <<<HTML
<!-- Voice CRM Agent widget -->
<script>
  (function(){
    var s=document.createElement('script');
    s.src='{$base}/widget/loader.js';
    s.async=true;
    s.setAttribute('data-project-key','{$apiKey}');
    document.head.appendChild(s);
  })();
</script>
HTML;
    }

    /**
     * Pair the two parallel form arrays into FAQ rows, dropping any where
     * either half is blank — a question with no answer is worse than no FAQ,
     * because the widget would render it and leave the visitor stuck.
     *
     * @return array<int, array{q: string, a: string}>
     */
    private function faqRows(array $questions, array $answers): array
    {
        $out = [];
        foreach ($questions as $i => $q) {
            $q = trim((string) $q);
            $a = trim((string) ($answers[$i] ?? ''));
            if ($q !== '' && $a !== '') {
                $out[] = ['q' => $q, 'a' => $a];
            }
        }

        return $out;
    }

    /**
     * Read an uploaded FAQ list.
     *
     * Two shapes, both of which people actually produce:
     *   CSV/TSV  question,answer  per line (a header row is skipped)
     *   JSON     [{"q": "…", "a": "…"}]  or  [{"question": …, "answer": …}]
     *
     * A malformed file yields nothing rather than throwing: the rest of the
     * form is still valid and saving it should not fail over an import.
     *
     * @return array<int, array{q: string, a: string}>
     */
    private function parseFaqFile(UploadedFile $file): array
    {
        $raw = @file_get_contents($file->getRealPath());
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        // JSON first — it is unambiguous, so a file that parses as JSON was
        // meant as JSON.
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $out = [];
            foreach ($decoded as $row) {
                if (!is_array($row)) continue;
                $q = trim((string) ($row['q'] ?? $row['question'] ?? ''));
                $a = trim((string) ($row['a'] ?? $row['answer'] ?? ''));
                if ($q !== '' && $a !== '') $out[] = ['q' => $q, 'a' => $a];
            }
            if ($out) return $out;
        }

        // Otherwise delimited text. Tabs win when present, since an answer is
        // far more likely to contain a comma than a tab.
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $delim = str_contains($raw, "\t") ? "\t" : ',';

        $out = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') continue;
            $cols = str_getcsv($line, $delim);
            $q = trim((string) ($cols[0] ?? ''));
            $a = trim((string) ($cols[1] ?? ''));
            // Skip a header row, but only if it is the first line — a real FAQ
            // could legitimately start with the word "question".
            if ($i === 0 && strcasecmp($q, 'question') === 0) continue;
            if ($q !== '' && $a !== '') $out[] = ['q' => $q, 'a' => $a];
        }

        return $out;
    }

    /** First occurrence of each question wins; comparison ignores case. */
    private function dedupeFaqs(array $faqs): array
    {
        $seen = [];
        $out  = [];
        foreach ($faqs as $row) {
            $key = mb_strtolower($row['q']);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }

    private function storeLogo(UploadedFile $file, int $projectId, string $name = 'logo'): string
    {
        // public_path() is the admin's web root (admin/public). Files
        // dropped here are served directly by Apache, no Laravel hop.
        $rel = "uploads/widget-logos/{$projectId}";
        $abs = public_path($rel);
        if (!is_dir($abs)) {
            File::makeDirectory($abs, 0755, true);
        }
        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        // Single canonical filename per project — overwrites cleanly,
        // cache-bust via the ?v=time query string in the saved URL.
        $name = "{$name}.{$ext}";
        $file->move($abs, $name);

        // URL base: use the actual running script's directory rather
        // than APP_URL, so this works even when APP_URL is mis-set
        // (a common dev-env footgun in subfolder deploys).
        $scheme = request()->getSchemeAndHttpHost();
        $dir    = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base   = $scheme . rtrim($dir, '/');
        return "{$base}/{$rel}/{$name}?v=" . time();
    }

    private function unlinkLogo(?string $url): void
    {
        if (!$url) return;
        // Strip query string + scheme/host, leaving path relative to public.
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $path = ltrim(preg_replace('#^.*/uploads/#', 'uploads/', $path), '/');
        $abs  = public_path($path);
        if ($abs && is_file($abs)) {
            @unlink($abs);
        }
    }
}
