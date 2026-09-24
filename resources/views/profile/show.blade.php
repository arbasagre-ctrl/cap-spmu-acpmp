@extends('layouts.app', ['title' => 'Account Settings'])
@section('content')
<style>
    .account-settings-page {
        display: grid;
        gap: 20px;
    }

    .account-settings-page .page-heading {
        margin-bottom: 0;
        align-items: flex-start;
    }

    .account-settings-page .page-heading h1 {
        margin: 2px 0 4px;
    }

    .account-settings-page .page-heading > div > p:not(.eyebrow) {
        max-width: 760px;
        margin: 0;
        color: var(--text-muted);
    }

    /* Two balanced rows. Each pair shares the same width and row height.
       The taller card determines the row height, so additional content on
       either side never breaks alignment. */
    .account-settings-primary-grid,
    .account-settings-secondary-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 20px;
        align-items: stretch;
    }

    .account-settings-primary-grid > .account-settings-card,
    .account-settings-secondary-grid > .account-settings-card {
        min-width: 0;
        height: 100%;
    }

    .account-settings-card {
        min-width: 0;
        padding: 18px;
        border-radius: 12px;
        box-shadow: 0 1px 2px rgba(7, 27, 53, .05);
    }

    .account-settings-card .card-header,
    .account-settings-card .section-heading {
        margin-bottom: 14px;
    }

    .account-settings-card .card-header h2,
    .account-settings-card .section-heading h2 {
        margin: 2px 0 0;
        font-size: 18px;
    }

    .account-settings-identity,
    .account-settings-profile-signing,
    .account-settings-contact-form,
    .account-settings-appearance-card {
        display: flex;
        flex-direction: column;
    }

    .account-settings-identity-grid {
        display: grid;
        flex: 1 1 auto;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        grid-auto-rows: minmax(64px, 1fr);
        gap: 0 18px;
        align-content: stretch;
    }

    .account-settings-readonly {
        min-width: 0;
        min-height: 68px;
        padding: 12px 14px;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: var(--surface-subtle);
    }

    .account-settings-readonly.is-wide {
        grid-column: 1 / -1;
    }

    .account-settings-readonly span {
        display: block;
        margin-bottom: 5px;
        color: var(--text-muted);
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .04em;
        text-transform: uppercase;
    }

    .account-settings-readonly strong {
        display: block;
        color: var(--heading);
        font-size: 13px;
        line-height: 1.4;
        overflow-wrap: anywhere;
    }

    /* The identity card is intentionally flatter than form controls so the
       official data reads as one structured record instead of six nested cards. */
    .account-settings-identity .account-settings-readonly {
        min-height: 0;
        padding: 11px 4px 12px;
        border: 0;
        border-bottom: 1px solid var(--border);
        border-radius: 0;
        background: transparent;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    .account-settings-identity .account-settings-readonly.is-wide {
        padding-left: 4px;
        padding-right: 4px;
    }

    .account-settings-note {
        margin: 14px 0 0;
        padding-top: 12px;
        border-top: 1px solid var(--border);
        color: var(--text-muted);
        font-size: 11px;
        line-height: 1.5;
    }

    .account-settings-profile-signing {
        gap: 0;
    }

    .account-settings-inner-section {
        min-width: 0;
    }

    .account-settings-inner-section + .account-settings-inner-section {
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid var(--border);
    }

    .account-settings-inner-heading {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 10px;
    }

    .account-settings-inner-heading h3 {
        margin: 0;
        color: var(--heading);
        font-size: 16px;
    }

    .account-settings-photo-layout {
        display: grid;
        grid-template-columns: 90px minmax(0, 1fr);
        gap: 14px;
        align-items: center;
    }

    .profile-photo-preview {
        width: 90px;
        height: 90px;
        border-radius: 50%;
        overflow: hidden;
        display: grid;
        place-items: center;
        background: linear-gradient(135deg, #0d63d8, #174b85);
        color: #fff;
        font-size: 1.55rem;
        font-weight: 800;
        border: 4px solid rgba(13, 99, 216, .10);
        box-shadow: 0 8px 22px rgba(13, 54, 100, .10);
    }

    .profile-photo-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .account-settings-photo-actions,
    .account-settings-upload {
        display: grid;
        gap: 9px;
        min-width: 0;
    }

    .account-settings-photo-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }

    .account-settings-photo-buttons form {
        margin: 0;
    }

    .profile-photo-remove {
        color: var(--danger);
        border-color: var(--danger-border);
        background: var(--danger-bg);
    }

    .profile-photo-remove:hover {
        color: var(--danger-action);
        border-color: var(--danger);
        background: color-mix(in srgb, var(--danger) 12%, var(--surface-elevated));
    }

    .account-settings-signature-layout {
        display: grid;
        grid-template-columns: minmax(140px, .68fr) minmax(0, 1.32fr);
        gap: 14px;
        align-items: start;
    }

    .signature-preview-wrap {
        display: grid;
        gap: 8px;
    }

    .signature-preview {
        display: grid;
        min-height: 104px;
        place-items: center;
        padding: 12px;
        overflow: hidden;
        border: 1px dashed var(--border-strong);
        border-radius: 10px;
        background: var(--surface-subtle);
    }

    .signature-preview img {
        display: block;
        max-width: 100%;
        max-height: 76px;
        object-fit: contain;
    }

    .signature-preview span {
        color: var(--text-muted);
        font-size: 12px;
        text-align: center;
    }

    .signature-upload-form {
        display: grid;
        gap: 9px;
    }

    .profile-signature-note {
        margin: 2px 0 0;
        padding: 8px 10px;
        border-left: 3px solid var(--info);
        border-radius: 8px;
        background: var(--surface-subtle);
        color: var(--text-muted);
        font-size: 10.5px;
        line-height: 1.45;
    }

    .account-settings-contact-form {
        gap: 14px;
    }

    .account-settings-contact-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 12px;
        align-items: start;
    }

    .account-settings-contact-grid label,
    .account-settings-upload label,
    .signature-upload-form label,
    .account-settings-appearance-card label {
        display: grid;
        gap: 7px;
        margin: 0;
        font-weight: 700;
    }

    .account-settings-contact-grid input,
    .account-settings-upload input[type="file"],
    .signature-upload-form input[type="file"],
    .account-settings-appearance-card select {
        width: 100%;
        margin: 0;
    }

    .account-settings-contact-grid input[readonly] {
        color: var(--heading);
        font-weight: 600;
        background: var(--surface-subtle);
        cursor: default;
    }

    .account-settings-notifications {
        display: grid;
        gap: 9px;
        margin: 0;
        padding: 12px 14px;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: var(--surface-subtle);
    }

    .account-settings-notifications legend {
        padding: 0 4px;
        color: var(--heading);
        font-size: 12px;
        font-weight: 800;
    }

    .account-settings-notifications .meta {
        margin: 0 0 2px;
    }

    .account-settings-notifications .checkbox {
        margin: 0;
    }


    .account-settings-notification-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 8px;
    }

    .account-settings-notification-grid .checkbox {
        min-width: 0;
        padding: 9px 10px;
        border: 1px solid var(--border);
        border-radius: 9px;
        background: var(--surface-elevated);
        align-items: center;
    }

    .account-settings-form-actions {
        display: flex;
        justify-content: flex-end;
        margin-top: auto;
        padding-top: 2px;
    }

    .account-settings-appearance-card {
        gap: 16px;
    }

    .account-settings-appearance-body {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 14px;
        align-items: stretch;
    }

    .account-settings-appearance-control {
        display: grid;
        gap: 7px;
        align-content: start;
    }

    .account-settings-appearance-summary {
        display: grid;
        gap: 6px;
        padding: 12px 14px;
        align-content: start;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: var(--surface-subtle);
    }

    .account-settings-appearance-summary strong {
        color: var(--heading);
        font-size: 12px;
    }

    .account-settings-appearance-summary p {
        margin: 0;
        color: var(--text-muted);
        font-size: 11px;
        line-height: 1.5;
    }

    .account-settings-appearance-spacer {
        display: none;
    }

    html[data-theme="dark"] .profile-photo-preview {
        border-color: rgba(255,255,255,.10);
    }

    @media (max-width: 1050px) {
        .account-settings-primary-grid,
        .account-settings-secondary-grid {
            grid-template-columns: 1fr;
        }

        .account-settings-primary-grid > .account-settings-card,
        .account-settings-secondary-grid > .account-settings-card {
            height: auto;
        }
    }

    @media (max-width: 720px) {
        .account-settings-identity-grid,
        .account-settings-contact-grid,
        .account-settings-photo-layout,
        .account-settings-signature-layout,
        .account-settings-appearance-body,
        .account-settings-notification-grid {
            grid-template-columns: 1fr;
        }

        .account-settings-photo-layout {
            justify-items: start;
        }

        .account-settings-form-actions {
            justify-content: stretch;
        }

        .account-settings-form-actions .button,
        .signature-upload-form .button {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="account-settings-page">
    <section class="page-heading">
        <div>
            <p class="eyebrow">Account Administration</p>
            <h1>Account Settings</h1>
            <p>Review your official account information and manage the communication, profile, signing, and display preferences available to your account.</p>
        </div>
    </section>

    <section class="account-settings-primary-grid" aria-label="Account identity, profile, and signing">
        <article class="card account-settings-card account-settings-identity" aria-labelledby="account-details-heading">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Official Identity</p>
                    <h2 id="account-details-heading">Account Information</h2>
                </div>
            </div>

            <div class="account-settings-identity-grid">
                <div class="account-settings-readonly"><span>Borrower / Employee Number</span><strong>{{ $user->employee_no ?: 'Not recorded' }}</strong></div>
                <div class="account-settings-readonly"><span>Full Name</span><strong>{{ $user->full_name }}</strong></div>
                <div class="account-settings-readonly"><span>Designation / Position</span><strong>{{ $user->designation ?: 'Not recorded' }}</strong></div>
                <div class="account-settings-readonly">
                    <span>Personnel Type</span>
                    <strong>
                        {{
                            match ($user->employment_type?->value) {
                                'FACULTY' => 'Faculty',
                                'EMPLOYEE' => 'Employee',
                                'STAFF' => 'Staff',
                                default => 'Not recorded',
                            }
                        }}
                    </strong>
                </div>
                <div class="account-settings-readonly">
                    <span>Employment Status</span>
                    <strong>
                        {{
                            match ($user->employment_status) {
                                'FULL_TIME' => 'Full-time',
                                'PART_TIME' => 'Part-time',
                                default => 'Not recorded',
                            }
                        }}
                    </strong>
                </div>
                <div class="account-settings-readonly"><span>Division</span><strong>{{ $user->organizationalUnit?->divisionLabel() ?: 'Not recorded' }}</strong></div>
                <div class="account-settings-readonly"><span>Office / College / Unit</span><strong>{{ $user->organizationalUnit?->unit_name ?: 'Not recorded' }}</strong></div>

                @php
                    $additionalRequestingUnits = $user->authorizedOrganizationalUnits
                        ->where('id', '!=', $user->organizational_unit_id)
                        ->values();
                @endphp
                @if($additionalRequestingUnits->isNotEmpty())
                    <div class="account-settings-readonly is-wide">
                        <span>Additional Authorized Requesting Unit{{ $additionalRequestingUnits->count() > 1 ? 's' : '' }}</span>
                        <strong>{{ $additionalRequestingUnits->pluck('unit_name')->join(', ') }}</strong>
                    </div>
                @endif
            </div>

            <p class="account-settings-note">
                Official identity, designation, personnel type, employment status, and organizational assignments are maintained by ICTU. Contact ICTU if an official account detail requires correction.
            </p>
        </article>

        <article class="card account-settings-card account-settings-profile-signing" aria-labelledby="profile-signing-heading">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Profile &amp; Signing</p>
                    <h2 id="profile-signing-heading">Profile Picture and E-signature</h2>
                </div>
            </div>

            <section class="account-settings-inner-section" aria-labelledby="profile-photo-heading">
                <div class="account-settings-inner-heading">
                    <div>
                        <h3 id="profile-photo-heading">Profile Picture</h3>
                    </div>
                </div>

                <div class="account-settings-photo-layout">
                    <div class="profile-photo-preview" data-profile-photo-preview aria-label="Current profile picture">
                        @if($user->profile_picture_path)
                            <img
                                src="{{ route('profile.picture.show') }}?v={{ $user->updated_at?->timestamp ?? time() }}"
                                alt="Profile picture of {{ $user->full_name }}"
                            >
                        @else
                            <span>
                                {{
                                    collect(preg_split('/\s+/', trim($user->full_name)))
                                        ->filter()
                                        ->take(2)
                                        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                                        ->implode('')
                                }}
                            </span>
                        @endif
                    </div>

                    <div class="account-settings-photo-actions">
                        <form method="post" action="{{ route('profile.picture.update') }}" enctype="multipart/form-data" class="account-settings-upload">
                            @csrf
                            <label for="profile-picture-input">
                                {{ $user->profile_picture_path ? 'Replace Profile Picture' : 'Upload Profile Picture' }}
                                <input
                                    id="profile-picture-input"
                                    type="file"
                                    name="profile_picture"
                                    accept="image/png,image/jpeg,image/webp"
                                    required
                                    data-profile-photo-input
                                >
                            </label>
                            <small class="field-help">PNG, JPG, JPEG, or WebP. Maximum 2 MB. A square image is recommended.</small>
                            @error('profile_picture')<small class="field-error">{{ $message }}</small>@enderror
                            <div class="account-settings-photo-buttons">
                                <button class="button primary ui-pressable" type="submit">
                                    {{ $user->profile_picture_path ? 'Save New Picture' : 'Upload Picture' }}
                                </button>
                            </div>
                        </form>

                        @if($user->profile_picture_path)
                            <form method="post" action="{{ route('profile.picture.destroy') }}" onsubmit="return confirm('Remove your current profile picture?');">
                                @csrf
                                @method('DELETE')
                                <button class="button secondary ui-pressable profile-photo-remove" type="submit">Remove Picture</button>
                            </form>
                        @endif
                    </div>
                </div>
            </section>

            <section class="account-settings-inner-section" aria-labelledby="signature-heading">
                <div class="account-settings-inner-heading">
                    <div>
                        <h3 id="signature-heading">E-signature</h3>
                    </div>
                    <x-status-badge
                        :status="$user->currentSignature ? 'ACTIVE' : 'PENDING'"
                        :label="$user->currentSignature ? 'Registered' : 'Setup Required'"
                    />
                </div>

                <div class="account-settings-signature-layout">
                    <div class="signature-preview-wrap">
                        <div class="signature-preview" data-signature-preview aria-label="Current E-signature preview">
                            @if($user->currentSignature?->file)
                                <img
                                    src="{{ route('files.show', $user->currentSignature->file, false) }}?v={{ $user->currentSignature->updated_at?->timestamp ?? time() }}"
                                    alt="Current E-signature of {{ $user->full_name }}"
                                >
                            @else
                                <span>No E-signature is currently registered.</span>
                            @endif
                        </div>
                        @if($user->currentSignature)
                            <p class="meta">Registered {{ $user->currentSignature->effective_from?->format('d M Y, g:i A') }}</p>
                        @endif
                    </div>

                    <form
                        method="post"
                        action="{{ route('profile.signature') }}"
                        enctype="multipart/form-data"
                        class="signature-upload-form"
                    >
                        @csrf
                        <label for="profile-signature-input">
                            {{ $user->currentSignature ? 'Replace E-signature' : 'Upload E-signature' }}
                            <input
                                id="profile-signature-input"
                                type="file"
                                name="signature"
                                accept="image/png,image/jpeg,image/webp"
                                required
                                data-signature-input
                            >
                        </label>
                        <small class="field-help">PNG, JPG, JPEG, or WebP. Maximum {{ $signatureMaxUploadMb }} MB.</small>
                        @error('signature')<small class="field-error">{{ $message }}</small>@enderror
                        <button class="button primary ui-pressable" type="submit">
                            {{ $user->currentSignature ? 'Save New E-signature' : 'Register E-signature' }}
                        </button>
                        <p class="profile-signature-note">
                            Your registered E-signature is applied only when you explicitly confirm an authorized signing action. Replacing it affects future signing actions only.
                        </p>
                    </form>
                </div>
            </section>
        </article>
    </section>

    <section class="account-settings-secondary-grid" aria-label="Communication and display preferences">
        <form method="post" action="{{ route('profile.update') }}" class="card account-settings-card account-settings-contact-form">
            @csrf
            @method('PUT')

            <div class="section-heading">
                <div>
                    <p class="eyebrow">Communication</p>
                    <h2>Contact and Notification Settings</h2>
                </div>
            </div>

            <div class="account-settings-contact-grid">
                <label for="official-email">Official Email
                    <input
                        id="official-email"
                        type="email"
                        value="{{ $user->email }}"
                        readonly
                        aria-readonly="true"
                        autocomplete="email"
                    >
                </label>

                <label for="contact-number">Contact Number
                    <input id="contact-number" name="mobile_no" value="{{ old('mobile_no', $user->mobile_no) }}" maxlength="30" autocomplete="tel">
                    @error('mobile_no')<small class="field-error">{{ $message }}</small>@enderror
                </label>
            </div>

            <fieldset class="account-settings-notifications">
                <legend>Notification Preferences</legend>
                <p class="meta">Choose which channels may send account and transaction updates.</p>
                <div class="account-settings-notification-grid">
                    <label class="checkbox"><input type="checkbox" name="system_notifications" value="1" @checked(data_get($user->notification_preferences, 'system', true))> In-system</label>
                    <label class="checkbox"><input type="checkbox" name="email_notifications" value="1" @checked(data_get($user->notification_preferences, 'email', true))> Email</label>
                </div>
            </fieldset>

            <div class="account-settings-form-actions">
                <button class="button primary ui-pressable" type="submit">Save Contact Settings</button>
            </div>
        </form>

        <article class="card account-settings-card account-settings-appearance-card appearance-settings-card" aria-labelledby="appearance-heading">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Display Preference</p>
                    <h2 id="appearance-heading">Appearance</h2>
                </div>
            </div>

            <div class="account-settings-appearance-body">
                <div class="account-settings-appearance-control">
                    <label for="appearance-select">Theme
                        <select id="appearance-select" data-appearance-select>
                            <option value="light">Light</option>
                            <option value="dark">Dark</option>
                            <option value="system">Default</option>
                        </select>
                    </label>
                    <p class="meta" data-appearance-status aria-live="polite">Default uses the application’s standard light appearance.</p>
                </div>

                <div class="account-settings-appearance-summary">
                    <strong>Display preference</strong>
                    <p>Theme changes affect this browser only and never change account or transaction records.</p>
                </div>
            </div>

            <div class="account-settings-appearance-spacer" aria-hidden="true"></div>
        </article>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const input = document.querySelector('[data-profile-photo-input]');
    const preview = document.querySelector('[data-profile-photo-preview]');

    if (input && preview) {
        input.addEventListener('change', () => {
            const file = input.files?.[0];
            if (!file || !file.type.startsWith('image/')) return;

            const url = URL.createObjectURL(file);
            preview.innerHTML = '';
            const image = document.createElement('img');
            image.src = url;
            image.alt = 'Selected profile picture preview';
            image.onload = () => URL.revokeObjectURL(url);
            preview.appendChild(image);
        });
    }

    const signatureInput = document.querySelector('[data-signature-input]');
    const signaturePreview = document.querySelector('[data-signature-preview]');

    signatureInput?.addEventListener('change', () => {
        const file = signatureInput.files?.[0];
        if (!file || !file.type.startsWith('image/') || !signaturePreview) return;

        const url = URL.createObjectURL(file);
        signaturePreview.innerHTML = '';
        const image = document.createElement('img');
        image.src = url;
        image.alt = 'Selected E-signature preview';
        image.onload = () => URL.revokeObjectURL(url);
        signaturePreview.appendChild(image);
    });
});
</script>
@endsection
