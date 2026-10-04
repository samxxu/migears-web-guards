<?php

declare(strict_types=1);

namespace MiGears\WebGuards\Tests\UserAgent;

use InvalidArgumentException;
use MiGears\Web\Request;
use MiGears\WebGuards\UserAgent\UserAgentGuard;
use PHPUnit\Framework\TestCase;

final class UserAgentGuardTest extends TestCase
{
    public function testBlockingRefusesAMatchingAgent(): void
    {
        $guard = UserAgentGuard::blocking('curl', 'wget');

        $response = $guard($this->from('curl/8.4.0'));

        $this->assertNotNull($response);
        $this->assertSame(403, $response->status);
        $this->assertSame('{"error":"Forbidden"}', $response->body);
    }

    public function testBlockingLetsANonMatchingAgentThrough(): void
    {
        $guard = UserAgentGuard::blocking('curl', 'wget');

        $this->assertNull($guard($this->from('Mozilla/5.0')));
    }

    public function testAllowingRefusesANonMatchingAgent(): void
    {
        $guard = UserAgentGuard::allowing('MyApp/');

        $this->assertNotNull($guard($this->from('Mozilla/5.0')));
    }

    public function testAllowingLetsAMatchingAgentThrough(): void
    {
        $guard = UserAgentGuard::allowing('MyApp/');

        $this->assertNull($guard($this->from('MyApp/1.2.3')));
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $guard = UserAgentGuard::blocking('curl');

        $this->assertNotNull($guard($this->from('CURL/8.4.0')));
    }

    public function testAMissingAgentIsNotAMatch(): void
    {
        $this->assertNull(UserAgentGuard::blocking('curl')(new Request('GET', '/')), 'deny: nothing to match, nothing to block');
        $this->assertNotNull(UserAgentGuard::allowing('MyApp/')(new Request('GET', '/')), 'allow: no match, no entry');
    }

    public function testAnEmptyBlocklistBlocksNobody(): void
    {
        $this->assertNull(UserAgentGuard::blocking()($this->from('curl/8.4.0')));
    }

    public function testRejectsAnEmptyPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);

        UserAgentGuard::blocking('curl', '  ');
    }

    private function from(string $agent): Request
    {
        return new Request('GET', '/', headers: ['user-agent' => $agent]);
    }
}
