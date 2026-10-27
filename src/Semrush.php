<?php

namespace Aimeos\KeywordsBridge\Drivers;

use Aimeos\KeywordsBridge\Contracts\Driver;
use Aimeos\KeywordsBridge\Keyword;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;


/**
 * Semrush keyword reports driver using the API v3
 */
class Semrush implements Driver
{
    /** @var array<string, mixed> */
    private array $config;


    /**
     * @param array<string, mixed> $config Settings from ./config/keywords-bridge.php
     */
    public function __construct(array $config = [])
    {
        if (empty($config['key'])) {
            throw new \InvalidArgumentException('Semrush setting [key] is missing');
        }

        $config['database'] = $this->database($config['database'] ?? 'us');
        $this->config = $config;
    }


    public function volume(array $keywords, ?string $country = null): array
    {
        $keywords = array_values(array_unique(array_filter(array_map(fn($keyword) => $this->normalize($keyword), $keywords), fn($keyword) => $keyword !== '')));
        // keywords are passed as semicolon separated list
        $valid = array_filter($keywords, fn($keyword) => !str_contains($keyword, ';'));
        $volumes = [];

        foreach (array_chunk($valid, 100) as $chunk) {
            foreach ($this->request(['type' => 'phrase_these', 'phrase' => implode(';', $chunk)], $country) as [$keyword, $volume]) {
                $volumes[$keyword] ??= $volume;
            }
        }

        return array_map(fn($keyword) => new Keyword($keyword, $volumes[$keyword] ?? null), $keywords);
    }


    public function related(string $keyword, ?string $country = null, int $limit = 50): array
    {
        if (($keyword = $this->normalize($keyword)) === '' || str_contains($keyword, ';')) {
            return [];
        }

        $rows = $this->request([
            'type' => 'phrase_related',
            'phrase' => $keyword,
            'display_limit' => max(1, min($limit, 1000)),
            'display_sort' => 'nq_desc',
        ], $country);

        return array_map(fn($row) => new Keyword($row[0], $row[1]), $rows);
    }


    /**
     * Returns the HTTP client for Semrush API requests
     *
     * @return PendingRequest HTTP client
     */
    protected function client(): PendingRequest
    {
        return Http::timeout($this->config['timeout'] ?? 30);
    }


    /**
     * Returns the Semrush database code for the country
     *
     * @param string $country Two letter ISO country code
     * @return string Semrush database code
     */
    protected function database(string $country): string
    {
        if (!preg_match('/^[a-z]{2}$/i', $country)) {
            throw new \InvalidArgumentException("Invalid country code [$country]");
        }

        $code = strtolower($country);
        return $code === 'gb' ? 'uk' : $code; // Semrush uses "uk" for the United Kingdom
    }


    /**
     * Normalizes the keyword
     *
     * @param string $keyword Keyword
     * @return string Trimmed keyword in lower case with single spaces
     */
    protected function normalize(string $keyword): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $keyword)));
    }


    /**
     * Sends the request to the Semrush API
     *
     * @param array<string, mixed> $params Query parameters
     * @param string|null $country Two letter ISO country code or NULL for the configured database
     * @return array<int, array{0: string, 1: int|null}> List of keyword and volume pairs
     */
    protected function request(array $params, ?string $country): array
    {
        $params += [
            'key' => $this->config['key'],
            'database' => $country !== null ? $this->database($country) : $this->config['database'],
            'export_columns' => 'Ph,Nq',
            'export_decode' => 1,
        ];

        try {
            $response = $this->client()->get('https://api.semrush.com/', $params);
        } catch (ConnectionException $e) {
            // the exception message contains the URL including the API key
            throw new \RuntimeException('Semrush API request failed: Connection error');
        }

        $body = trim($response->body());

        if (str_starts_with($body, 'ERROR 50 ')) { // NOTHING FOUND
            return [];
        }

        if ($response->failed() || str_starts_with($body, 'ERROR')) {
            $error = str_starts_with($body, 'ERROR') ? explode("\n", $body)[0] : 'HTTP status ' . $response->status();
            throw new \RuntimeException('Semrush API request failed: ' . $error);
        }

        $rows = [];

        foreach (array_slice(explode("\n", $body), 1) as $line) { // first line contains the column names
            $cols = explode(';', rtrim($line, "\r"));

            if ($cols[0] !== '') {
                $rows[] = [$this->normalize($cols[0]), is_numeric($cols[1] ?? null) ? (int) $cols[1] : null];
            }
        }

        return $rows;
    }
}
