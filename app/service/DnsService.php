<?php

namespace app\service;

class DnsService
{
    public function parseTxtRecord(string $output): ?array
    {
        $lines = preg_split('/\r?\n/', $output);
        foreach ($lines as $line) {
            if (strpos($line, '_acme-challenge.') !== false) {
                if (preg_match('/(_acme-challenge\.[^\s]+)\s+TXT\s+value:\s+(.+)/', $line, $matches)) {
                    return [
                        'name' => trim($matches[1]),
                        'value' => trim($matches[2]),
                    ];
                }
            }
        }

        return null;
    }

    public function verifyTxt(string $host, string $value): bool
    {
        $records = dns_get_record($host, DNS_TXT);
        $expected = trim($value, '\"');
        foreach ($records as $record) {
            $txt = $record['txt'] ?? '';
            if ($txt === $value || $txt === $expected || strpos($txt, $expected) !== false) {
                return true;
            }
        }

        return false;
    }
}
