<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\IpFilter;

use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\WebGuards\IpFilter\IpFilterGuard;
use PHPUnit\Framework\TestCase;

final class IpFilterGuardTest extends TestCase
{
    public function testAllowingLetsAClientInsideTheListThrough(): void
    {
        $guard = IpFilterGuard::allowing('10.0.0.0/8');

        $this->assertNull($guard($this->from('10.1.2.3')));
    }

    public function testAllowingRefusesAClientOutsideTheList(): void
    {
        $guard = IpFilterGuard::allowing('10.0.0.0/8');

        $response = $guard($this->from('192.168.1.1'));

        $this->assertNotNull($response);
        $this->assertSame(403, $response->status);
        $this->assertSame('{"error":"Forbidden"}', $response->body);
    }

    public function testBlockingRefusesAClientInsideTheList(): void
    {
        $guard = IpFilterGuard::blocking('10.0.0.0/8');

        $this->assertNotNull($guard($this->from('10.1.2.3')));
    }

    public function testBlockingLetsAClientOutsideTheListThrough(): void
    {
        $guard = IpFilterGuard::blocking('10.0.0.0/8');

        $this->assertNull($guard($this->from('192.168.1.1')));
    }

    public function testMatchesStayInsideTheCidrBoundary(): void
    {
        $guard = IpFilterGuard::allowing('10.0.0.0/24');

        $this->assertNull($guard($this->from('10.0.0.255')), 'the last address in the range matches');
        $this->assertNotNull($guard($this->from('10.0.1.0')), 'the first address past the range does not');
    }

    public function testABareAddressIsAnExactMatch(): void
    {
        $guard = IpFilterGuard::allowing('10.0.0.7');

        $this->assertNull($guard($this->from('10.0.0.7')));
        $this->assertNotNull($guard($this->from('10.0.0.8')));
    }

    public function testMatchesIpv6Networks(): void
    {
        $guard = IpFilterGuard::allowing('2001:db8::/32');

        $this->assertNull($guard($this->from('2001:db8:1234::1')));
        $this->assertNotNull($guard($this->from('2001:db9::1')));
    }

    public function testDifferentAddressFamiliesNeverMatch(): void
    {
        $guard = IpFilterGuard::allowing('10.0.0.0/8');

        $this->assertNotNull($guard($this->from('2001:db8::1')));
    }

    public function testAnEmptyAllowlistAllowsNobody(): void
    {
        $guard = IpFilterGuard::allowing();

        $this->assertNotNull($guard($this->from('10.0.0.1')));
    }

    public function testAnEmptyDenylistBlocksNobody(): void
    {
        $guard = IpFilterGuard::blocking();

        $this->assertNull($guard($this->from('10.0.0.1')));
    }

    public function testAMissingClientAddressIsNotAMatch(): void
    {
        $this->assertNotNull(IpFilterGuard::allowing('10.0.0.0/8')(new Request('GET', '/')), 'allow: no address, no entry');
        $this->assertNull(IpFilterGuard::blocking('10.0.0.0/8')(new Request('GET', '/')), 'deny: no address, nothing to block');
    }

    public function testRejectsAValueThatIsNotAnIpOrNetwork(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IpFilterGuard::allowing('not-an-ip');
    }

    public function testRejectsAnOutOfRangePrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IpFilterGuard::allowing('10.0.0.0/33');
    }

    public function testRejectsANonNumericPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IpFilterGuard::allowing('10.0.0.0/x');
    }

    public function testMatchesStayInsideANonAlignedCidr(): void
    {
        // /25 is not a whole number of octets, so the last byte is masked.
        $guard = IpFilterGuard::allowing('10.0.0.0/25');

        $this->assertNull($guard($this->from('10.0.0.127')), 'the last address of the /25 matches');
        $this->assertNotNull($guard($this->from('10.0.0.128')), 'the first address past the /25 does not');
    }

    public function testAClientAddressThatIsNotAnIpIsNotAMatch(): void
    {
        $this->assertNotNull(IpFilterGuard::allowing('10.0.0.0/8')($this->from('not-an-ip')), 'allow: a malformed address is not in the list');
        $this->assertNull(IpFilterGuard::blocking('10.0.0.0/8')($this->from('not-an-ip')), 'deny: a malformed address matches nothing');
    }

    private function from(string $ip): Request
    {
        return new Request('GET', '/', server: ['REMOTE_ADDR' => $ip]);
    }
}
