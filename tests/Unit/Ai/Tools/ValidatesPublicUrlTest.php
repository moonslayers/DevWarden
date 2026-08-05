<?php

use App\Ai\Tools\Concerns\ValidatesPublicUrl;
use Tests\TestCase;

uses(TestCase::class);

function validatesPublicUrlProbe(): object
{
    return new class
    {
        use ValidatesPublicUrl;
    };
}

test('isPublicUrl rejects alternative IPv4 encodings of non-public addresses', function (string $url) {
    expect(validatesPublicUrlProbe()->isPublicUrl($url))->toBeFalse();
})->with([
    'decimal integer loopback' => 'http://2130706433/',
    'hexadecimal loopback' => 'http://0x7f000001/',
    'octal dotted loopback' => 'http://0177.0.0.1/',
    'shorthand dotted loopback' => 'http://127.1/',
    'shorthand dotted-decimal loopback' => 'http://127.0.1/',
    'hex dotted loopback' => 'http://0x7f.0.0.1/',
    'decimal integer private' => 'http://3232235777/',
    'hexadecimal private' => 'http://0xc0a80101/',
    'hexadecimal link-local' => 'http://0x7f000101/',
    'zero-encoded loopback' => 'http://0.0.0.1/',
]);

test('isPublicUrl rejects the standard non-public host forms', function (string $url) {
    expect(validatesPublicUrlProbe()->isPublicUrl($url))->toBeFalse();
})->with([
    'loopback ip' => 'http://127.0.0.1/',
    'ipv6 loopback' => 'http://[::1]/',
    'localhost hostname' => 'http://localhost/',
    'localhost subdomain' => 'http://api.localhost/',
    'private ip' => 'http://192.168.1.1/',
    'link-local ip' => 'http://169.254.1.1/',
    'non-http scheme' => 'ftp://example.com/file',
    'no scheme' => 'example.com',
]);

test('isPublicUrl keeps accepting legitimate public URLs', function (string $url) {
    expect(validatesPublicUrlProbe()->isPublicUrl($url))->toBeTrue();
})->with([
    'public dns hostname' => 'http://example.com/',
    'public subdomain hostname' => 'http://images.example.com/',
    'public dotted-quad ip' => 'http://8.8.8.8/',
    'public ipv6' => 'http://[2606:4700::1111]/',
    'decimal encoding of a public ip' => 'http://134744072/',
    'hexadecimal encoding of a public ip' => 'http://0x08080808/',
]);
