@props([
    'file' => null,
    'title' => 'Scanned Borrowing Request Letter',
])

@php
    /*
     * Relative URL is intentional.
     * It keeps the iframe on the same host/port/session
     * currently used by the authenticated SPMU user.
     */
    $previewUrl = $file
        ? route('files.show', $file, false)
        : null;

    $mimeType =
        strtolower((string) ($file?->mime_type ?? ''));

    $originalName =
        strtolower((string) ($file?->original_name ?? ''));

    $isPdf =
        $mimeType === 'application/pdf'
        || str_ends_with($originalName, '.pdf');

    $isImage =
        str_starts_with($mimeType, 'image/')
        || preg_match(
            '/\.(png|jpe?g|webp)$/i',
            $originalName
        );
@endphp

<article class="card scanned-document-card">
    <div class="scanned-document-header">
        <div>
            <p class="eyebrow">
                Scanned request letter
            </p>

            <h2>
                {{ $title }}
            </h2>
        </div>

        @if($previewUrl)
            <a
                class="button secondary small ui-pressable"
                href="{{ $previewUrl }}"
                target="_blank"
                rel="noopener"
            >
                Open original
            </a>
        @endif
    </div>

    @if(!$file)
        <div class="scanned-document-empty">
            No scanned request letter is available.
        </div>
    @elseif($isPdf)
        <div class="scanned-pdf-stage">
            <iframe
                class="scanned-pdf-frame"
                src="{{ $previewUrl }}#page=1&zoom=page-fit&toolbar=1&navpanes=0&scrollbar=1&view=Fit"
                title="{{ $title }}"
            ></iframe>
        </div>
    @elseif($isImage)
        <div
            class="scanned-image-viewer"
            data-scanned-image-viewer
        >
            <div
                class="scanned-image-toolbar"
                role="toolbar"
                aria-label="Image zoom controls"
            >
                <button
                    type="button"
                    data-image-zoom-out
                    aria-label="Zoom out"
                >
                    −
                </button>

                <span data-image-zoom-label>
                    Fit
                </span>

                <button
                    type="button"
                    data-image-zoom-in
                    aria-label="Zoom in"
                >
                    +
                </button>

                <button
                    type="button"
                    data-image-fit
                >
                    Fit
                </button>
            </div>

            <div class="scanned-image-stage">
                <img
                    data-image
                    src="{{ $previewUrl }}"
                    alt="{{ $title }}"
                >
            </div>
        </div>
    @else
        <div class="scanned-document-empty">
            Preview is unavailable for this file type.
            Use Open original.
        </div>
    @endif
</article>

@once
<style>
    .scanned-document-card {
        min-width: 0;
        overflow: hidden;
    }

    .scanned-document-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 18px 20px;
        border-bottom: 1px solid var(--border, #d7dee8);
    }

    .scanned-document-header h2 {
        margin: 3px 0 0;
        font-size: 1.15rem;
    }

    .scanned-pdf-stage {
        height: clamp(460px, 56vh, 610px);
        min-height: 460px;
        background: #525659;
    }

    .scanned-pdf-frame {
        display: block;
        width: 100%;
        height: 100%;
        border: 0;
    }

    .scanned-image-toolbar {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 12px;
        border-bottom: 1px solid #d7dee8;
        background: #f4f7fa;
    }

    .scanned-image-toolbar button {
        min-width: 38px;
        height: 36px;
        border: 1px solid #bcc9d8;
        border-radius: 8px;
        background: #fff;
        color: #173b64;
        font: inherit;
        font-weight: 700;
        cursor: pointer;
    }

    .scanned-image-toolbar span {
        min-width: 56px;
        text-align: center;
        font-weight: 700;
    }

    .scanned-image-stage {
        height: clamp(460px, 56vh, 610px);
        min-height: 460px;
        overflow: auto;
        padding: 22px;
        background: #e7ecf1;
        text-align: center;
    }

    .scanned-image-stage img {
        display: block;
        width: auto;
        max-width: 100%;
        height: auto;
        margin: 0 auto;
        background: #fff;
    }

    .scanned-document-empty {
        display: grid;
        place-items: center;
        min-height: 360px;
        padding: 24px;
        color: #64748b;
        text-align: center;
    }

    @media (max-width: 720px) {
        .scanned-document-header {
            align-items: stretch;
            flex-direction: column;
        }

        .scanned-pdf-stage,
        .scanned-image-stage {
            min-height: 380px;
            height: 54vh;
        }
    }
</style>

<script>
(() => {
    const viewers =
        document.querySelectorAll(
            '[data-scanned-image-viewer]'
        );

    viewers.forEach((viewer) => {
        if (viewer.dataset.ready === '1') {
            return;
        }

        viewer.dataset.ready = '1';

        const image =
            viewer.querySelector('[data-image]');

        const label =
            viewer.querySelector(
                '[data-image-zoom-label]'
            );

        let zoom = 100;
        let fit = true;

        const render = () => {
            if (fit) {
                image.style.width = 'auto';
                image.style.maxWidth = '100%';
                label.textContent = 'Fit';
                return;
            }

            image.style.width = `${zoom}%`;
            image.style.maxWidth = 'none';
            label.textContent = `${zoom}%`;
        };

        viewer
            .querySelector('[data-image-zoom-out]')
            ?.addEventListener('click', () => {
                fit = false;
                zoom = Math.max(40, zoom - 10);
                render();
            });

        viewer
            .querySelector('[data-image-zoom-in]')
            ?.addEventListener('click', () => {
                fit = false;
                zoom = Math.min(250, zoom + 10);
                render();
            });

        viewer
            .querySelector('[data-image-fit]')
            ?.addEventListener('click', () => {
                fit = true;
                zoom = 100;
                render();
            });

        render();
    });
})();
</script>
@endonce

<!-- SPMU_COMPACT_REVIEW_OVERRIDE -->
<style>
/*
 * Compact approval document review.
 * The native PDF/image stage keeps its own scrolling; the page no longer
 * needs an oversized 680â€“900px preview.
 */
.formal-document-review-card,
.scanned-document-card {
    min-height: 0 !important;
    align-self: start;
}

.formal-document-review-header,
.scanned-document-header {
    padding-block: 14px !important;
}

.formal-document-review-stage,
.formal-pdf-stage,
.scanned-pdf-stage,
.scanned-image-stage {
    height: clamp(460px, 56vh, 610px) !important;
    min-height: 460px !important;
    max-height: 610px !important;
    overflow: auto;
}

.formal-document-review-frame,
.formal-pdf-frame,
.scanned-pdf-frame {
    min-height: 0 !important;
}

@media (max-width: 900px) {
    .formal-document-review-stage,
    .formal-pdf-stage,
    .scanned-pdf-stage,
    .scanned-image-stage {
        height: 52vh !important;
        min-height: 400px !important;
        max-height: 540px !important;
    }
}

@media (max-width: 620px) {
    .formal-document-review-stage,
    .formal-pdf-stage,
    .scanned-pdf-stage,
    .scanned-image-stage {
        height: 50vh !important;
        min-height: 340px !important;
        max-height: 460px !important;
    }
}
</style>