<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Model\InsuranceData;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class InsuranceDataTest extends TestCase
{
    public function testStreetTownStatusDoesNotAffectOverallMatchStatus(): void
    {
        $data = new InsuranceData([
            'level_match_status' => 'matched',
            'assistance_identity_match_status' => 'matched',
            'street_town_match_status' => 'unmatched',
        ]);

        $this->assertSame('matched', $data->calculateOverallMatchStatus());
    }

    public function testLevelOrAssistanceMismatchStillMarksDataAsSuspicious(): void
    {
        $levelMismatch = new InsuranceData([
            'level_match_status' => 'unmatched',
            'assistance_identity_match_status' => 'matched',
            'street_town_match_status' => 'matched',
        ]);
        $assistanceMismatch = new InsuranceData([
            'level_match_status' => 'matched',
            'assistance_identity_match_status' => 'unmatched',
            'street_town_match_status' => 'matched',
        ]);

        $this->assertSame('unmatched', $levelMismatch->calculateOverallMatchStatus());
        $this->assertSame('unmatched', $assistanceMismatch->calculateOverallMatchStatus());
    }
}
