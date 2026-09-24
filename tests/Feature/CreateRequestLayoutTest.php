<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Borrowing Schedule explanatory text must not be pinned to an
 * arbitrarily narrow column width while the card around it is far wider -
 * that forces early, unnecessary line wraps. This does not assert any
 * pixel width (brittle); it only guards against reintroducing a hardcoded
 * max-width on the row-spanning helper/note text in this card.
 */
class CreateRequestLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_borrowing_schedule_helper_and_note_text_are_not_pinned_to_a_narrow_max_width(): void
    {
        $chs = OrganizationalUnit::query()->where('unit_code', 'CHS')->firstOrFail();
        $borrower = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'account_status' => AccountStatus::Active,
            'organizational_unit_id' => $chs->id,
        ]);

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('requests.create'));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('request-schedule-help', $content);
        $this->assertStringContainsString('request-schedule-note', $content);

        foreach (['request-schedule-help', 'request-schedule-note'] as $class) {
            if (! preg_match('/\.'.$class.'\s*\{([^}]*)\}/', $content, $matches)) {
                $this->fail("No CSS rule found for .{$class}.");
            }

            $this->assertStringNotContainsString(
                'max-width',
                $matches[1],
                "The .{$class} rule must not pin this row-spanning explanatory text to an arbitrary narrow width."
            );
        }
    }
}
