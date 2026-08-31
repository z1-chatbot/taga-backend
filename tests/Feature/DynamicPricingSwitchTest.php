<?php

namespace Tests\Feature;

use App\Models\PricingConfiguration;
use App\Models\SystemSetting;
use Tests\TestCase;

/**
 * The master switch for the platform markup.
 *
 * It always governed pricing, but nothing could reliably write it. The only
 * editor was the generic Settings screen, which rendered every value — booleans
 * included — as a free-text box seeded with JSON.stringify(true). Turning it off
 * meant typing the word `false`, which arrived as the STRING "false"; the
 * settings endpoint then read it back with `(bool)`, and `(bool) "false"` is
 * TRUE in PHP.
 *
 * So the save succeeded, the toast said success, the badge on the Pricing page
 * still read ON, and the pricing itself — which goes through
 * `PricingConfiguration::dynamicPricingEnabled()`, and does coerce properly —
 * had quietly gone OFF. The two disagreed, which is the worst of the three
 * possible outcomes.
 *
 * These tests pin both halves: the switch writes, and everything that reads the
 * key agrees about what it says.
 */
class DynamicPricingSwitchTest extends TestCase
{
    private function admin(): array
    {
        return $this->tokenFor($this->makeUser(['role' => 'admin']));
    }

    private function setRaw(mixed $value): void
    {
        SystemSetting::setValue(
            SystemSetting::CATEGORY_GENERAL,
            'enable_dynamic_pricing',
            $value,
            'Enable Dynamic Pricing',
            'Allow platform to add markup to product prices',
            SystemSetting::TYPE_BOOLEAN
        );
    }

    public function test_the_switch_turns_dynamic_pricing_off(): void
    {
        $this->setRaw(true);

        $this->putJson('/api/v1/admin/pricing-configurations/dynamic-pricing', ['enabled' => false], $this->admin())
            ->assertOk()
            ->assertJsonPath('data.enable_dynamic_pricing', false);

        $this->assertFalse(PricingConfiguration::dynamicPricingEnabled());
    }

    public function test_the_switch_turns_it_back_on(): void
    {
        $this->setRaw(false);

        $this->putJson('/api/v1/admin/pricing-configurations/dynamic-pricing', ['enabled' => true], $this->admin())
            ->assertOk()
            ->assertJsonPath('data.enable_dynamic_pricing', true);

        $this->assertTrue(PricingConfiguration::dynamicPricingEnabled());
    }

    /**
     * The bug itself, in one assertion.
     *
     * A value saved through the old text box is the string "false". Anything
     * reading it with a plain cast reports the switch as ON while the pricing
     * treats it as OFF.
     */
    public function test_a_switch_saved_as_the_string_false_reads_as_off_everywhere(): void
    {
        $this->setRaw('false');

        $this->assertFalse(PricingConfiguration::dynamicPricingEnabled());

        $this->getJson('/api/v1/admin/settings/public', $this->admin())
            ->assertOk()
            ->assertJsonPath('data.enable_dynamic_pricing', false);
    }

    public function test_the_page_and_the_pricing_never_disagree(): void
    {
        foreach ([true, false] as $enabled) {
            $this->putJson('/api/v1/admin/pricing-configurations/dynamic-pricing', ['enabled' => $enabled], $this->admin())
                ->assertOk();

            $reported = $this->getJson('/api/v1/admin/settings/public', $this->admin())
                ->assertOk()
                ->json('data.enable_dynamic_pricing');

            $this->assertSame(
                PricingConfiguration::dynamicPricingEnabled(),
                $reported,
                'the badge on the Pricing page must say what the pricing actually does'
            );
        }
    }

    /**
     * Deactivating the row is a separate control on the same screen, and
     * `getValue()` only reads active rows — so a switch that had been powered
     * off silently fell back to the default of ON regardless of its value.
     * Writing through the switch has to undo that, or turning it back on there
     * does nothing.
     */
    public function test_the_switch_reactivates_a_row_that_was_powered_off(): void
    {
        $this->setRaw(true);

        SystemSetting::where('category', SystemSetting::CATEGORY_GENERAL)
            ->where('key', 'enable_dynamic_pricing')
            ->update(['is_active' => false]);

        $this->putJson('/api/v1/admin/pricing-configurations/dynamic-pricing', ['enabled' => false], $this->admin())
            ->assertOk();

        $this->assertDatabaseHas('system_settings', [
            'category' => SystemSetting::CATEGORY_GENERAL,
            'key' => 'enable_dynamic_pricing',
            'is_active' => true,
        ]);

        $this->assertFalse(PricingConfiguration::dynamicPricingEnabled());
    }

    public function test_enabled_is_required_and_must_be_a_boolean(): void
    {
        $this->putJson('/api/v1/admin/pricing-configurations/dynamic-pricing', [], $this->admin())
            ->assertStatus(422)
            ->assertJsonValidationErrors('enabled');

        $this->putJson('/api/v1/admin/pricing-configurations/dynamic-pricing', ['enabled' => 'maybe'], $this->admin())
            ->assertStatus(422)
            ->assertJsonValidationErrors('enabled');
    }

    /**
     * The route sits under the pricing-configurations prefix, above a /{id}
     * wildcard. Registered the other way round it would be read as an update of
     * a configuration whose id is the string "dynamic-pricing".
     */
    public function test_the_switch_is_not_swallowed_by_the_id_wildcard(): void
    {
        $before = PricingConfiguration::count();

        $this->putJson('/api/v1/admin/pricing-configurations/dynamic-pricing', ['enabled' => true], $this->admin())
            ->assertOk()
            ->assertJsonStructure(['data' => ['enable_dynamic_pricing']]);

        $this->assertSame($before, PricingConfiguration::count());
    }
}
