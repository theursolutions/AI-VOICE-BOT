<?php

namespace App\Http\Controllers\LeadGen;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LeadGen\Campaign;
use App\Models\LeadGen\LeadList;
use App\Models\LeadGen\OfferProfile;
use App\Models\LeadGen\OutreachMessage;
use App\Models\LeadGen\Qualification;
use App\Models\LeadGen\Prospect;
use App\Models\LeadGen\Search;
use App\Models\LeadGen\Suppression;
use App\Services\LeadGen\LeadGenContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Msd\MailChannel\Models\EmailAccount;
use Msd\MetaChannels\Models\ChannelConnection;

/**
 * Lead Generation landing page: what is set up, what isn't, and the
 * headline numbers. Every status shown is read from real configuration —
 * a source or channel that isn't connected says so and links to where it
 * gets connected.
 */
class DashboardController extends Controller
{
    public function __construct(private LeadGenContext $context) {}

    public function index(Request $request, Client $client): View
    {
        $projects = $this->context->projects($request, $client);
        $project  = $this->context->resolve($request, $client);
        $ready    = $project && LeadGenContext::tablesReady();

        if ($project && !$ready) {
            Log::warning('leadgen.tables_missing', [
                'project_id' => $project->id,
                'fix'        => 'php artisan tenant:migrate ' . $project->id,
            ]);
        }

        $stats = null;
        $recent = collect();
        if ($ready) {
            $stats = [
                'prospects'   => Prospect::whereNull('suppressed_at')->count(),
                'review'      => Prospect::whereIn('status', ['new', 'reviewing'])->whereNull('suppressed_at')->count(),
                'high_fit'    => Qualification::where('is_current', true)->where(fn ($q) => $q->where('override_band', 'high')
                                    ->orWhere(fn ($w) => $w->whereNull('override_band')->where('band', 'high')))->count(),
                'scored'      => Qualification::where('is_current', true)->count(),
                'in_crm'      => Prospect::where('status', 'in_crm')->count(),
                'drafts'      => OutreachMessage::where('status', 'draft')->count(),
                'lists'       => LeadList::count(),
                'profiles'    => OfferProfile::count(),
                'running'     => Search::whereIn('status', [Search::QUEUED, Search::RUNNING])->count(),
                'campaigns'   => Campaign::whereIn('status', ['running', 'paused'])->count(),
                'suppressed'  => Suppression::count(),
            ];
            $recent = Search::orderByDesc('id')->limit(5)->get();
        }

        $sources = [
            [
                'name'       => 'Overture Maps places',
                'what'       => 'Open business listings (name, category, website, phone, address) — storage and commercial use permitted.',
                'configured' => (bool) config('leadgen.places.url'),
                'hint'       => 'Needs the places worker (LEADGEN_PLACES_URL) — set by the platform operator.',
            ],
            [
                'name'       => 'CSV / Excel import',
                'what'       => 'Your own prospect lists.',
                'configured' => true,
                'hint'       => null,
            ],
        ];

        $channels = [];
        if ($project) {
            $channels = [
                'email'     => EmailAccount::where('project_id', $project->id)->enabled()->count(),
                'whatsapp'  => ChannelConnection::where('project_id', $project->id)->enabled()
                    ->where('provider', ChannelConnection::PROVIDER_WHATSAPP)->count(),
                'messenger' => ChannelConnection::where('project_id', $project->id)->enabled()
                    ->whereIn('provider', ['facebook_page', 'messenger', 'instagram'])->count(),
            ];
        }

        return view('leadgen.dashboard', compact('client', 'projects', 'project', 'ready', 'stats', 'sources', 'channels', 'recent'));
    }
}
