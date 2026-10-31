<?php
declare(strict_types=1);

namespace Announcements\Tests\Unit;

use Announcements\Api\Audience;
use PHPUnit\Framework\TestCase;

final class AudienceTest extends TestCase
{
    public function testStaffSeeEverything(): void
    {
        $this->assertSame(['everyone', 'all', 'staff'], Audience::visibleFor(true, false));
        $this->assertSame(['everyone', 'all', 'staff'], Audience::visibleFor(true, true));
    }

    public function testAgentsSeeEveryoneAndAllButNotStaff(): void
    {
        $this->assertSame(['everyone', 'all'], Audience::visibleFor(false, true));
    }

    public function testCustomersAndUnknownRolesSeeEveryoneOnly(): void
    {
        $this->assertSame(['everyone'], Audience::visibleFor(false, false));
    }

    public function testNormalizeAndLabels(): void
    {
        $this->assertSame('staff', Audience::normalize(' STAFF_ONLY '));
        $this->assertNull(Audience::normalize('nobody'));
        $this->assertSame('All Staff and Agents', Audience::label('all'));
        $this->assertSame('Staff-Only', Audience::label('staff'));
        $this->assertSame('mystery', Audience::label('mystery'));
    }
}
