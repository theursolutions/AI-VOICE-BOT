<?php

namespace App\Console\Commands;

use App\Models\Flow;
use App\Models\Project;
use App\Services\Tenant\TenantManager;
use Illuminate\Console\Command;

/**
 *   php artisan flow:make-demo --project=1
 *
 * Creates a realistic IVR-style demo flow on the chosen project so you
 * can open the visual editor and see what a working flow looks like.
 *
 * Graph it builds:
 *
 *     [Start] → [Say welcome] → [Capture DTMF menu]
 *                                  ├ 1 → [Say "billing…"]  → [Transfer to AI]
 *                                  ├ 2 → [Say "orders…"]   → [Transfer to AI]
 *                                  ├ 3 → [Transfer to AI]
 *                                  ├ 0 → [Say directory]   → [End]
 *                                  └ timeout → [Say apology]→ [End]
 */
class MakeDemoFlow extends Command
{
    protected $signature = 'flow:make-demo {--project= : project id (defaults to first)} {--name=Demo IVR Flow}';
    protected $description = 'Seed a demo conversation flow into a project so you can preview the editor.';

    public function handle(TenantManager $tenants): int
    {
        $projectId = (int) $this->option('project');
        $project = $projectId
            ? Project::find($projectId)
            : Project::orderBy('id')->first();

        if (!$project) {
            $this->error('No project found.');
            return 1;
        }

        $tenants->useFor($project);
        $name = (string) $this->option('name');

        $def = $this->buildDefinition();

        $flow = Flow::create([
            'project_id'  => $project->id,
            'name'        => $name,
            'slug'        => Flow::generateSlug($name, $project->id),
            'status'      => Flow::STATUS_DRAFT,
            'definition'  => $def,
            'version'     => 1,
            'language'    => 'en',
            'description' => 'Sample flow showing welcome + DTMF menu + branches. Created by flow:make-demo.',
            'created_at'  => time(),
            'update_at'   => time(),
        ]);

        $clientSlug = $project->client?->slug ?? '?';
        $this->info("Created flow #{$flow->id} '{$flow->name}' on project '{$project->name}'");
        $this->line("Open: /c/{$clientSlug}/flows/{$flow->id}/editor");
        return 0;
    }

