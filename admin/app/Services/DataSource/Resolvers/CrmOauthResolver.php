<?php

namespace App\Services\DataSource\Resolvers;

use App\Models\DataSource;
use App\Services\CrmProviders\HubSpotClient;
use App\Services\CrmProviders\PipedriveClient;
use App\Services\CrmProviders\SalesforceClient;
use App\Services\CrmProviders\TokenVault;
use App\Services\CrmProviders\ZohoClient;
use App\Services\DataSource\ResolverInterface;
use App\Services\DataSource\ResolverResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tier 2 — third-party CRM via OAuth.
 *
 * config = {
 *   provider:      'hubspot' | 'salesforce' | 'pipedrive' | 'zoho',
 *   access_token:  '...'  (encrypted at rest),
 *   refresh_token: '...'  (encrypted at rest),
 *   expires_at:    unix_ts,
 *   scopes:        [...],
 *   hub_id:        int|null,         // hubspot
 *   instance_url:  string|null,      // salesforce
 *   api_domain:    string|null,      // pipedrive, zoho
 * }
 *
 * On resolve: decrypt tokens, refresh if near expiry, fan out to the
 * provider client, flatten results into prompt-injectable rows.
 */
class CrmOauthResolver implements ResolverInterface
{
    /** Whitelisted providers. */
    private const PROVIDERS = ['hubspot', 'salesforce', 'pipedrive', 'zoho'];

    /** Refresh if we're within this many seconds of expiry. */
    private const REFRESH_SKEW = 60;

    /** Cap total rows we feed back to the LLM per source. */
    private const MAX_ROWS = 20;

    public function __construct(
        private HubSpotClient $hubspot,
        private SalesforceClient $salesforce,
        private PipedriveClient $pipedrive,
        private ZohoClient $zoho,
        private TokenVault $vault,
    ) {}

    public function type(): string
    {
        return DataSource::TYPE_CRM_OAUTH;
    }

    public function resolve(string $userQuery, DataSource $source, array $context = []): ResolverResult
    {
        try {
            $cfg = $this->vault->decryptConfig($source->config ?? []);

            $provider = $cfg['provider'] ?? null;
            if (!in_array($provider, self::PROVIDERS, true)) {
                return ResolverResult::error($source->id, $source->type, "Unsupported CRM provider '{$provider}'");
            }

            $cfg = $this->ensureFreshToken($source, $cfg);
            if (empty($cfg['access_token'])) {
                return ResolverResult::error($source->id, $source->type, 'OAuth token unavailable / refresh failed');
            }

            return match ($provider) {
                'hubspot'    => $this->resolveHubSpot($userQuery, $source, $cfg),
                'salesforce' => $this->resolveSalesforce($userQuery, $source, $cfg),
                'pipedrive'  => $this->resolvePipedrive($userQuery, $source, $cfg),
                'zoho'       => $this->resolveZoho($userQuery, $source, $cfg),
                default      => ResolverResult::error($source->id, $source->type, "No handler for '{$provider}'"),
            };
        } catch (Throwable $e) {
            Log::error("CrmOauthResolver crashed for source #{$source->id}: ".$e->getMessage());
            return ResolverResult::error($source->id, $source->type, $e->getMessage());
        }
    }

