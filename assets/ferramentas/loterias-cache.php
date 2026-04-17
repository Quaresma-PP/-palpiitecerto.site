<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const API_TOKEN = 'PT8gtyJokGWN0YV';
const API_URL = 'https://apiloterias.com.br/app/v2/resultado?loteria=ultimos&token=' . API_TOKEN;

$cacheDir = __DIR__ . '/cache';
$cacheFile = $cacheDir . '/loterias-ultimos.json';

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function getNowSaoPaulo(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
}

function readCache(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }

    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return null;
    }

    $parsed = json_decode($raw, true);
    if (!is_array($parsed) || !isset($parsed['payload']) || !is_array($parsed['payload'])) {
        return null;
    }

    return $parsed;
}

function shouldRefresh(?array $cache): bool
{
    if ($cache === null || !isset($cache['next_refresh_at'])) {
        return true;
    }

    try {
        $now = getNowSaoPaulo();
        $nextRefresh = new DateTimeImmutable($cache['next_refresh_at'], new DateTimeZone('America/Sao_Paulo'));
        return $now >= $nextRefresh;
    } catch (Throwable $e) {
        return true;
    }
}

function computeNextRefresh(DateTimeImmutable $now): DateTimeImmutable
{
    $checkpoints = [
        [21, 15],
        [21, 30],
        [22, 0],
    ];

    foreach ($checkpoints as [$hour, $minute]) {
        $slot = $now->setTime($hour, $minute, 0);
        if ($now < $slot) {
            return $slot;
        }
    }

    // Depois da ultima janela do dia, agenda para o primeiro slot do dia seguinte.
    return $now->modify('+1 day')->setTime($checkpoints[0][0], $checkpoints[0][1], 0);
}

function fetchRemote(string $url): ?array
{
    $body = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (is_string($response) && $response !== '' && $httpCode >= 200 && $httpCode < 300) {
            $body = $response;
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 12,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if (is_string($response) && $response !== '') {
            $body = $response;
        }
    }

    if ($body === null) {
        return null;
    }

    $parsed = json_decode($body, true);
    if (!is_array($parsed)) {
        return null;
    }

    return $parsed;
}

$cache = readCache($cacheFile);
$mustRefresh = shouldRefresh($cache);

if ($mustRefresh) {
    $remote = fetchRemote(API_URL);
    if (is_array($remote)) {
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        $now = getNowSaoPaulo();
        $newCache = [
            'cached_at' => $now->format(DateTimeInterface::ATOM),
            'next_refresh_at' => computeNextRefresh($now)->format(DateTimeInterface::ATOM),
            'payload' => $remote,
        ];

        @file_put_contents($cacheFile, json_encode($newCache, JSON_UNESCAPED_UNICODE));
        jsonResponse($remote);
    }

    // Se falhar a atualização remota, usa cache antigo quando existir.
    if ($cache !== null) {
        jsonResponse($cache['payload']);
    }

    jsonResponse(['error' => 'Falha ao buscar dados da API e cache indisponivel.'], 502);
}

jsonResponse($cache['payload']);
