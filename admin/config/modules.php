<?php

/**
 * Canonical registry of gateable admin modules (the sidebar sections).
 *
 * A role grants access to a subset of these keys. The role-gate middleware
 * maps a request's route NAME → a module key (by prefix), and the sidebar
 * hides modules the current member's role doesn't include. Owners bypass.
 *
 *   key => [
 *     'label'  => human label (sidebar + roles matrix),
 *     'routes' => route-name prefixes that belong to this module,
 *   ]
 */
return [
    'dashboard'    => ['label' => 'Dashboard',       'routes' => ['dashboard']],
    'assistant'    => ['label' => 'Team Assistant',  'routes' => ['assistant']],
    'conversations'=> ['label' => 'Conversations',   'routes' => ['sessions']],
    'messages'     => ['label' => 'Messages',        'routes' => ['chat']],
    'contacts'     => ['label' => 'Contacts',        'routes' => ['contacts']],
    'leads'        => ['label' => 'Leads',           'routes' => ['leads']],
    'agents'       => ['label' => 'Agents',          'routes' => ['bot-agents']],
    'channels'     => ['label' => 'Channels',        'routes' => ['channels']],
    'data_sources' => ['label' => 'Data Sources',    'routes' => ['data-sources']],
    'flows'        => ['label' => 'Flow Builder',    'routes' => ['flows']],
    // Split deliberately. These were one module, gated by `byo_llm` (Scale
    // only) — which locked Starter and Growth out of the knowledge-tier
    // toggles as well, purely as a side effect of sharing a key. They are two
    // different products at two different tiers:
    //   bot_strategy   — which sources the bot may draw on   (paid plans)
    //   brain_settings — LLM provider + CPU/GPU, i.e. BYO model (top tier)
    'bot_strategy'  => ['label' => 'Bot Strategy',   'routes' => ['bot-strategy']],
    'brain_settings'=> ['label' => 'Brain Settings', 'routes' => ['brain-settings']],
    'skills'       => ['label' => 'Skills',          'routes' => ['skills']],
    'telephony'    => ['label' => 'Telephony',       'routes' => ['telephony']],
    'voices'       => ['label' => 'Voices',          'routes' => ['voices', 'agent-voices']],
    'widget'       => ['label' => 'Widget',          'routes' => ['widget-settings']],
    'compute'      => ['label' => 'Compute Mesh',    'routes' => ['compute']],
    'profile'      => ['label' => 'Project Profile', 'routes' => ['project-profile']],
    'team'         => ['label' => 'Team & Roles',    'routes' => ['invitations', 'roles', 'members']],
];
