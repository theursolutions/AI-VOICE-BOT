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
    'leads'        => ['label' => 'Leads',           'routes' => ['leads']],
    'agents'       => ['label' => 'Agents',          'routes' => ['bot-agents']],
    'channels'     => ['label' => 'Channels',        'routes' => ['channels']],
    'data_sources' => ['label' => 'Data Sources',    'routes' => ['data-sources']],
    'flows'        => ['label' => 'Flow Builder',    'routes' => ['flows']],
    'bot_strategy' => ['label' => 'Bot Strategy',    'routes' => ['bot-strategy', 'brain-settings']],
    'skills'       => ['label' => 'Skills',          'routes' => ['skills']],
    'telephony'    => ['label' => 'Telephony',       'routes' => ['telephony']],
    'voices'       => ['label' => 'Voices',          'routes' => ['voices', 'agent-voices']],
    'widget'       => ['label' => 'Widget',          'routes' => ['widget-settings']],
    'compute'      => ['label' => 'Compute Mesh',    'routes' => ['compute']],
    'profile'      => ['label' => 'Project Profile', 'routes' => ['project-profile']],
    'team'         => ['label' => 'Team & Roles',    'routes' => ['invitations', 'roles', 'members']],
];