    /**
     * Search contacts/companies/deals in parallel-ish (sequential for MVP)
     * and merge into a flat row set capped at MAX_ROWS.
     */
    private function resolveHubSpot(string $userQuery, DataSource $source, array $cfg): ResolverResult
    {
        $token = (string) $cfg['access_token'];

        $contacts  = $this->hubspot->searchContacts($token,  $userQuery, 10);
        $companies = $this->hubspot->searchCompanies($token, $userQuery, 5);
        $deals     = $this->hubspot->searchDeals($token,     $userQuery, 5);

        $rows = [];

        foreach ($contacts as $c) {
            $p = $c['properties'] ?? [];
            $rows[] = [
                'type'           => 'contact',
                'id'             => $c['id'] ?? null,
                'firstname'      => $p['firstname']      ?? null,
                'lastname'       => $p['lastname']       ?? null,
                'email'          => $p['email']          ?? null,
                'phone'          => $p['phone']          ?? null,
                'company'        => $p['company']        ?? null,
                'lifecyclestage' => $p['lifecyclestage'] ?? null,
            ];
        }

        foreach ($companies as $c) {
            $p = $c['properties'] ?? [];
            $rows[] = [
                'type'     => 'company',
                'id'       => $c['id'] ?? null,
                'name'     => $p['name']     ?? null,
                'domain'   => $p['domain']   ?? null,
                'phone'    => $p['phone']    ?? null,
                'industry' => $p['industry'] ?? null,
                'city'     => $p['city']     ?? null,
                'country'  => $p['country']  ?? null,
            ];
        }

        foreach ($deals as $d) {
            $p = $d['properties'] ?? [];
            $rows[] = [
                'type'      => 'deal',
                'id'        => $d['id'] ?? null,
                'dealname'  => $p['dealname']  ?? null,
                'amount'    => $p['amount']    ?? null,
                'dealstage' => $p['dealstage'] ?? null,
                'pipeline'  => $p['pipeline']  ?? null,
                'closedate' => $p['closedate'] ?? null,
            ];
        }

        $rows = array_slice($rows, 0, self::MAX_ROWS);

        if (empty($rows)) {
            return ResolverResult::empty($source->id, $source->type);
        }

        return ResolverResult::records($source->id, $source->type, $rows, [
            'provider' => 'hubspot',
            'hub_id'   => $cfg['hub_id'] ?? null,
        ]);
    }

    /**
     * Salesforce: Contact / Account / Opportunity via parameterizedSearch.
     * Each `searchRecord` is mostly flat (Salesforce inlines fields).
     */
    private function resolveSalesforce(string $userQuery, DataSource $source, array $cfg): ResolverResult
    {
        $token       = (string) $cfg['access_token'];
        $instanceUrl = isset($cfg['instance_url']) ? (string) $cfg['instance_url'] : null;

        $contacts  = $this->salesforce->searchContacts($token,  $userQuery, 10, $instanceUrl);
        $companies = $this->salesforce->searchCompanies($token, $userQuery, 5,  $instanceUrl);
        $deals     = $this->salesforce->searchDeals($token,     $userQuery, 5,  $instanceUrl);

        $rows = [];

        foreach ($contacts as $c) {
            $rows[] = [
                'type'      => 'contact',
                'id'        => $c['Id'] ?? null,
                'firstname' => $c['FirstName'] ?? null,
                'lastname'  => $c['LastName']  ?? null,
                'email'     => $c['Email']     ?? null,
                'phone'     => $c['Phone']     ?? null,
                'company'   => $c['Account']['Name'] ?? null,
            ];
        }

        foreach ($companies as $a) {
            $rows[] = [
                'type'     => 'company',
                'id'       => $a['Id'] ?? null,
                'name'     => $a['Name']     ?? null,
                'domain'   => $a['Website']  ?? null,
                'phone'    => $a['Phone']    ?? null,
                'industry' => $a['Industry'] ?? null,
                'city'     => $a['BillingCity']    ?? null,
                'country'  => $a['BillingCountry'] ?? null,
            ];
        }

        foreach ($deals as $o) {
            $rows[] = [
                'type'      => 'deal',
                'id'        => $o['Id'] ?? null,
                'dealname'  => $o['Name']      ?? null,
                'amount'    => $o['Amount']    ?? null,
                'dealstage' => $o['StageName'] ?? null,
                'pipeline'  => $o['Account']['Name'] ?? null,
                'closedate' => $o['CloseDate'] ?? null,
            ];
        }

        $rows = array_slice($rows, 0, self::MAX_ROWS);

        if (empty($rows)) {
            return ResolverResult::empty($source->id, $source->type);
        }

        return ResolverResult::records($source->id, $source->type, $rows, [
            'provider'     => 'salesforce',
            'instance_url' => $instanceUrl,
        ]);
    }

