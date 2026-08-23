@extends('layouts.app', ['title' => 'Welcome'])

@section('content')

<style>
    .landing-shell {
        --landing-navy: #071b35;
        --landing-blue: #0d63d8;
        --landing-blue-strong: #0758c9;
        --landing-blue-soft: #eef5ff;
        --landing-ink: #0f2545;
        --landing-muted: #60708a;
        --landing-border: #dfe8f4;
        --landing-surface: #ffffff;
        --landing-soft: #f7faff;
        width: 100%;
        color: var(--landing-ink);
        background:
            radial-gradient(circle at 88% 4%, rgba(25, 107, 219, .10), transparent 25rem),
            linear-gradient(180deg, #fbfdff 0%, #ffffff 32%, #f8fbff 100%);
    }

    .landing-shell *,
    .landing-shell *::before,
    .landing-shell *::after {
        box-sizing: border-box;
    }

    .landing-container {
        width: min(1280px, calc(100% - 80px));
        margin-inline: auto;
    }

    .landing-hero {
        position: relative;
        overflow: hidden;
        padding: 0;
        border-bottom: 1px solid var(--landing-border);
        background:
            linear-gradient(
                90deg,
                rgba(255,255,255,.98) 0%,
                rgba(255,255,255,.96) 30%,
                rgba(255,255,255,.84) 48%,
                rgba(255,255,255,.42) 66%,
                rgba(255,255,255,.10) 100%
            ),
            url("{{ asset('images/cspc-campus-hero.png') }}") center right / cover no-repeat;
    }

    .landing-hero-grid {
        position: relative;
        width: min(1280px, calc(100% - 80px));
        margin-inline: auto;
        display: block;
        min-height: 500px;
        padding: 72px 0 154px;
    }

    .landing-hero-grid > div:first-child {
        width: min(590px, 100%);
        padding: 0;
    }

    .landing-hero h1 {
        max-width: 620px;
        margin: 0;
        color: var(--landing-navy);
        font-size: clamp(2.45rem, 4.5vw, 4.15rem);
        line-height: .98;
        letter-spacing: -.055em;
        font-weight: 850;
    }

    .landing-hero h1 .accent {
        display: block;
        margin-top: 8px;
        color: var(--landing-blue);
    }

    .landing-hero-copy {
        max-width: 620px;
        margin: 18px 0 0;
        color: var(--landing-muted);
        font-size: .94rem;
        line-height: 1.8;
    }

    .landing-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 22px;
    }

    .landing-actions .button,
    .landing-cta .button {
        min-height: 46px;
        padding-inline: 22px;
        border-radius: 10px;
    }

    .landing-learn-more {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        min-height: 46px;
        padding: 0 20px;
        border: 1px solid #1769e0;
        border-radius: 10px;
        background: rgba(255,255,255,.88);
        color: #1769e0;
        font-weight: 700;
        text-decoration: none;
        box-shadow: 0 8px 20px rgba(23,105,224,.08);
        transition:
            transform .16s ease,
            background-color .16s ease,
            color .16s ease,
            box-shadow .16s ease;
    }

    .landing-learn-more:hover {
        color: #0758c9;
        background: #ffffff;
        box-shadow: 0 10px 24px rgba(23,105,224,.12);
        transform: translateY(-1px);
        text-decoration: none;
    }

    .landing-learn-more:active {
        transform: scale(.98);
    }

    .landing-learn-more__arrow {
        display: inline-block;
        font-size: 1.05rem;
        line-height: 1;
        transition: transform .16s ease;
    }

    .landing-learn-more:hover .landing-learn-more__arrow {
        transform: translateX(3px);
    }






    .landing-feature-strip {
        position: relative;
        z-index: 8;
        width: min(900px, calc(100% - 80px));
        margin: -96px auto 38px;
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        border: 1px solid rgba(214,225,240,.95);
        border-radius: 18px;
        background: rgba(255,255,255,.97);
        box-shadow:
            0 24px 54px rgba(7,27,53,.12),
            0 2px 8px rgba(7,27,53,.04);
        overflow: hidden;
        backdrop-filter: blur(12px);
    }

    .landing-feature-strip article {
        position: relative;
        min-height: 154px;
        padding: 24px 20px 22px;
        text-align: center;
        background: transparent;
        border: 0;
        box-shadow: none;
    }

    .landing-feature-strip article + article::before {
        content: "";
        position: absolute;
        left: 0;
        top: 24px;
        bottom: 24px;
        width: 1px;
        background: #e2eaf4;
    }

    .landing-icon {
        width: 46px;
        height: 46px;
        display: inline-grid;
        place-items: center;
        border-radius: 50%;
        color: var(--landing-blue);
        background: var(--landing-blue-soft);
        margin-bottom: 12px;
        box-shadow: inset 0 0 0 1px rgba(23,105,224,.04);
    }

    .landing-feature-strip h3,
    .landing-feature-card h3,
    .landing-process h3 {
        margin: 0;
        color: var(--landing-navy);
        font-size: .95rem;
    }

    .landing-feature-strip p {
        margin: 7px 0 0;
        color: var(--landing-muted);
        font-size: .76rem;
        line-height: 1.55;
    }

    .landing-section {
        padding: 76px 0;
    }

    .landing-section.alt {
        background:
            linear-gradient(180deg, rgba(238,245,255,.58), rgba(255,255,255,.35));
        border-block: 1px solid #edf2f8;
    }

    .landing-section-heading {
        max-width: 720px;
        margin: 0 auto 42px;
        text-align: center;
    }

    .landing-section-heading.align-left {
        margin-inline: 0;
        text-align: left;
    }

    .landing-section-heading .mini {
        display: inline-block;
        margin-bottom: 8px;
        color: var(--landing-blue);
        font-size: .78rem;
        font-weight: 800;
    }

    .landing-section-heading h2 {
        margin: 0;
        color: var(--landing-navy);
        font-size: clamp(1.9rem, 3vw, 2.9rem);
        letter-spacing: -.035em;
        line-height: 1.1;
    }

    .landing-section-heading p {
        margin: 14px 0 0;
        color: var(--landing-muted);
        line-height: 1.75;
    }

    .landing-about-section {
        padding-top: 86px;
        padding-bottom: 68px;
    }

    .landing-about-wrap {
        width: min(930px, 100%);
        margin-inline: auto;
    }

    .landing-about-wrap .landing-section-heading {
        max-width: 820px;
        margin-bottom: 22px;
    }

    .landing-about-copy {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 28px;
        padding-top: 22px;
        border-top: 1px solid var(--landing-border);
    }

    .landing-about-copy p {
        margin: 0;
        color: var(--landing-muted);
        font-size: .9rem;
        line-height: 1.8;
    }

    .landing-process {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 18px;
        position: relative;
    }

    .landing-process::before {
        content: "";
        position: absolute;
        top: 32px;
        left: 8%;
        right: 8%;
        border-top: 1px dashed #a9c9f3;
        z-index: 0;
    }

    .landing-process article {
        position: relative;
        z-index: 1;
        text-align: center;
    }

    .landing-process-icon {
        width: 66px;
        height: 66px;
        display: grid;
        place-items: center;
        margin: 0 auto 15px;
        border: 1px solid #cfe0f6;
        border-radius: 50%;
        color: var(--landing-blue);
        background: #fff;
        box-shadow: 0 10px 25px rgba(13, 99, 216, .10);
    }

    .landing-step-no {
        width: 22px;
        height: 22px;
        display: inline-grid;
        place-items: center;
        margin-right: 5px;
        border-radius: 50%;
        color: #fff;
        background: var(--landing-blue);
        font-size: .68rem;
        font-weight: 800;
    }

    .landing-process p {
        margin: 8px auto 0;
        max-width: 150px;
        color: var(--landing-muted);
        font-size: .75rem;
        line-height: 1.55;
    }

    .landing-feature-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
    }

    .landing-feature-card {
        min-height: 180px;
        padding: 22px;
        border: 1px solid var(--landing-border);
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 10px 26px rgba(13, 54, 100, .045);
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }

    .landing-feature-card:hover {
        transform: translateY(-3px);
        border-color: #bad4f4;
        box-shadow: 0 16px 32px rgba(13, 54, 100, .08);
    }

    .landing-feature-card p {
        margin: 9px 0 0;
        color: var(--landing-muted);
        font-size: .8rem;
        line-height: 1.6;
    }

    .landing-impact {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        overflow: hidden;
        border-radius: 18px;
        background:
            radial-gradient(circle at 90% 10%, rgba(37,118,225,.35), transparent 20rem),
            linear-gradient(135deg, #073566, #071b35 72%);
        box-shadow: 0 22px 45px rgba(7,27,53,.14);
    }

    .landing-impact article {
        position: relative;
        min-height: 150px;
        padding: 28px;
        color: #fff;
    }

    .landing-impact article + article::before {
        content: "";
        position: absolute;
        left: 0;
        top: 24px;
        bottom: 24px;
        width: 1px;
        background: rgba(255,255,255,.14);
    }

    .landing-impact strong {
        display: block;
        margin-top: 10px;
        font-size: 1.15rem;
    }

    .landing-impact p {
        margin: 7px 0 0;
        color: rgba(255,255,255,.70);
        font-size: .76rem;
        line-height: 1.55;
    }

    .landing-cta {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 30px;
        align-items: center;
        padding: 42px 48px;
        border: 1px solid var(--landing-border);
        border-radius: 20px;
        background:
            radial-gradient(circle at 86% 50%, rgba(13,99,216,.12), transparent 18rem),
            linear-gradient(135deg, #ffffff, #f3f8ff);
        box-shadow: 0 14px 34px rgba(13,54,100,.06);
    }

    .landing-cta h2 {
        margin: 0;
        color: var(--landing-navy);
        font-size: clamp(1.5rem, 2.6vw, 2.2rem);
        letter-spacing: -.03em;
    }

    .landing-cta p {
        margin: 10px 0 0;
        color: var(--landing-muted);
    }

    .landing-footer {
        margin-top: 82px;
        background: linear-gradient(135deg, #061a33, #082c57);
        color: rgba(255,255,255,.82);
    }

    .landing-footer-grid {
        display: grid;
        grid-template-columns: 1.4fr .8fr .8fr 1fr;
        gap: 36px;
        padding: 46px 0 36px;
    }

    .landing-footer h3 {
        margin: 0 0 12px;
        color: #fff;
        font-size: .9rem;
    }

    .landing-footer p,
    .landing-footer a {
        color: rgba(255,255,255,.72);
        font-size: .78rem;
        line-height: 1.75;
    }

    .landing-footer a {
        display: block;
        text-decoration: none;
    }

    .landing-footer a:hover {
        color: #fff;
    }

    .landing-footer-brand strong {
        display: block;
        margin-bottom: 7px;
        color: #fff;
        font-size: .94rem;
    }

    .landing-footer-bottom {
        display: flex;
        justify-content: space-between;
        gap: 20px;
        padding: 18px 0 24px;
        border-top: 1px solid rgba(255,255,255,.10);
        font-size: .72rem;
    }

    @media (max-width: 1050px) {
        .landing-hero-grid {
            width: min(100% - 40px, 1180px);
            min-height: 450px;
            padding: 58px 0 126px;
        }

        .landing-feature-strip {
            width: min(100% - 40px, 900px);
            margin: -58px auto 32px;
            border-radius: 18px;
        }

        .landing-process {
            grid-template-columns: repeat(3, 1fr);
            gap: 32px 18px;
        }

        .landing-process::before {
            display: none;
        }

        .landing-feature-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .landing-footer-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 720px) {
        .landing-container {
            width: min(100% - 28px, 1180px);
        }

        .landing-hero {
            background:
                linear-gradient(
                    180deg,
                    rgba(255,255,255,.97) 0%,
                    rgba(255,255,255,.92) 58%,
                    rgba(255,255,255,.72) 100%
                ),
                url("{{ asset('images/cspc-campus-hero.png') }}") center / cover no-repeat;
        }

        .landing-hero h1 {
            font-size: clamp(2.15rem, 11vw, 3.15rem);
        }

        .landing-hero-grid {
            gap: 32px;
            min-height: auto;
        }



        .landing-feature-strip {
            width: min(100% - 28px, 900px);
            margin: -50px auto 28px;
            grid-template-columns: repeat(2, 1fr);
        }

        .landing-feature-strip article + article::before {
            display: none;
        }

        .landing-feature-strip article {
            min-height: 142px;
            padding: 20px 16px;
            border-bottom: 1px solid #e8eef6;
        }

        .landing-feature-strip article:nth-last-child(-n+2) {
            border-bottom: 0;
        }

        .landing-about-copy,
        .landing-feature-grid,
        .landing-impact,
        .landing-footer-grid,
        .landing-cta {
            grid-template-columns: 1fr;
        }

        .landing-process {
            grid-template-columns: repeat(2, 1fr);
        }

        .landing-impact article + article::before {
            top: 0;
            left: 22px;
            right: 22px;
            width: auto;
            height: 1px;
        }

        .landing-cta {
            padding: 30px 24px;
        }

        .landing-footer-bottom {
            flex-direction: column;
        }
    }

    @media (max-width: 460px) {
        .landing-feature-strip,
        .landing-process {
            grid-template-columns: 1fr;
        }

        .landing-feature-strip article {
            border-bottom: 1px solid #e8eef6;
        }

        .landing-feature-strip article:last-child {
            border-bottom: 0;
        }

        .landing-section {
            padding: 64px 0;
        }

    }

    @media (prefers-reduced-motion: reduce) {
        .landing-feature-card {
            transition: none;
        }
    }
</style>

<div class="landing-shell">

    {{-- HERO --}}
    <section class="landing-hero" aria-labelledby="landing-title">
        <div class="landing-container landing-hero-grid">

            <div>
                <h1 id="landing-title">
                    Smarter Asset Management.
                    <span class="accent">Stronger Accountability.</span>
                </h1>

                <p class="landing-hero-copy">
                    A centralized platform for borrowing, release, return,
                    inventory monitoring, and accountability of CSPC institutional property.
                </p>

                <div class="landing-actions">
                    <a class="button primary ui-pressable" href="{{ route('login') }}">
                        Sign in
                    </a>

                    <a class="landing-learn-more ui-pressable" href="#about">
                        <span>Learn more</span>
                        <span class="landing-learn-more__arrow" aria-hidden="true">&rarr;</span>
                    </a>
                </div>
            </div>
</div>

        {{-- QUICK STRIP --}}
        <div class="landing-container">
            <div class="landing-feature-strip " aria-label="Core system functions">

        <article>
            <span class="landing-icon" aria-hidden="true">
                <x-icon name="requests" size="22" />
            </span>
            <h3>Request</h3>
            <p>Submit borrowing details and required supporting documents.</p>
        </article>

        <article>
            <span class="landing-icon" aria-hidden="true">
                <x-icon name="approval" size="22" />
            </span>
            <h3>Review</h3>
            <p>SPMU verifies the request and records the official decision.</p>
        </article>

        <article>
            <span class="landing-icon" aria-hidden="true">
                <x-icon name="custody" size="22" />
            </span>
            <h3>Track</h3>
            <p>Follow release, custody, return, and accountability status.</p>
        </article>

        <article>
            <span class="landing-icon" aria-hidden="true">
                <x-icon name="inventory" size="22" />
            </span>
            <h3>Monitor</h3>
            <p>Maintain reliable inventory and operational records.</p>
        </article>

            </div>
        </div>
    </section>

    {{-- ABOUT --}}
    <section id="about" class="landing-section landing-about-section">
        <div class="landing-container">
            <div class="landing-about-wrap">

                <div class="landing-section-heading align-left">
                    <span class="mini">About SPMU-ACPMP</span>
                    <h2>A clearer way to manage institutional property borrowing.</h2>
                    <p>
                        SPMU-ACPMP brings borrowing, inventory, custody, return,
                        accountability, and reporting into one managed system for
                        Camarines Sur Polytechnic Colleges.
                    </p>
                </div>

                <div class="landing-about-copy">
                    <p>
                        Borrowers can review item availability, submit requests,
                        provide required signed documents, and follow the progress
                        of their borrowing transactions from request to completion.
                    </p>

                    <p>
                        Authorized SPMU personnel can verify requests, manage
                        approved inventory, oversee physical release and return,
                        monitor accountability cases, and retain auditable records.
                    </p>
                </div>

            </div>
        </div>
    </section>

    {{-- HOW IT WORKS --}}
    <section id="how-it-works" class="landing-section alt">
        <div class="landing-container">

            <div class="landing-section-heading">
                <span class="mini">Process</span>
                <h2>How It Works</h2>
                <p>
                    A clear end-to-end process from borrowing request to completed return.
                </p>
            </div>

            <div class="landing-process">

                <article>
                    <span class="landing-process-icon">
                        <x-icon name="profile" size="25" />
                    </span>
                    <h3><span class="landing-step-no">1</span>Login</h3>
                    <p>Access the system using an authorized CSPC account.</p>
                </article>

                <article>
                    <span class="landing-process-icon">
                        <x-icon name="requests" size="25" />
                    </span>
                    <h3><span class="landing-step-no">2</span>Submit Request</h3>
                    <p>Enter borrowing details and items, print the required request document(s), obtain wet signatures, then upload the required signed scans.</p>
                </article>

                <article>
                    <span class="landing-process-icon">
                        <x-icon name="approval" size="25" />
                    </span>
                    <h3><span class="landing-step-no">3</span>SPMU Review</h3>
                    <p>SPMU reviews the submitted request, supporting documents, requested quantities, dates, and current availability, then approves, returns it for revision, or rejects it.</p>
                </article>

                <article>
                    <span class="landing-process-icon">
                        <x-icon name="inventory" size="25" />
                    </span>
                    <h3><span class="landing-step-no">4</span>Release</h3>
                    <p>After approval, SPMU schedules pickup, prepares the approved quantities, and physically releases the items.</p>
                </article>

                <article>
                    <span class="landing-process-icon">
                        <x-icon name="custody" size="25" />
                    </span>
                    <h3><span class="landing-step-no">5</span>Return</h3>
                    <p>SPMU inspects returned property. Laundry-required linen continues through the Laundry workflow before final settlement.</p>
                </article>

                <article>
                    <span class="landing-process-icon">
                        <x-icon name="accountability" size="25" />
                    </span>
                    <h3><span class="landing-step-no">6</span>Monitor</h3>
                    <p>Track completed custody, accountability, inventory, and institutional reporting.</p>
                </article>

            </div>
        </div>
    </section>

    {{-- FEATURES --}}
    <section id="features" class="landing-section">
        <div class="landing-container">

            <div class="landing-section-heading">
                <h2>Key Features</h2>
            </div>

            <div class="landing-feature-grid">

                <article class="landing-feature-card">
                    <span class="landing-icon"><x-icon name="inventory" size="22" /></span>
                    <h3>Asset Inventory</h3>
                    <p>Maintain property details, quantities, availability, reservation, and custody status.</p>
                </article>

                <article class="landing-feature-card">
                    <span class="landing-icon"><x-icon name="requests" size="22" /></span>
                    <h3>Borrowing Requests</h3>
                    <p>Create and track borrowing requests with required request details and items.</p>
                </article>

                <article class="landing-feature-card">
                    <span class="landing-icon"><x-icon name="success" size="22" /></span>
                    <h3>Supporting Documents</h3>
                    <p>Maintain signed borrowing documents and applicable supporting files for verification.</p>
                </article>

                <article class="landing-feature-card">
                    <span class="landing-icon"><x-icon name="approval" size="22" /></span>
                    <h3>SPMU Review</h3>
                    <p>Review requests, verify documents, and record approval, revision, or rejection decisions.</p>
                </article>

                <article class="landing-feature-card">
                    <span class="landing-icon"><x-icon name="custody" size="22" /></span>
                    <h3>Release & Custody</h3>
                    <p>Record prepared quantities, physical release, borrower custody, and final issuance.</p>
                </article>

                <article class="landing-feature-card">
                    <span class="landing-icon"><x-icon name="calendar" size="22" /></span>
                    <h3>Borrowing Calendar</h3>
                    <p>View confirmed borrowing schedules and upcoming return deadlines.</p>
                </article>

                <article class="landing-feature-card">
                    <span class="landing-icon"><x-icon name="accountability" size="22" /></span>
                    <h3>Return & Accountability</h3>
                    <p>Record return condition, incidents, obligations, and case-by-case accountability follow-up.</p>
                </article>

                <article class="landing-feature-card">
                    <span class="landing-icon"><x-icon name="inventory" size="22" /></span>
                    <h3>Reports & Monitoring</h3>
                    <p>Support auditable operational reporting and institutional inventory monitoring.</p>
                </article>

            </div>
        </div>
    </section>

    {{-- IMPACT BAND --}}
    <section class="landing-section alt">
        <div class="landing-container">

            <div class="landing-impact">

                <article>
                    <span class="landing-icon"><x-icon name="inventory" size="20" /></span>
                    <strong>Centralized Records</strong>
                    <p>Inventory and borrowing information are kept in one managed system.</p>
                </article>

                <article>
                    <span class="landing-icon"><x-icon name="profile" size="20" /></span>
                    <strong>Role-based Access</strong>
                    <p>Users see only the functions and records appropriate to their responsibilities.</p>
                </article>

                <article>
                    <span class="landing-icon"><x-icon name="success" size="20" /></span>
                    <strong>Traceable Records</strong>
                    <p>Important decisions and operational actions remain attributable and available for review.</p>
                </article>

                <article>
                    <span class="landing-icon"><x-icon name="accountability" size="20" /></span>
                    <strong>Accountability Tracking</strong>
                    <p>Release, return, inspection, and unresolved obligations stay connected to each transaction.</p>
                </article>

            </div>
        </div>
    </section>

    {{-- CTA --}}
    <section class="landing-section">
        <div class="landing-container">

            <div class="landing-cta">
                <div>
                    <h2>Ready to manage borrowing more efficiently?</h2>
                    <p>Sign in using your authorized CSPC account to access SPMU-ACPMP.</p>
                </div>

                <a class="button primary ui-pressable" href="{{ route('login') }}">
                    Sign in
                </a>
            </div>

        </div>
    </section>

    {{-- FOOTER --}}
    <footer class="landing-footer">
        <div class="landing-container">

            <div class="landing-footer-grid">

                <div class="landing-footer-brand">
                    <strong>SPMU-ACPMP</strong>
                    <p>Supply and Property Management Unit<br>Asset Custody Monitoring Program</p>
                    <p>Camarines Sur Polytechnic Colleges<br>Nabua, Camarines Sur, Philippines</p>
                </div>

                <div>
                    <h3>Quick Links</h3>
                    <a href="#">Home</a>
                    <a href="#about">About</a>
                    <a href="#features">Features</a>
                    <a href="#how-it-works">How It Works</a>
                </div>

                <div>
                    <h3>Resources</h3>
                    <a href="#features">System Features</a>
                    <a href="#how-it-works">Borrowing Process</a>
                    <a href="#about">About the System</a>
                </div>

                <div>
                    <h3>Access</h3>
                    <p>Authorized CSPC users can sign in to access their assigned system functions.</p>
                    <a href="{{ route('login') }}">Sign in to SPMU-ACPMP</a>
                </div>

            </div>

            <div class="landing-footer-bottom">
                <span>&copy; {{ now()->year }} SPMU-ACPMP. All rights reserved.</span>
                <span>Camarines Sur Polytechnic Colleges</span>
            </div>

        </div>
    </footer>

</div>

@endsection
