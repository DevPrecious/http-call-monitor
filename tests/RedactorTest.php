<?php

namespace HttpCallMonitor\Tests;

use GuzzleHttp\Psr7\Uri;
use HttpCallMonitor\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RedactorTest extends TestCase
{
    #[DataProvider('paths')]
    public function test_it_masks_identifiers_in_paths(string $path, string $expected): void
    {
        $this->assertSame($expected, (new Redactor)->path(new Uri("https://api.example.com{$path}")));
    }

    public static function paths(): array
    {
        return [
            'plain path' => ['/v1/charges', '/v1/charges'],
            'no path' => ['', '/'],
            'numeric id' => ['/v1/customers/12345/orders', '/v1/customers/{id}/orders'],
            'uuid' => ['/orders/3f2b8c1e-9d4a-4c6b-8e21-7a5d0f9c1b2e', '/orders/{id}'],
            'stripe style id' => ['/v1/customers/cus_NffrFeUfNV2Hib', '/v1/customers/{id}'],
            'bot token' => ['/bot123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/sendMessage', '/{id}/sendMessage'],
            'token with no digits' => ['/hooks/aBcDeFgHiJkLmNoPqRsTuVwX', '/hooks/{id}'],
            'email address' => ['/users/jane@example.com', '/users/{id}'],
            'encoded email address' => ['/users/jane%40example.com/profile', '/users/{id}/profile'],
            'long lowercase word is kept' => ['/v1/subscription-schedules', '/v1/subscription-schedules'],
        ];
    }

    public function test_custom_replacements_run_after_masking(): void
    {
        $redactor = new Redactor(['#/tenants/[^/]+#' => '/tenants/{tenant}']);

        $this->assertSame('/tenants/{tenant}/users/{id}', $redactor->path(new Uri('https://x.test/tenants/acme/users/42')));
    }

    public function test_host_keeps_only_a_non_default_port(): void
    {
        $redactor = new Redactor;

        $this->assertSame('api.example.com', $redactor->host(new Uri('https://API.example.com:443/x')));
        $this->assertSame('api.example.com:8443', $redactor->host(new Uri('https://api.example.com:8443/x')));
    }

    public function test_error_messages_lose_every_url(): void
    {
        $error = (new Redactor)->error(new RuntimeException(
            'cURL error 7: Failed to connect (see https://curl.se/libcurl/c/libcurl-errors.html) for http://x.test/a?token=SECRET',
        ));

        $this->assertSame('cURL error 7: Failed to connect for [url]', $error);
    }

    public function test_invalid_utf8_in_an_error_cannot_break_the_insert(): void
    {
        $this->assertTrue(mb_check_encoding((new Redactor)->error(new RuntimeException("bad \xC3\x28 bytes")), 'UTF-8'));
    }

    public function test_long_values_are_cut_to_fit_their_columns(): void
    {
        $redactor = new Redactor;

        $this->assertSame(255, mb_strlen($redactor->error(new RuntimeException(str_repeat('x', 400)))));
        $this->assertSame(1000, mb_strlen($redactor->path(new Uri('https://x.test/'.str_repeat('abc/', 400)))));
    }
}