    private function buildDefinition(): array
    {
        // Layout: start at (80, 240), main column at x≈ every 260px;
        // DTMF branches fan out vertically.
        $nodes = [
            // Spine
            [
                'id' => 'n_start', 'type' => 'start',
                'data' => ['label' => 'Call connects'],
                'position' => ['x' => 60, 'y' => 280],
            ],
            [
                'id' => 'n_welcome', 'type' => 'say',
                'data' => [
                    'source' => 'tts',
                    'text'   => 'Hello! Thanks for calling Acme Corporation. We are happy to help.',
                    'audio_asset_id' => null,
                    'language' => '',
                ],
                'position' => ['x' => 320, 'y' => 280],
            ],
            [
                'id' => 'n_menu', 'type' => 'capture_dtmf',
                'data' => [
                    'prompt_source' => 'tts',
                    'prompt'        => 'Press 1 for billing, 2 for orders, 3 to speak with an agent, or 0 for our directory.',
                    'web_prompt'    => 'How can we help you today?',
                    'prompt_audio_asset_id' => null,
                    'language'      => '',
                    'timeout_secs'  => 8,
                    'max_digits'    => 1,
                    // Web button labels — phone uses the digits, webchat
                    // shows these as quick-reply buttons.
                    'button_labels' => [
                        '1' => 'Billing',
                        '2' => 'Orders',
                        '3' => 'Talk to an agent',
                        '0' => 'Directory',
                    ],
                ],
                'position' => ['x' => 600, 'y' => 280],
            ],

            // Branch 1: billing
            [
                'id' => 'n_say_billing', 'type' => 'say',
                'data' => [
                    'source' => 'tts',
                    'text'   => 'Connecting you to our billing assistant. One moment please.',
                    'audio_asset_id' => null,
                    'language' => '',
                ],
                'position' => ['x' => 920, 'y' => 40],
            ],
            [
                'id' => 'n_ai_billing', 'type' => 'transfer_ai',
                'data' => ['agent_id' => null, 'persona_override' => 'You are the billing assistant. Help the caller with invoices, refunds, and payment methods.'],
                'position' => ['x' => 1220, 'y' => 40],
            ],

            // Branch 2: orders
            [
                'id' => 'n_say_orders', 'type' => 'say',
                'data' => [
                    'source' => 'tts',
                    'text'   => 'Let me check on your order. One moment.',
                    'audio_asset_id' => null,
                    'language' => '',
                ],
                'position' => ['x' => 920, 'y' => 200],
            ],
            [
                'id' => 'n_ai_orders', 'type' => 'transfer_ai',
                'data' => ['agent_id' => null, 'persona_override' => 'You are the orders assistant. Look up order status, track shipments, and handle returns.'],
                'position' => ['x' => 1220, 'y' => 200],
            ],

            // Branch 3: general agent (no Say — straight to AI)
            [
                'id' => 'n_ai_general', 'type' => 'transfer_ai',
                'data' => ['agent_id' => null, 'persona_override' => ''],
                'position' => ['x' => 920, 'y' => 360],
            ],

            // Branch 0: directory then end
            [
                'id' => 'n_say_directory', 'type' => 'say',
                'data' => [
                    'source' => 'tts',
                    'text'   => 'Our team directory: dial 100 for sales, 200 for support, 300 for billing. Have a great day!',
                    'audio_asset_id' => null,
                    'language' => '',
                ],
                'position' => ['x' => 920, 'y' => 520],
            ],
            [
                'id' => 'n_end_directory', 'type' => 'end',
                'data' => ['message' => 'Goodbye!'],
                'position' => ['x' => 1220, 'y' => 520],
            ],

            // Timeout
            [
                'id' => 'n_say_timeout', 'type' => 'say',
                'data' => [
                    'source' => 'tts',
                    'text'   => 'Sorry, I did not catch that. Please call back when you are ready. Goodbye.',
                    'audio_asset_id' => null,
                    'language' => '',
                ],
                'position' => ['x' => 920, 'y' => 680],
            ],
            [
                'id' => 'n_end_timeout', 'type' => 'end',
                'data' => ['message' => 'Goodbye.'],
                'position' => ['x' => 1220, 'y' => 680],
            ],
        ];

        $edges = [
            // Spine
            $this->edge('e1', 'n_start',   'out', 'n_welcome'),
            $this->edge('e2', 'n_welcome', 'out', 'n_menu'),

            // DTMF branches
            $this->edge('e3', 'n_menu', '1',       'n_say_billing'),
            $this->edge('e4', 'n_say_billing', 'out', 'n_ai_billing'),

            $this->edge('e5', 'n_menu', '2',       'n_say_orders'),
            $this->edge('e6', 'n_say_orders', 'out', 'n_ai_orders'),

            $this->edge('e7', 'n_menu', '3',       'n_ai_general'),

            $this->edge('e8', 'n_menu', '0',       'n_say_directory'),
            $this->edge('e9', 'n_say_directory', 'out', 'n_end_directory'),

            $this->edge('e10', 'n_menu', 'timeout', 'n_say_timeout'),
            $this->edge('e11', 'n_say_timeout', 'out', 'n_end_timeout'),
        ];

        return [
            'nodes'    => $nodes,
            'edges'    => $edges,
            'settings' => [
                'language'     => 'en',
                'timeout_secs' => 8,
                'max_retries'  => 2,
            ],
        ];
    }

    private function edge(string $id, string $from, string $handle, string $to): array
    {
        return [
            'id'           => $id,
            'source'       => $from,
            'sourceHandle' => $handle,
            'target'       => $to,
            'animated'     => true,
        ];
    }
}
