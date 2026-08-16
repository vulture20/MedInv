<?php

namespace Tests\Unit;

use App\Domain\Metadata\TrackListRuntimeCalculator;
use Tests\TestCase;

class TrackListRuntimeCalculatorTest extends TestCase
{
    public function test_sums_every_tracks_duration(): void
    {
        $total = TrackListRuntimeCalculator::totalSeconds([
            ['duration_seconds' => 249],
            ['duration_seconds' => 224],
            ['duration_seconds' => 281],
        ]);

        $this->assertSame(754, $total);
    }

    public function test_returns_null_when_any_track_has_an_unknown_duration(): void
    {
        $total = TrackListRuntimeCalculator::totalSeconds([
            ['duration_seconds' => 249],
            ['duration_seconds' => null],
        ]);

        $this->assertNull($total);
    }

    public function test_returns_null_for_an_empty_track_list(): void
    {
        $this->assertNull(TrackListRuntimeCalculator::totalSeconds([]));
    }

    public function test_a_single_zero_length_track_is_a_real_known_value_not_treated_as_unknown(): void
    {
        $total = TrackListRuntimeCalculator::totalSeconds([
            ['duration_seconds' => 0],
            ['duration_seconds' => 180],
        ]);

        $this->assertSame(180, $total);
    }
}
