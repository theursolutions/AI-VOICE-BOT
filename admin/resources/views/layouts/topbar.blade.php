<style>
    .tva-tb-brand {
        display:flex; align-items:center; gap:10px;
        text-decoration:none; color:inherit;
        padding:4px 10px; border-radius:10px;
        transition: background .15s;
    }
    .tva-tb-brand:hover { background: rgba(15,23,42,.06); }
    html.dark .tva-tb-brand:hover { background: rgba(255,255,255,.06); }
    .tva-tb-logo {
        width:36px; height:36px; border-radius:9px;
        background: var(--tva-gradient); color:#fff;
        display:flex; align-items:center; justify-content:center;
        font-weight:700; font-size:13px;
        overflow:hidden; flex-shrink:0;
        box-shadow: 0 2px 6px rgba(99,102,241,.25);
    }
    .tva-tb-logo img { width:100%; height:100%; object-fit:cover; }
    /* Company/project name as a pill rather than loose text, so it reads as a
       distinct badge next to the logo instead of floating in the bar. */
    .tva-tb-brand__name {
        display:inline-flex; align-items:center;
        font-size:13px; font-weight:600; line-height:1;
        color:#0f172a;
        padding:5px 11px; border-radius:999px;
        background: rgba(15,23,42,.05);
        border:1px solid rgba(15,23,42,.08);
        max-width:220px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
        transition: background .15s, border-color .15s;
    }
    .tva-tb-brand:hover .tva-tb-brand__name {
        background: rgba(15,23,42,.08);
        border-color: rgba(15,23,42,.14);
    }
    .tva-tb-brand__sub   { font-size:11px; color:#64748b; margin-top:3px; padding-left:11px; }
    html.dark .tva-tb-brand__name {
        color:#f1f5f9;
        background: rgba(255,255,255,.07);
        border-color: rgba(255,255,255,.12);
    }
    html.dark .tva-tb-brand:hover .tva-tb-brand__name {
        background: rgba(255,255,255,.11);
        border-color: rgba(255,255,255,.18);
    }
    @media (max-width: 640px) {
        .tva-tb-brand__text { display: none; }
    }
</style>

<div class="top-bar">
    {{-- Active project brand (logo + name). Replaces the static breadcrumb. --}}
    @php
        $tvaLogoPath = $tvaProfile['logo_path'] ?? null;
        // asset() depends on APP_URL, which is often mis-set in dev.
        // Derive the URL from the running script dir so it always works.
        $tvaLogoUrl = null;
        if ($tvaLogoPath) {
            $tvaUrlBase = request()->getSchemeAndHttpHost() . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
            $tvaLogoUrl = $tvaUrlBase . '/storage/' . ltrim($tvaLogoPath, '/');
        }
        $tvaProjectName = $tvaProject?->name ?? config('app.name', 'Serve AI');
        $tvaInitials = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $tvaProjectName) ?: 'P', 0, 2));
        $tvaProfileUrl = (request()->route('client') && $tvaProject)
            ? route('project-profile.index', ['client' => is_object(request()->route('client')) ? request()->route('client')->slug : request()->route('client'), 'project_id' => $tvaProject->id])
            : null;
    @endphp
    @if ($tvaProfileUrl)
        <a href="{{ $tvaProfileUrl }}" class="-intro-x mr-auto tva-tb-brand" title="Edit project profile">
            <div class="tva-tb-logo">
                @if ($tvaLogoUrl)
                    <img src="{{ $tvaLogoUrl }}" alt="{{ $tvaProjectName }}">
                @else
                    {{ $tvaInitials }}
                @endif
            </div>
            <div class="tva-tb-brand__text">
                <div class="tva-tb-brand__name">{{ $tvaProjectName }}</div>
                @if (!empty($tvaProfile['industry']))
                    <div class="tva-tb-brand__sub">{{ $tvaProfile['industry'] }}</div>
                @endif
            </div>
        </a>
    @else
        <div class="-intro-x mr-auto tva-tb-brand">
            <div class="tva-tb-logo">{{ $tvaInitials }}</div>
            <div class="tva-tb-brand__text">
                <div class="tva-tb-brand__name">{{ $tvaProjectName }}</div>
            </div>
        </div>
    @endif

    <div id="create-company-modal" class="modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content"> <a data-tw-dismiss="modal" href="javascript:;"> <i data-lucide="x" class="w-8 h-8 text-slate-400"></i> </a>
                <div class="modal-body p-0">
                    <div class="intro-y box">
                            <div class="flex flex-col sm:flex-row items-center p-5 border-b border-slate-200/60 dark:border-darkmode-400">
                                <h2 class="font-medium text-base mr-auto">
                                    Input
                                </h2>
                                <div class="form-check form-switch w-full sm:w-auto sm:ml-auto mt-3 sm:mt-0">
                                    <label class="form-check-label ml-0" for="show-example-1">Show example code</label>
                                    <input id="show-example-1" data-target="#input" class="show-code form-check-input mr-0 ml-3" type="checkbox">
                                </div>
                            </div>
                            <div id="input" class="p-5">
                                <div class="preview">
                                    <div>
                                        <label for="regular-form-1" class="form-label">Input Text</label>
                                        <input id="regular-form-1" type="text" class="form-control" placeholder="Input text">
                                    </div>
                                    <div class="mt-3">
                                        <label for="regular-form-2" class="form-label">Rounded</label>
                                        <input id="regular-form-2" type="text" class="form-control form-control-rounded" placeholder="Rounded">
                                    </div>
                                    <div class="mt-3">
                                        <label for="regular-form-3" class="form-label">With Help</label>
                                        <input id="regular-form-3" type="text" class="form-control" placeholder="With help">
                                        <div class="form-help">Lorem Ipsum is simply dummy text of the printing and typesetting industry.</div>
                                    </div>
                                    <div class="mt-3">
                                        <label for="regular-form-4" class="form-label">Password</label>
                                        <input id="regular-form-4" type="password" class="form-control" placeholder="Password">
                                    </div>
                                    <div class="mt-3">
                                        <label for="regular-form-5" class="form-label">Disabled</label>
                                        <input id="regular-form-5" type="text" class="form-control" placeholder="Disabled" disabled="">
                                    </div>
                                </div>
                            </div>
                        </div>
                    <div class="px-5 pb-8 text-center"> <button type="button" data-tw-dismiss="modal" class="btn btn-primary w-24">Ok</button> </div>
                </div>
            </div>
        </div>
    </div>
    <!-- BEGIN: Workspace Switcher -->
    <div class="intro-x mr-3 sm:mr-6">
        @include('partials.workspace-switcher')
    </div>
    <!-- END: Workspace Switcher -->

    <!-- BEGIN: Search -->
    <div class="intro-x relative mr-3 sm:mr-6">
        <div class="search hidden sm:block">
            <input type="text" class="search__input form-control border-transparent" placeholder="Search...">
            <i data-lucide="search" class="search__icon dark:text-slate-500"></i> 
        </div>
        <a class="notification sm:hidden" href=""> <i data-lucide="search" class="notification__icon dark:text-slate-500"></i> </a>
        <div class="search-result">
            <div class="search-result__content">
                <div class="search-result__content__title">Pages</div>
                <div class="mb-5">
                    <a href="" class="flex items-center">
                        <div class="w-8 h-8 bg-success/20 dark:bg-success/10 text-success flex items-center justify-center rounded-full"> <i class="w-4 h-4" data-lucide="inbox"></i> </div>
                        <div class="ml-3">Mail Settings</div>
                    </a>
                    <a href="" class="flex items-center mt-2">
                        <div class="w-8 h-8 bg-pending/10 text-pending flex items-center justify-center rounded-full"> <i class="w-4 h-4" data-lucide="users"></i> </div>
                        <div class="ml-3">Users & Permissions</div>
                    </a>
                    <a href="" class="flex items-center mt-2">
                        <div class="w-8 h-8 bg-primary/10 dark:bg-primary/20 text-primary/80 flex items-center justify-center rounded-full"> <i class="w-4 h-4" data-lucide="credit-card"></i> </div>
                        <div class="ml-3">Transactions Report</div>
                    </a>
                </div>
                <div class="search-result__content__title">Users</div>
                <div class="mb-5">
                    <a href="" class="flex items-center mt-2">
                        <div class="w-8 h-8 image-fit">
                            <img alt="Midone - HTML Admin Template" class="rounded-full" src="{{url('/assets/dist/images/profile-6.jpg')}}">
                        </div>
                        <div class="ml-3">Robert De Niro</div>
                        <div class="ml-auto w-48 truncate text-slate-500 text-xs text-right">robertdeniro@left4code.com</div>
                    </a>
                    <a href="" class="flex items-center mt-2">
                        <div class="w-8 h-8 image-fit">
                            <img alt="Midone - HTML Admin Template" class="rounded-full" src="{{url('/assets/dist/images/profile-12.jpg')}}">
                        </div>
                        <div class="ml-3">Robert De Niro</div>
                        <div class="ml-auto w-48 truncate text-slate-500 text-xs text-right">robertdeniro@left4code.com</div>
                    </a>
                    <a href="" class="flex items-center mt-2">
                        <div class="w-8 h-8 image-fit">
                            <img alt="Midone - HTML Admin Template" class="rounded-full" src="{{url('/assets/dist/images/profile-10.jpg')}}">
                        </div>
                        <div class="ml-3">John Travolta</div>
                        <div class="ml-auto w-48 truncate text-slate-500 text-xs text-right">johntravolta@left4code.com</div>
                    </a>
                    <a href="" class="flex items-center mt-2">
                        <div class="w-8 h-8 image-fit">
                            <img alt="Midone - HTML Admin Template" class="rounded-full" src="{{url('/assets/dist/images/profile-10.jpg')}}">
                        </div>
                        <div class="ml-3">Kevin Spacey</div>
                        <div class="ml-auto w-48 truncate text-slate-500 text-xs text-right">kevinspacey@left4code.com</div>
                    </a>
                </div>
                <div class="search-result__content__title">Products</div>
                <a href="" class="flex items-center mt-2">
                    <div class="w-8 h-8 image-fit">
                        <img alt="Midone - HTML Admin Template" class="rounded-full" src="{{url('/assets/dist/images/preview-12.jpg')}}">
                    </div>
                    <div class="ml-3">Nikon Z6</div>
                    <div class="ml-auto w-48 truncate text-slate-500 text-xs text-right">Photography</div>
                </a>
                <a href="" class="flex items-center mt-2">
                    <div class="w-8 h-8 image-fit">
                        <img alt="Midone - HTML Admin Template" class="rounded-full" src="{{url('/assets/dist/images/preview-10.jpg')}}">
                    </div>
                    <div class="ml-3">Dell XPS 13</div>
                    <div class="ml-auto w-48 truncate text-slate-500 text-xs text-right">PC &amp; Laptop</div>
                </a>
                <a href="" class="flex items-center mt-2">
                    <div class="w-8 h-8 image-fit">
                        <img alt="Midone - HTML Admin Template" class="rounded-full" src="{{url('/assets/dist/images/preview-13.jpg')}}">
                    </div>
                    <div class="ml-3">Samsung Galaxy S20 Ultra</div>
                    <div class="ml-auto w-48 truncate text-slate-500 text-xs text-right">Smartphone &amp; Tablet</div>
                </a>
                <a href="" class="flex items-center mt-2">
                    <div class="w-8 h-8 image-fit">
                        <img alt="Midone - HTML Admin Template" class="rounded-full" src="{{url('/assets/dist/images/preview-3.jpg')}}">
                    </div>
                    <div class="ml-3">Nikon Z6</div>
                    <div class="ml-auto w-48 truncate text-slate-500 text-xs text-right">Photography</div>
                </a>
            </div>
        </div>
    </div>
    <!-- END: Search -->
    
    <!-- BEGIN: Notifications -->
    <div class="intro-x dropdown mr-auto sm:mr-6">
        {{-- Bell kept for layout continuity, but WITHOUT the template's demo
             notifications. There is no notification backend yet, so showing
             fabricated entries (stock avatars, lorem text, fake timestamps)
             would read as real activity on a live install. The dropdown opens
             and states plainly that there is nothing to show.
             `notification--bullet` is dropped too: the unread dot implied
             pending items that never existed. --}}
        <div class="dropdown-toggle notification cursor-pointer" role="button" aria-expanded="false" data-tw-toggle="dropdown" title="Notifications">
            <i data-lucide="bell" class="notification__icon dark:text-slate-500"></i>
        </div>
        <div class="notification-content pt-2 dropdown-menu">
            <div class="notification-content__box dropdown-content">
                <div class="notification-content__title">Notifications</div>
                <div class="flex flex-col items-center justify-center py-6 text-center">
                    <i data-lucide="bell-off" class="w-6 h-6 text-slate-400 mb-2"></i>
                    <div class="text-slate-500 text-xs">You're all caught up</div>
                </div>
            </div>
        </div>
    </div>
    <!-- END: Notifications -->
    <!-- BEGIN: Account Menu -->
    @php
        // Initials from the user's own name — first + last word, so "Umer Malik"
        // becomes "UM" and a single-word name falls back to its first letter.
        // Replaces the template's stock stock-photo avatar.
        $tvaUserName  = trim((string) (Auth::user()->name ?? ''));
        $tvaNameParts = preg_split('/\s+/', $tvaUserName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tvaUserInit  = count($tvaNameParts) > 1
            ? mb_strtoupper(mb_substr($tvaNameParts[0], 0, 1) . mb_substr(end($tvaNameParts), 0, 1))
            : mb_strtoupper(mb_substr($tvaUserName !== '' ? $tvaUserName : 'U', 0, 1));
    @endphp
    <div class="intro-x dropdown w-8 h-8">
        <div class="dropdown-toggle w-8 h-8 rounded-full overflow-hidden shadow-lg zoom-in cursor-pointer flex items-center justify-center font-medium text-xs text-white select-none"
             style="background-image: var(--tva-gradient, linear-gradient(135deg,#3b82f6,#2563eb));"
             role="button" aria-expanded="false" data-tw-toggle="dropdown"
             title="{{ $tvaUserName }}">
            {{ $tvaUserInit }}
        </div>
        <div class="dropdown-menu w-56">
            <ul class="dropdown-content bg-primary text-white">
                <li class="p-2">
                    <div class="font-medium">{{ Auth::user()->name }}</div>
                    <div class="text-xs text-white/70 mt-0.5 dark:text-slate-500">{{ Auth::user()->email }}</div>
                </li>
                <li>
                    <hr class="dropdown-divider border-white/[0.08]">
                </li>
                <li>
                    <a href="{{ route('profile.edit') }}" class="dropdown-item hover:bg-white/5"> <i data-lucide="user" class="w-4 h-4 mr-2"></i> View Profile </a>
                </li>
                <li>
                    <a href="{{ route('profile.edit') }}#update-password" class="dropdown-item hover:bg-white/5"> <i data-lucide="lock" class="w-4 h-4 mr-2"></i> Change Password </a>
                </li>
                <li>
                    <hr class="dropdown-divider border-white/[0.08]">
                </li>
                <li>
                    <a href="{{ route('logout') }}" onclick="event.preventDefault();document.getElementById('logout-form').submit();" 
                        class="dropdown-item hover:bg-white/5"> <i data-lucide="toggle-right" class="w-4 h-4 mr-2"></i> Logout 
                    </a>
                    <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                        @csrf
                    </form>
                </li>
            </ul>
        </div>
    </div>
    <!-- END: Account Menu -->
</div>