    /**
     * Pipedrive: person / organization / deal via itemSearch.
     * Each item wraps fields under `item`.
     */
    private function resolvePipedrive(string $userQuery, DataSource $source, array $cfg): ResolverResult
    {
        $token     = (string) $cfg['access_token'];
        $apiDomain = isset($cfg['api_domain']) ? (string) $cfg['api_domain'] : null;

        $contacts  = $this->pipedrive->searchContacts($token,  $userQuery, 10, $apiDomain);
        $companies = $this->pipedrive->searchCompanies($token, $userQuery, 5,  $apiDomain);
        $deals     = $this->pipedrive->searchDeals($token,     $userQuery, 5,  $apiDomain);

        $rows = [];

        foreach ($contacts as $entry) {
            $p = $entry['item'] ?? [];
            $name = (string) ($p['name'] ?? '');
            $parts = $name !== '' ? explode(' ', $name, 2) : ['', ''];
            $emails = is_array($p['emails'] ?? null) ? $p['emails'] : [];
            $phones = is_array($p['phones'] ?? null) ? $p['phones'] : [];
            $orgName = is_array($p['organization'] ?? null) ? ($p['organization']['name'] ?? null) : null;

            $rows[] = [
                'type'      => 'contact',
                'id'        => $p['id'] ?? null,
                'firstname' => $parts[0] ?? null,
                'lastname'  => $parts[1] ?? null,
                'email'     => $emails[0] ?? null,
                'phone'     => $phones[0] ?? null,
                'company'   => $orgName,
            ];
        }

        foreach ($companies as $entry) {
            $p = $entry['item'] ?? [];
            $rows[] = [
                'type'    => 'company',
                'id'      => $p['id'] ?? null,
                'name'    => $p['name']    ?? null,
                'domain'  => $p['address'] ?? null,
                'phone'   => null,
                'city'    => null,
                'country' => null,
            ];
        }

        foreach ($deals as $entry) {
            $p = $entry['item'] ?? [];
            $orgName = is_array($p['organization'] ?? null) ? ($p['organization']['name'] ?? null) : null;
            $rows[] = [
                'type'      => 'deal',
                'id'        => $p['id'] ?? null,
                'dealname'  => $p['title']  ?? null,
                'amount'    => $p['value']  ?? null,
                'dealstage' => $p['status'] ?? null,
                'pipeline'  => $orgName,
                'closedate' => null,
            ];
        }

        $rows = array_slice($rows, 0, self::MAX_ROWS);

        if (empty($rows)) {
            return ResolverResult::empty($source->id, $source->type);
        }

        return ResolverResult::records($source->id, $source->type, $rows, [
            'provider'   => 'pipedrive',
            'api_domain' => $apiDomain,
        ]);
    }

    /**
     * Zoho: Contacts / Accounts / Deals via /crm/v3/{module}/search.
     */
    private function resolveZoho(string $userQuery, DataSource $source, array $cfg): ResolverResult
    {
        $token     = (string) $cfg['access_token'];
        $apiDomain = isset($cfg['api_domain']) ? (string) $cfg['api_domain'] : null;

        $contacts  = $this->zoho->searchContacts($token,  $userQuery, 10, $apiDomain);
        $companies = $this->zoho->searchCompanies($token, $userQuery, 5,  $apiDomain);
        $deals     = $this->zoho->searchDeals($token,     $userQuery, 5,  $apiDomain);

        $rows = [];

        foreach ($contacts as $c) {
            $accountName = is_array($c['Account_Name'] ?? null) ? ($c['Account_Name']['name'] ?? null) : ($c['Account_Name'] ?? null);
            $rows[] = [
                'type'      => 'contact',
                'id'        => $c['id'] ?? null,
                'firstname' => $c['First_Name'] ?? null,
                'lastname'  => $c['Last_Name']  ?? null,
                'email'     => $c['Email']      ?? null,
                'phone'     => $c['Phone']      ?? null,
                'company'   => $accountName,
            ];
        }

        foreach ($companies as $a) {
            $rows[] = [
                'type'     => 'company',
                'id'       => $a['id'] ?? null,
                'name'     => $a['Account_Name'] ?? null,
                'domain'   => $a['Website']      ?? null,
                'phone'    => $a['Phone']        ?? null,
                'industry' => $a['Industry']     ?? null,
                'city'     => $a['Billing_City']    ?? null,
                'country'  => $a['Billing_Country'] ?? null,
            ];
        }

        foreach ($deals as $d) {
            $accountName = is_array($d['Account_Name'] ?? null) ? ($d['Account_Name']['name'] ?? null) : ($d['Account_Name'] ?? null);
            $rows[] = [
                'type'      => 'deal',
                'id'        => $d['id'] ?? null,
                'dealname'  => $d['Deal_Name'] ?? null,
                'amount'    => $d['Amount']    ?? null,
                'dealstage' => $d['Stage']     ?? null,
                'pipeline'  => $accountName,
                'closedate' => $d['Closing_Date'] ?? null,
            ];
        }

        $rows = array_slice($rows, 0, self::MAX_ROWS);

        if (empty($rows)) {
            return ResolverResult::empty($source->id, $source->type);
        }

        return ResolverResult::records($source->id, $source->type, $rows, [
            'provider'   => 'zoho',
            'api_domain' => $apiDomain,
        ]);
    }

