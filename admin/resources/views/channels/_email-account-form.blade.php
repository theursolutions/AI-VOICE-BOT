{{--
    One form used for both "Connect a mailbox" (create) and "Edit mailbox".
    $acct is null on create (fields blank, passwords required) or an
    EmailAccount on edit (fields prefilled, passwords optional — blank keeps
    what's on file, per EmailAccountController::update()).
--}}
@php
    $isEdit = $acct !== null;
    $defaults = config('mail_channel.defaults', []);
@endphp
<div id="{{ $modalId }}" class="tva-modal" hidden>
    <div class="tva-modal__backdrop" data-tva-modal-close></div>
    <form method="POST" action="{{ $action }}" class="tva-modal__panel email-account-form" style="max-width:560px;">
        @csrf
        <input type="hidden" name="project_id" value="{{ $projectId }}">
        <div class="tva-modal__head">
            <i data-lucide="mail" class="w-4 h-4 mr-2 inline" style="color:#f59e0b;"></i>
            {{ $title }}
            <button type="button" data-tva-modal-close class="ml-auto"><i data-lucide="x" class="w-4 h-4"></i></button>
        </div>
        <div class="tva-modal__body">
            <div class="grid grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="form-label">Label <span class="text-xs text-slate-400">(optional)</span></label>
                    <input type="text" name="label" maxlength="191" class="form-control" value="{{ $acct->label ?? '' }}" placeholder="e.g. Support inbox">
                </div>
                <div>
                    <label class="form-label">From name</label>
                    <input type="text" name="from_name" maxlength="191" class="form-control" value="{{ $acct->from_name ?? '' }}" placeholder="e.g. Acme Support">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Mailbox address <span class="text-danger">*</span></label>
                <input type="email" name="from_email" required maxlength="191" class="form-control" value="{{ $acct->from_email ?? '' }}" placeholder="support@yourcompany.com">
            </div>

            <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider mt-2 mb-2">Incoming mail (IMAP)</div>
            <div class="grid grid-cols-3 gap-3 mb-2">
                <div style="grid-column:span 2;">
                    <label class="form-label">Host <span class="text-danger">*</span></label>
                    <input type="text" name="imap_host" required maxlength="191" class="form-control" value="{{ $acct->imap_host ?? '' }}" placeholder="imap.yourcompany.com">
                </div>
                <div>
                    <label class="form-label">Port <span class="text-danger">*</span></label>
                    <input type="number" name="imap_port" required class="form-control" value="{{ $acct->imap_port ?? ($defaults['imap_port'] ?? 993) }}">
                </div>
            </div>
            <div class="grid grid-cols-3 gap-3 mb-3">
                <div>
                    <label class="form-label">Encryption</label>
                    <select name="imap_encryption" class="form-select">
                        @foreach (['ssl' => 'SSL/TLS', 'tls' => 'STARTTLS', 'none' => 'None'] as $v => $l)
                            <option value="{{ $v }}" @selected(($acct->imap_encryption ?? ($defaults['imap_encryption'] ?? 'ssl')) === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" name="imap_username" required maxlength="191" class="form-control" value="{{ $acct->imap_username ?? '' }}">
                </div>
                <div>
                    <label class="form-label">Password {{ $isEdit ? '' : '*' }}</label>
                    <input type="password" name="imap_password" {{ $isEdit ? '' : 'required' }} maxlength="512" class="form-control" placeholder="{{ $isEdit ? 'Leave blank to keep' : '' }}" autocomplete="new-password">
                </div>
            </div>

            <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider mt-2 mb-2">Outgoing mail (SMTP)</div>
            <div class="grid grid-cols-3 gap-3 mb-2">
                <div style="grid-column:span 2;">
                    <label class="form-label">Host <span class="text-danger">*</span></label>
                    <input type="text" name="smtp_host" required maxlength="191" class="form-control" value="{{ $acct->smtp_host ?? '' }}" placeholder="smtp.yourcompany.com">
                </div>
                <div>
                    <label class="form-label">Port <span class="text-danger">*</span></label>
                    <input type="number" name="smtp_port" required class="form-control" value="{{ $acct->smtp_port ?? ($defaults['smtp_port'] ?? 587) }}">
                </div>
            </div>
            <div class="grid grid-cols-3 gap-3 mb-2">
                <div>
                    <label class="form-label">Encryption</label>
                    <select name="smtp_encryption" class="form-select">
                        @foreach (['ssl' => 'SSL/TLS', 'tls' => 'STARTTLS', 'none' => 'None'] as $v => $l)
                            <option value="{{ $v }}" @selected(($acct->smtp_encryption ?? ($defaults['smtp_encryption'] ?? 'tls')) === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" name="smtp_username" required maxlength="191" class="form-control" value="{{ $acct->smtp_username ?? '' }}">
                </div>
                <div>
                    <label class="form-label">Password {{ $isEdit ? '' : '*' }}</label>
                    <input type="password" name="smtp_password" {{ $isEdit ? '' : 'required' }} maxlength="512" class="form-control" placeholder="{{ $isEdit ? 'Leave blank to keep' : '' }}" autocomplete="new-password">
                </div>
            </div>

            <div class="email-account-form__test-result text-xs mt-1"></div>
        </div>
        <div class="tva-modal__foot">
            <button type="button" class="btn btn-secondary email-account-form__test" data-test-url="{{ $testUrl }}">Test connection</button>
            <button type="button" class="btn btn-secondary" data-tva-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">{{ $submit }}</button>
        </div>
    </form>
</div>
