<?php

namespace App\Ai\Tools\Concerns;

trait ValidatesPublicUrl
{
    /**
     * Determine whether the given URL is a valid public http(s) URL.
     */
    public function isPublicUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        if ($host === 'localhost' || $host === '0.0.0.0' || str_ends_with($host, '.localhost')) {
            return false;
        }

        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host);
        }

        $normalized = $this->normalizeIpv4Address($host);

        if ($normalized !== null) {
            return $this->isPublicIp($normalized);
        }

        foreach ($this->resolveIpAddresses($host) as $ip) {
            if (! $this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a hostname to all of its IP addresses (IPv4 and IPv6).
     *
     * @return array<int, string>
     */
    protected function resolveIpAddresses(string $host): array
    {
        $ips = [];

        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            array_push($ips, ...$ipv4);
        }

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return $ips;
    }

    /**
     * Determine whether an IP address is publicly routable.
     */
    protected function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return true;
        }

        if ($ip === '::1' || $ip === '0:0:0:0:0:0:0:1') {
            return false;
        }

        // fc00::/7 unique local addresses.
        if (($packed[0] & "\xfe") === "\xfc") {
            return false;
        }

        // fe80::/10 link-local addresses.
        if ($packed[0] === "\xfe" && ($packed[1] & "\xc0") === "\x80") {
            return false;
        }

        // ::ffff:a.b.c.d IPv4-mapped addresses.
        if (substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            $ipv4 = @inet_ntop(substr($packed, 12));

            return $ipv4 !== false && $this->isPublicIp($ipv4);
        }

        // ::/96 IPv4-compatible addresses.
        if (substr($packed, 0, 12) === str_repeat("\x00", 12)) {
            $ipv4 = @inet_ntop(substr($packed, 12));

            return $ipv4 !== false && $this->isPublicIp($ipv4);
        }

        return true;
    }

    /**
     * Normalize an inet_aton-style IPv4 encoding to a dotted-quad address.
     *
     * cURL/Guzzle resolve shorthand numeric hosts (decimal, hex and octal forms
     * such as 2130706433, 0x7f000001 or 127.1) that filter_var() does not
     * recognize as IP addresses. Normalizing them here prevents SSRF through
     * addresses that otherwise fall through to a failing DNS lookup.
     */
    protected function normalizeIpv4Address(string $host): ?string
    {
        if (! preg_match('/^[0-9a-fx.]+$/i', $host)) {
            return null;
        }

        $parts = explode('.', $host);

        if ($parts === [] || count($parts) > 4) {
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            $value = $this->parseIpv4Part($part);

            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        return match (count($values)) {
            1 => $this->buildIpv4Address($values[0] >> 24, ($values[0] >> 16) & 0xFF, ($values[0] >> 8) & 0xFF, $values[0] & 0xFF),
            2 => $values[1] <= 0xFFFFFF
                ? $this->buildIpv4Address($values[0], ($values[1] >> 16) & 0xFF, ($values[1] >> 8) & 0xFF, $values[1] & 0xFF)
                : null,
            3 => $values[2] <= 0xFFFF
                ? $this->buildIpv4Address($values[0], $values[1], ($values[2] >> 8) & 0xFF, $values[2] & 0xFF)
                : null,
            4 => $this->buildIpv4Address($values[0], $values[1], $values[2], $values[3]),
            default => null,
        };
    }

    /**
     * Parse a single inet_aton IPv4 part (decimal, octal or hexadecimal).
     */
    protected function parseIpv4Part(string $part): ?int
    {
        if (preg_match('/^0[xX][0-9a-fA-F]+$/', $part)) {
            $value = hexdec(substr($part, 2));

            return $value <= 0xFFFFFFFF ? $value : null;
        }

        if (preg_match('/^0[0-7]+$/', $part)) {
            $value = octdec($part);

            return $value <= 0xFFFFFFFF ? $value : null;
        }

        if (preg_match('/^[1-9][0-9]*$/', $part)) {
            $value = (int) $part;

            return $value <= 0xFFFFFFFF ? $value : null;
        }

        return $part === '0' ? 0 : null;
    }

    /**
     * Build a dotted-quad IPv4 string from four byte values, or null if any is out of range.
     */
    protected function buildIpv4Address(int $a, int $b, int $c, int $d): ?string
    {
        if ($a > 0xFF || $b > 0xFF || $c > 0xFF || $d > 0xFF) {
            return null;
        }

        return "$a.$b.$c.$d";
    }
}