    /**
     * If the token is within REFRESH_SKEW of expiry (or already expired),
     * mint a new one via the matching provider and persist the encrypted
     * result back to the row.
     */
    private function ensureFreshToken(DataSource $source, array $cfg): array
    {
        $expiresAt = (int) ($cfg['expires_at'] ?? 0);
        if ($expiresAt > time() + self::REFRESH_SKEW) {
            return $cfg;
        }

        $provider = $cfg['provider'] ?? null;
        if (!in_array($provider, self::PROVIDERS, true) || empty($cfg['refresh_token'])) {
            return $cfg;
        }

        $fresh = match ($provider) {
            'hubspot'    => $this->hubspot->refresh((string) $cfg['refresh_token']),
            'salesforce' => $this->salesforce->refresh((string) $cfg['refresh_token']),
            'pipedrive'  => $this->pipedrive->refresh((string) $cfg['refresh_token']),
            'zoho'       => $this->zoho->refresh((string) $cfg['refresh_token']),
            default      => [],
        };

        if (empty($fresh['access_token'])) {
            $source->update([
                'status'     => DataSource::STATUS_EXPIRED,
                'last_error' => ucfirst((string) $provider).' refresh_token rotation failed',
                'update_at'  => time(),
            ]);
            $cfg['access_token'] = '';
            return $cfg;
        }

        $cfg['access_token']  = $fresh['access_token'];
        $cfg['refresh_token'] = $fresh['refresh_token'] ?: ($cfg['refresh_token'] ?? '');
        $cfg['expires_at']    = time() + (int) ($fresh['expires_in'] ?? 0);

        // Provider-specific bookkeeping.
        if ($provider === 'hubspot' && !empty($fresh['hub_id'])) {
            $cfg['hub_id'] = $fresh['hub_id'];
        }
        if ($provider === 'salesforce' && !empty($fresh['instance_url'])) {
            $cfg['instance_url'] = $fresh['instance_url'];
        }
        if (in_array($provider, ['pipedrive', 'zoho'], true) && !empty($fresh['api_domain'])) {
            $cfg['api_domain'] = $fresh['api_domain'];
        }

        $source->update([
            'config'    => $this->vault->encryptConfig($cfg),
            'status'    => DataSource::STATUS_ACTIVE,
            'update_at' => time(),
        ]);

        return $cfg;
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        $provider = $config['provider'] ?? null;
        if (!in_array($provider, self::PROVIDERS, true)) {
            $errors['provider'] = 'Unsupported CRM provider. Allowed: '.implode(', ', self::PROVIDERS);
        }

        return $errors;
    }

    public function needsSync(): bool
    {
        return false;
    }

    public function sync(DataSource $source): void
    {
        // OAuth-backed sources are live-queried; nothing to ingest.
    }
}
