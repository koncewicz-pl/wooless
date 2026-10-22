<?php

namespace Tests\Unit;

use App\Services\OctaneHosts;
use PHPUnit\Framework\TestCase;

class OctaneHostsTest extends TestCase
{
    public function test_single_host_builds_one_https_site_address(): void
    {
        $this->assertSame('https://localhost:443', OctaneHosts::caddySiteAddress('localhost', 443));
        $this->assertSame('localhost', OctaneHosts::primary('localhost'));
    }

    public function test_comma_separated_hosts_are_trimmed_and_joined(): void
    {
        $this->assertSame(
            'https://shop.example.com:443, https://www.shop.example.com:443',
            OctaneHosts::caddySiteAddress(' shop.example.com , www.shop.example.com, ', 443),
        );
        $this->assertSame('shop.example.com', OctaneHosts::primary('shop.example.com, www.shop.example.com'));
        $this->assertSame(['shop.example.com', 'www.shop.example.com'], OctaneHosts::parse('shop.example.com,www.shop.example.com'));
    }

    public function test_empty_or_missing_value_falls_back_to_localhost(): void
    {
        $this->assertSame(['localhost'], OctaneHosts::parse(null));
        $this->assertSame(['localhost'], OctaneHosts::parse(''));
        $this->assertSame(['localhost'], OctaneHosts::parse(' , '));
        $this->assertSame('https://localhost:8443', OctaneHosts::caddySiteAddress(null, 8443));
    }
}
