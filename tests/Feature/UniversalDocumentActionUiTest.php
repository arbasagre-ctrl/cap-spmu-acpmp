<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UniversalDocumentActionUiTest extends TestCase
{
    #[Test]
    public function document_actions_use_preview_instead_of_document_specific_open_labels(): void
    {
        $bladeSource = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php'))
            ->map(fn ($file) => $file->getContents())
            ->implode("\n");

        foreach ([
            'Open Document',
            'Open Borrower Slip',
            'Open Laundry Form',
            'Open Gate Pass',
            'Open Billing Statement',
            'Open Official Billing Statement',
            'Open Late Return Billing Statement',
            'Open RSLDDP',
            'Open Compliance Notice',
            'Open Restriction Notice',
            'Open Suspension Notice',
            'Open Administrative Sanction Notice',
            'Open Late Return Notice',
            'Open Accomplished Scan',
            'Open Scanned Cashier Receipt',
            'View Document',
            'View Form',
            'View PDF',
            'View uploaded file',
            'Open original',
        ] as $legacyLabel) {
            $this->assertStringNotContainsString($legacyLabel, $bladeSource);
        }

        $this->assertStringContainsString('Preview', $bladeSource);
    }

    #[Test]
    public function generated_document_lists_do_not_render_separate_download_actions(): void
    {
        $bladeSource = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php'))
            ->map(fn ($file) => $file->getContents())
            ->implode("\n");

        $this->assertStringNotContainsString("route('documents.download'", $bladeSource);
    }

    #[Test]
    public function uploaded_documents_use_the_protected_in_system_preview_route(): void
    {
        $bladeSource = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php'))
            ->map(fn ($file) => $file->getContents())
            ->implode("\n");

        $this->assertStringContainsString("route('files.preview'", $bladeSource);

        $requestForm = (string) file_get_contents(resource_path('views/requests/form.blade.php'));
        $this->assertStringContainsString("route('files.preview', \$requestLetter->file)", $requestForm);
        $this->assertStringContainsString("route('files.preview', \$ptc->file)", $requestForm);
    }

    #[Test]
    public function borrower_obligation_document_buttons_are_also_normalized_to_preview(): void
    {
        $source = (string) file_get_contents(app_path('Services/BorrowerObligationService.php'));

        foreach ([
            'Open RSLDDP',
            'Open Compliance Notice',
            'Open Restriction Notice',
            'Open Late Return Notice',
            'Open Late Return Billing Statement',
            'Open Suspension Notice',
            'Open Administrative Sanction Notice',
        ] as $legacyLabel) {
            $this->assertStringNotContainsString($legacyLabel, $source);
        }

        $this->assertStringContainsString("['Preview', route('documents.preview'", $source);
    }

    #[Test]
    public function unavailable_documents_use_not_available_and_non_applicable_documents_are_hidden(): void
    {
        $requestShow = (string) file_get_contents(resource_path('views/requests/show.blade.php'));
        $releaseProcess = (string) file_get_contents(resource_path('views/custody/partials/release-process.blade.php'));
        $returnWorkspace = (string) file_get_contents(resource_path('views/custody/partials/return-workspace.blade.php'));

        $this->assertStringContainsString('Not available', $requestShow);
        $this->assertStringNotContainsString('>Preparing</span>', $requestShow);
        $this->assertStringNotContainsString('>Missing approved document</span>', $requestShow);

        $this->assertStringContainsString('@if($requestHasLaundry)', $requestShow);
        $this->assertStringContainsString('@if($requestHasOffCampus)', $requestShow);
        $this->assertStringNotContainsString('>Not applicable</span>', $requestShow);

        $this->assertStringContainsString('@if($hasOffCampusItem)', $releaseProcess);
        $this->assertStringContainsString('@if($hasLaundryItem)', $releaseProcess);
        $this->assertStringContainsString('@if($hasOperationalReturnDocuments)', $returnWorkspace);
    }

    #[Test]
    public function workflow_navigation_actions_are_not_renamed_to_preview(): void
    {
        $heading = (string) file_get_contents(resource_path('views/requests/partials/operational-heading.blade.php'));

        $this->assertStringContainsString('Open Custody Record', $heading);
    }
}
