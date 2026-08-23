@extends('layouts.app', ['title' => 'Account Settings'])
@section('content')
<style>
    .profile-photo-card { display: grid; gap: 18px; }
    .profile-photo-layout {
        display: grid;
        grid-template-columns: 112px minmax(0, 1fr);
        gap: 18px;
        align-items: center;
    }
    .profile-photo-preview {
        width: 112px;
        height: 112px;
        border-radius: 50%;
        overflow: hidden;
        display: grid;
        place-items: center;
        background: linear-gradient(135deg, #0d63d8, #174b85);
        color: #fff;
        font-size: 2rem;
        font-weight: 800;
        border: 4px solid rgba(13, 99, 216, .10);
        box-shadow: 0 8px 22px rgba(13, 54, 100, .10);
    }
    .profile-photo-preview img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .profile-photo-actions { display: grid; gap: 12px; min-width: 0; }
    .profile-photo-actions label { display: grid; gap: 7px; }
    .profile-photo-actions input[type="file"] { width: 100%; }
    .profile-photo-buttons { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .profile-photo-remove-form { margin: 0; }
    .profile-photo-remove { color: #b42318; border-color: #f2b8b5; background: #fff7f6; }
    .profile-photo-remove:hover { color: #8f1d14; border-color: #e49a96; background: #fff1ef; }
    html[data-theme="dark"] .profile-photo-preview { border-color: rgba(255,255,255,.10); }
    html[data-theme="dark"] .profile-photo-remove { color: #ffb4ad; border-color: #6f3a38; background: #321d1c; }
    @media (max-width: 640px) { .profile-photo-layout { grid-template-columns: 1fr; } }
</style>

@php
    $isBorrower = $user->access_classification === App\Enums\AccessClassification::BorrowerOnly;
@endphp
<section class="page-heading">
    <div>
        <p class="eyebrow">Personal account</p>
        <h1>Account Settings</h1>
    </div>
</section>

<section class="content-grid two profile-layout">
    <form method="post" action="{{ route('profile.update') }}" class="card form-grid account-settings-form">
        @csrf
        @method('PUT')

        <section class="account-settings-section" aria-labelledby="account-details-heading">
            <div class="section-heading">
                <div><p class="eyebrow">Identity</p><h2 id="account-details-heading">Account Details</h2></div>
            </div>

            @if($isBorrower)
                <div class="form-columns">
                    <label>Borrower Number
                        <input name="employee_no" value="{{ old('employee_no', $user->employee_no) }}" required maxlength="80" autocomplete="off">
                        @error('employee_no')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <label>Office / Department
                        <select name="organizational_unit_id" required>
                            <option value="">Select office or department</option>
                            @foreach($borrowerUnits as $unit)
                                <option value="{{ $unit->id }}" @selected((string) old('organizational_unit_id', $user->organizational_unit_id) === (string) $unit->id)>{{ $unit->unit_name }}</option>
                            @endforeach
                        </select>
                        @error('organizational_unit_id')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                </div>
            @else
                <div class="profile-readonly-grid">
                    <div><span>Employee Number</span><strong>{{ $user->employee_no }}</strong></div>
                    <div><span>Office / Department</span><strong>{{ $user->organizationalUnit?->unit_name ?: 'Not recorded' }}</strong></div>
                </div>
                <p class="field-help">Institutional identifiers and organizational assignments are maintained by ICTU because they determine portal authority, approval routing, and delegation eligibility.</p>
            @endif

            <div class="form-columns">
                <label>Full name
                    <input name="full_name" value="{{ old('full_name', $user->full_name) }}" required autocomplete="name">
                    @error('full_name')<small class="field-error">{{ $message }}</small>@enderror
                </label>
                <label>Designation
                    <input name="designation" value="{{ old('designation', $user->designation) }}" autocomplete="organization-title">
                    @error('designation')<small class="field-error">{{ $message }}</small>@enderror
                </label>
            </div>
        </section>

        <section class="account-settings-section" aria-labelledby="contact-information-heading">
            <div class="section-heading"><div><p class="eyebrow">Communication</p><h2 id="contact-information-heading">Contact Information</h2></div></div>
            <div class="profile-readonly-grid single-row">
                <div class="full-span"><span>Official email</span><strong>{{ $user->email }}</strong></div>
            </div>
            <label>Contact number
                <input name="mobile_no" value="{{ old('mobile_no', $user->mobile_no) }}" maxlength="30" autocomplete="tel">
                @error('mobile_no')<small class="field-error">{{ $message }}</small>@enderror
            </label>
            <fieldset>
                <legend>Notification preferences</legend>
                <p class="meta">Choose how the system may send account and transaction updates.</p>
                <label class="checkbox"><input type="checkbox" name="system_notifications" value="1" @checked(data_get($user->notification_preferences, 'system', true))> In-system notifications</label>
                <label class="checkbox"><input type="checkbox" name="email_notifications" value="1" @checked(data_get($user->notification_preferences, 'email', true))> Email notifications</label>
                <label class="checkbox"><input type="checkbox" name="sms_notifications" value="1" @checked(data_get($user->notification_preferences, 'sms', false))> SMS notifications</label>
            </fieldset>
        </section>

        <div class="form-actions"><button class="button primary ui-pressable" type="submit">Save Changes</button></div>
    </form>

    <div class="profile-side-column">
        <article class="card profile-photo-card" aria-labelledby="profile-photo-heading">
            <div class="card-header">
                <div><p class="eyebrow">Profile</p><h2 id="profile-photo-heading">Profile Picture</h2></div>
            </div>

            <div class="profile-photo-layout">
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

                <div class="profile-photo-actions">
                    <form method="post" action="{{ route('profile.picture.update') }}" enctype="multipart/form-data">
                        @csrf
                        <label for="profile-picture-input">
                            {{ $user->profile_picture_path ? 'Change profile picture' : 'Upload profile picture' }}
                            <input
                                id="profile-picture-input"
                                type="file"
                                name="profile_picture"
                                accept="image/png,image/jpeg,image/webp"
                                required
                                data-profile-photo-input
                            >
                        </label>
                        <small class="field-help">PNG, JPG, JPEG, or WebP. Maximum 2 MB. A square photo works best.</small>
                        @error('profile_picture')<small class="field-error">{{ $message }}</small>@enderror
                        <div class="profile-photo-buttons">
                            <button class="button primary ui-pressable" type="submit">
                                {{ $user->profile_picture_path ? 'Save New Picture' : 'Upload Picture' }}
                            </button>
                        </div>
                    </form>

                    @if($user->profile_picture_path)
                        <form method="post" action="{{ route('profile.picture.destroy') }}" class="profile-photo-remove-form" onsubmit="return confirm('Remove your current profile picture?');">
                            @csrf
                            @method('DELETE')
                            <button class="button secondary ui-pressable profile-photo-remove" type="submit">Remove Picture</button>
                        </form>
                    @endif
                </div>
            </div>
        </article>

        <article class="card appearance-settings-card" aria-labelledby="appearance-heading">
            <div class="card-header">
                <div><p class="eyebrow">Display preference</p><h2 id="appearance-heading">Appearance</h2></div>
            </div>
            <label for="appearance-select">Theme
                <select id="appearance-select" data-appearance-select>
                    <option value="light">Light</option>
                    <option value="dark">Dark</option>
                    <option value="system">Default</option>
                </select>
            </label>
            <p class="meta" data-appearance-status aria-live="polite">Default follows this device’s light or dark preference.</p>
        </article>

        <article class="card signature-card" aria-labelledby="signature-heading">
            <div class="card-header">
                <div><p class="eyebrow">Document policy</p><h2 id="signature-heading">Physical Signatures</h2></div>
                <x-status-badge status="ACTIVE" label="Wet signatures" />
            </div>
            <p>Borrowing Request Letters, Borrower Slips, Gate Passes, Laundry Forms, and other required operational documents use handwritten/wet signatures on printed copies. No e-signature upload is required for the active borrowing workflow.</p>
            <div class="callout account-information-callout">
                <x-icon name="information" />
                <div><strong>Document evidence</strong><p>Where required, the fully accomplished physical document is scanned and uploaded to the related transaction for SPMU verification and audit history.</p></div>
            </div>
        </article>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const input = document.querySelector('[data-profile-photo-input]');
    const preview = document.querySelector('[data-profile-photo-preview]');

    if (!input || !preview) return;

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
});
</script>

@endsection
