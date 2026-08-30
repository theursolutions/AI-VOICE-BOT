<?php

/**
 * Mail channel configuration. Merged under the `mail_channel` key by
 * MailChannelServiceProvider. Per-mailbox credentials live in the
 * `email_accounts` table, not here — this only holds sweep-wide defaults.
 */
return [
    // How often the inbound sweep runs (app/Console/Kernel.php schedules the
    // `email:poll-inbound` command at this cadence — change both together).
    'poll_interval_minutes' => env('MAIL_CHANNEL_POLL_MINUTES', 2),

    // Cap per mailbox per poll, so one account with a flooded inbox can't
    // starve the queue worker of time for every other project's mailbox.
    'max_fetch_per_poll' => env('MAIL_CHANNEL_MAX_FETCH', 25),

    // Defaults offered on the "connect a mailbox" form.
    'defaults' => [
        'imap_port'       => 993,
        'imap_encryption' => 'ssl',
        'smtp_port'       => 587,
        'smtp_encryption' => 'tls',
    ],

    // "Connect Google" — a SEPARATE OAuth app from anything used for
    // dashboard sign-in (see App\Http\Controllers\Admin\EmailOAuthController
    // for why that separation matters).
    'google' => [
        'client_id'     => env('GOOGLE_MAIL_CLIENT_ID'),
        'client_secret' => env('GOOGLE_MAIL_CLIENT_SECRET'),
        // https://mail.google.com/ is REQUIRED for raw IMAP/SMTP XOAUTH2 —
        // the narrower gmail.readonly/gmail.send Gmail-API scopes do not
        // work for protocol-level access at all.
        'scopes'          => env('GOOGLE_MAIL_SCOPES', 'https://mail.google.com/ email openid'),
        'authorize_base'  => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url'       => 'https://oauth2.googleapis.com/token',
        'userinfo_url'    => 'https://www.googleapis.com/oauth2/v2/userinfo',
        'imap_host'       => 'imap.gmail.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
        'smtp_host'       => 'smtp.gmail.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
    ],

    // "Connect Microsoft" — Outlook.com and Microsoft 365. This is the path
    // that actually matters: Microsoft has deprecated Basic Auth (password)
    // for IMAP/SMTP on these mailboxes, so OAuth is the ONLY way in.
    'microsoft' => [
        'client_id'     => env('MICROSOFT_MAIL_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_MAIL_CLIENT_SECRET'),
        // 'common' = both personal Microsoft accounts and work/school
        // (Microsoft 365) tenants — the broadest-compatibility endpoint.
        'authority'     => env('MICROSOFT_MAIL_AUTHORITY', 'https://login.microsoftonline.com/common'),
        'scopes'        => env('MICROSOFT_MAIL_SCOPES', 'https://outlook.office365.com/IMAP.AccessAsUser.All https://outlook.office365.com/SMTP.Send offline_access email openid User.Read'),
        'graph_userinfo_url' => 'https://graph.microsoft.com/v1.0/me',
        'imap_host'     => 'outlook.office365.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
        'smtp_host'     => 'smtp.office365.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
    ],
];
