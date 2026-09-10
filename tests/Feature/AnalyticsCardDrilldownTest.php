<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every Analytics card opens a real detail.
 *
 * The promise this guards is "no dead clicks": a card that offers a drill-down
 * must resolve to a panel with a title and an explanation, including when the
 * underlying reading does not exist. A card whose data is empty still has to
 * say why it is empty rather than opening a blank drawer.
 */
class AnalyticsCardDrilldownTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every card key wired into the five Analytics tabs.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function cardKeys(): array
    {
        $keys = [
            'overview' => [
                'overview.trend', 'overview.insights', 'overview.released',
                'overview.units', 'overview.snapshot',
            ],
            'demand' => [
                'demand.requested-quantity', 'demand.released-quantity', 'demand.active-units',
                'demand.trend', 'demand.division', 'demand.requested-items', 'demand.units',
                'demand.released-items', 'demand.low-usage', 'demand.peak',
            ],
            'inventory' => [
                'inventory.available', 'inventory.reserved', 'inventory.custody',
                'inventory.attention', 'inventory.availability', 'inventory.distribution',
                'inventory.low-availability', 'inventory.utilization', 'inventory.operational',
                'inventory.coverage',
            ],
            'returns' => [
                'returns.trend', 'returns.outcome', 'returns.lifecycle', 'returns.followup',
                'returns.condition', 'returns.summary', 'returns.issues',
            ],
            'predictive' => [
                'forecast.readiness', 'forecast.scheduled', 'forecast.outlook',
                'forecast.division', 'forecast.unit', 'forecast.equipment',
                'forecast.busy', 'forecast.notes', 'forecast.methodology',
            ],
        ];

        $cases = [];

        foreach ($keys as $section => $group) {
            foreach ($group as $key) {
                $cases[$section.' / '.$key] = [$section, $key];
            }
        }

        return $cases;
    }

    /* The Analytics route authorises on the access classification alone. */
    private function spmuHead(): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::SpmuHead,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cardKeys')]
    public function test_every_card_key_opens_a_detail_with_content(string $section, string $key): void
    {
        $response = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => $section,
            'academic_period' => 'month',
            'detail' => 'card',
            'for' => $key,
        ]));

        $response->assertOk();

        /* A resolved panel, not a silently dropped detail. */
        $response->assertSee('data-analytics-detail-panel', false);

        /* The fallback is a real failure: it means the key was never wired. */
        $response->assertDontSee('Detail unavailable');
    }

    public function test_an_unknown_card_key_still_answers_rather_than_vanishing(): void
    {
        $this->actingAs($this->spmuHead())
            ->get(route('analytics.index', [
                'section' => 'overview',
                'detail' => 'card',
                'for' => 'not-a-real-card',
            ]))
            ->assertOk()
            ->assertSee('data-analytics-detail-panel', false)
            ->assertSee('Detail unavailable');
    }

    public function test_a_card_detail_keeps_the_selected_filters(): void
    {
        $response = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'returns',
            'academic_period' => 'week',
            'group' => 'ACADEMIC',
            'detail' => 'card',
            'for' => 'returns.summary',
        ]));

        $response->assertOk();

        /* The close link returns to the same tab and filters it was opened from. */
        $response->assertSee('section=returns', false);
        $response->assertSee('academic_period=week', false);
        $response->assertSee('group=ACADEMIC', false);
    }

    public function test_empty_readings_explain_themselves_instead_of_opening_blank(): void
    {
        /*
         * With no completed returns the summary cannot produce a rate. The
         * drawer must still say so - "not measurable" is a reading, 0% is not.
         */
        $this->actingAs($this->spmuHead())
            ->get(route('analytics.index', [
                'section' => 'returns',
                'academic_period' => 'month',
                'detail' => 'card',
                'for' => 'returns.summary',
            ]))
            ->assertOk()
            ->assertSee('Not measurable');
    }

    public function test_overdue_and_returned_late_stay_separate_in_the_drawer(): void
    {
        $this->actingAs($this->spmuHead())
            ->get(route('analytics.index', [
                'section' => 'returns',
                'academic_period' => 'month',
                'detail' => 'card',
                'for' => 'returns.followup',
            ]))
            ->assertOk()
            ->assertSee('separate measure from a late return', false);
    }

    public function test_scheduled_demand_is_not_described_as_a_forecast(): void
    {
        $this->actingAs($this->spmuHead())
            ->get(route('analytics.index', [
                'section' => 'predictive',
                'academic_period' => 'month',
                'detail' => 'card',
                'for' => 'forecast.scheduled',
            ]))
            ->assertOk()
            ->assertSee('It is a record, not a projection', false);
    }
}
