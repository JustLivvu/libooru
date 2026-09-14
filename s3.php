<?php
declare(strict_types=1);

/**
 * Pure PHP S3 Client using AWS Signature Version 4.
 * Compatible with AWS S3, MinIO, Cloudflare R2, Wasabi, DigitalOcean Spaces, Backblaze B2, etc.
 */
class S3Client
{
    private string $endpoint;
    private string $region;
    private string $bucket;
    private string $accessKey;
    private string $secretKey;

    public function __construct(string $endpoint, string $region, string $bucket, string $accessKey, string $secretKey)
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            throw new RuntimeException('S3 endpoint is required.');
        }
        if (!preg_match('#^https?://#i', $endpoint)) {
            $endpoint = 'https://' . $endpoint;
        }

        $parsedEndpoint = parse_url($endpoint);
        if (!is_array($parsedEndpoint)
            || empty($parsedEndpoint['host'])
            || !in_array(strtolower((string)($parsedEndpoint['scheme'] ?? '')), ['http', 'https'], true)
        ) {
            throw new RuntimeException('S3 endpoint must be a valid HTTP or HTTPS URL.');
        }

        $this->endpoint  = rtrim($endpoint, '/');
        $this->region    = $region ?: 'us-east-1';
        $this->bucket    = $bucket;
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
    }

    /**
     * Build full URL for a key.
     */
    public function getUrl(string $key): string
    {
        $key = ltrim($key, '/');
        if ($this->bucket !== '') {
            $host = (string)parse_url($this->endpoint, PHP_URL_HOST);
            $scheme = parse_url($this->endpoint, PHP_URL_SCHEME) ?: 'https';
            $port = parse_url($this->endpoint, PHP_URL_PORT);
            $portStr = $port ? ':' . $port : '';

            // A virtual-hosted endpoint already contains the bucket name.
            // In that form, adding it to the path produces an invalid request.
            if (str_starts_with(strtolower($host), strtolower($this->bucket) . '.')) {
                return "{$scheme}://{$host}{$portStr}/{$key}";
            }

            return "{$scheme}://{$host}{$portStr}/{$this->bucket}/{$key}";
        }
        return "{$this->endpoint}/{$key}";
    }

    /** Create a time-limited, browser-usable GET URL without exposing the secret key. */
    public function getPresignedUrl(string $key, int $expires = 3600): string
    {
        $expires = max(1, min($expires, 604800));
        $url = $this->getUrl($key);
        $parsed = parse_url($url);
        if (!is_array($parsed) || empty($parsed['host'])) {
            throw new RuntimeException('Could not build S3 media URL.');
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
        $path = $parsed['path'] ?? '/';
        $time = time();
        $amzDate = gmdate('Ymd\THis\Z', $time);
        $dateStamp = gmdate('Ymd', $time);
        $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";

        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->accessKey . '/' . $credentialScope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string)$expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($query, SORT_STRING);
        $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $canonicalRequest = implode("\n", [
            'GET',
            $path,
            $canonicalQuery,
            "host:{$host}\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $query['X-Amz-Signature'] = hash_hmac('sha256', $stringToSign, $kSigning);

        return "{$scheme}://{$host}{$path}?" . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Helper to make HTTP request using stream context (pure PHP fallback for cURL).
     */
    private function httpRequest(string $method, string $url, array $headers, string $payload = '', int $timeout = 300): array
    {
        $headerLines = [];
        foreach ($headers as $h) {
            $headerLines[] = $h;
        }

        $opts = [
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headerLines),
                'content'       => $payload,
                'ignore_errors' => true,
                'timeout'       => $timeout,
            ]
        ];

        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            if (preg_match('#HTTP/\d\.\d\s+(\d+)#i', $http_response_header[0], $m)) {
                $httpCode = (int)$m[1];
            }
        }

        return ['code' => $httpCode, 'body' => $response !== false ? $response : ''];
    }

    /**
     * Upload a file or string to S3. Files are streamed by curl and never loaded
     * into the PHP worker's memory.
     */
    public function putObject(string $key, string $bodyOrPath, string $contentType = 'application/octet-stream', bool $isFile = false): bool
    {
        $key = ltrim($key, '/');
        $url = $this->getUrl($key);

        if ($isFile) {
            $res = $this->uploadFile($url, $bodyOrPath, $contentType);
        } else {
            $headers = $this->createSignedHeaders('PUT', $url, $bodyOrPath, $contentType);
            $res = $this->httpRequest('PUT', $url, $headers, $bodyOrPath);
        }

        if ($res['code'] < 200 || $res['code'] >= 300) {
            $detail = $this->getErrorDetail((string)($res['body'] ?? ''));
            throw new RuntimeException("S3 upload failed (HTTP {$res['code']}){$detail}.");
        }

        return true;
    }

    private function uploadFile(string $url, string $path, string $contentType): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('S3 upload source is not readable.');
        }
        $payloadHash = hash_file('sha256', $path);
        $size = filesize($path);
        if ($payloadHash === false || $size === false) {
            throw new RuntimeException('Could not inspect the S3 upload source.');
        }

        $headers = $this->createSignedHeadersForHash('PUT', $url, $payloadHash, $contentType);
        $headers[] = 'Content-Length: ' . $size;
        $command = [
            'curl', '--silent', '--show-error', '--max-time', '300',
            '--request', 'PUT', '--upload-file', $path,
            '--output', '-', '--write-out', "\n__LIBOORU_HTTP_STATUS__%{http_code}",
        ];
        foreach ($headers as $header) {
            $command[] = '--header';
            $command[] = $header;
        }
        $command[] = $url;

        $pipes = [];
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the streaming S3 upload.');
        }
        $responseText = (string)stream_get_contents($pipes[1]);
        $errorText = trim((string)stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException('Streaming S3 upload failed: ' . substr($errorText, 0, 300));
        }
        if (!preg_match('/\n__LIBOORU_HTTP_STATUS__(\d{3})$/', $responseText, $match, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('S3 upload returned an unreadable HTTP status.');
        }

        $body = substr($responseText, 0, $match[0][1]);
        return ['code' => (int)$match[1][0], 'body' => $body];
    }

    private function getErrorDetail(string $body): string
    {
        if ($body === '') return '';

        $xml = @simplexml_load_string($body);
        if ($xml === false) return '';

        $code = trim((string)($xml->Code ?? ''));
        $message = trim((string)($xml->Message ?? ''));
        if ($code === '' && $message === '') return '';

        $detail = $code;
        if ($message !== '') $detail .= ($detail !== '' ? ': ' : '') . $message;
        return ' — ' . substr($detail, 0, 300);
    }

    /**
     * Delete an object from S3.
     */
    public function deleteObject(string $key): bool
    {
        $key = ltrim($key, '/');
        $url = $this->getUrl($key);

        $headers = $this->createSignedHeaders('DELETE', $url, '');
        $res = $this->httpRequest('DELETE', $url, $headers);

        return ($res['code'] >= 200 && $res['code'] < 300) || $res['code'] === 404;
    }

    /**
     * Fetch object content into string.
     */
    public function getObject(string $key): ?string
    {
        $key = ltrim($key, '/');
        $url = $this->getUrl($key);

        $headers = $this->createSignedHeaders('GET', $url, '');
        $res = $this->httpRequest('GET', $url, $headers);

        if ($res['code'] === 200) {
            return $res['body'];
        }

        return null;
    }
    /** Download an object to a local file without loading it into memory. */
    public function getObjectToFile(string $key, string $path): void
    {
        $key = ltrim($key, '/');
        $url = $this->getUrl($key);
        $headers = $this->createSignedHeaders('GET', $url, '');
        $command = [
            'curl', '--silent', '--show-error', '--max-time', '300',
            '--request', 'GET', '--output', $path, '--write-out', '%{http_code}',
        ];
        foreach ($headers as $header) {
            $command[] = '--header';
            $command[] = $header;
        }
        $command[] = $url;

        $pipes = [];
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the streaming S3 download.');
        }
        $statusText = trim((string)stream_get_contents($pipes[1]));
        $errorText = trim((string)stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $httpCode = ctype_digit($statusText) ? (int)$statusText : 0;

        if ($exitCode !== 0 || $httpCode !== 200) {
            @unlink($path);
            throw new RuntimeException('S3 download failed' . ($httpCode ? " (HTTP {$httpCode})" : '') . ': ' . substr($errorText, 0, 300));
        }
    }

    /** List objects below a prefix using S3 ListObjectsV2. */
    public function listObjects(string $prefix = ''): array
    {
        $objects = [];
        $continuationToken = null;

        do {
            $query = ['list-type' => '2', 'prefix' => ltrim($prefix, '/')];
            if ($continuationToken !== null) {
                $query['continuation-token'] = $continuationToken;
            }
            ksort($query, SORT_STRING);
            $url = $this->getUrl('') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $headers = $this->createSignedHeaders('GET', $url, '');
            $res = $this->httpRequest('GET', $url, $headers, '', 15);
            if ($res['code'] !== 200) {
                throw new RuntimeException("S3 object listing failed (HTTP {$res['code']}).");
            }

            $xml = @simplexml_load_string($res['body']);
            if ($xml === false) {
                throw new RuntimeException('S3 returned an invalid object listing.');
            }
            foreach ($xml->Contents as $item) {
                $objects[] = [
                    'key' => (string)$item->Key,
                    'size' => (int)$item->Size,
                    'last_modified' => (string)$item->LastModified,
                ];
            }
            $continuationToken = ((string)$xml->IsTruncated === 'true')
                ? (string)$xml->NextContinuationToken
                : null;
        } while ($continuationToken !== null && $continuationToken !== '');

        usort($objects, fn(array $a, array $b): int => strcmp($b['last_modified'], $a['last_modified']));
        return $objects;
    }


    /**
     * Stream object directly to client output.
     */
    public function streamObject(string $key, bool $rateLimited = false): void
    {
        $key = ltrim($key, '/');
        $url = $this->getUrl($key);

        $headers = $this->createSignedHeaders('GET', $url, '');
        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if (preg_match('/^bytes=\d*-\d*$/', $range)) {
            // Forward Safari's byte-range request to S3. The response headers
            // are relayed below so the browser receives 206 Partial Content.
            $headers[] = 'Range: ' . $range;
        }
        $headerLines = implode("\r\n", $headers);

        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => $headerLines,
                'timeout' => 300,
            ]
        ]);

        $fp = @fopen($url, 'rb', false, $context);
        if ($fp) {
            $meta = stream_get_meta_data($fp);
            $responseHeaders = $meta['wrapper_data'] ?? [];
            foreach ($responseHeaders as $header) {
                if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#i', $header, $matches)) {
                    http_response_code((int)$matches[1]);
                } elseif (preg_match('/^(Content-Length|Content-Range|Accept-Ranges):/i', $header)) {
                    header($header, true);
                }
            }
            if ($rateLimited) {
                $burstRemaining = VIDEO_RATE_LIMIT_AFTER;
                $limitedBytes = 0;
                $limitStartedAt = null;
                while (!feof($fp) && !connection_aborted()) {
                    $chunk = fread($fp, 65536);
                    if ($chunk === false || $chunk === '') break;
                    $chunkLength = strlen($chunk);
                    echo $chunk;

                    $burstBytes = min($burstRemaining, $chunkLength);
                    $burstRemaining -= $burstBytes;
                    $limitedInChunk = $chunkLength - $burstBytes;
                    if ($limitedInChunk > 0) {
                        $limitStartedAt ??= microtime(true);
                        $limitedBytes += $limitedInChunk;
                        $delay = ($limitedBytes / VIDEO_RATE_LIMIT) - (microtime(true) - $limitStartedAt);
                        if ($delay > 0) usleep((int)($delay * 1000000));
                    }
                }
            } else {
                fpassthru($fp);
            }
            fclose($fp);
        }
    }

    /**
     * Create AWS SigV4 signed headers.
     */
    private function createSignedHeaders(string $method, string $url, string $payload, string $contentType = ''): array
    {
        return $this->createSignedHeadersForHash($method, $url, hash('sha256', $payload), $contentType);
    }

    private function createSignedHeadersForHash(string $method, string $url, string $payloadHash, string $contentType = ''): array
    {
        $parsedUrl = parse_url($url);
        if (!is_array($parsedUrl) || empty($parsedUrl['host'])) {
            throw new RuntimeException('Could not build a valid S3 request URL. Check the S3 endpoint.');
        }
        $host   = $parsedUrl['host'] . (isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '');
        $path   = $parsedUrl['path'] ?? '/';
        $query  = $parsedUrl['query'] ?? '';

        $time      = time();
        $amzDate   = gmdate('Ymd\THis\Z', $time);
        $dateStamp = gmdate('Ymd', $time);

        $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n";
        $signedHeaders    = "host;x-amz-content-sha256;x-amz-date";

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $path,
            $query,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $stringToSign    = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $headers = [
            "Host: {$host}",
            "x-amz-date: {$amzDate}",
            "x-amz-content-sha256: {$payloadHash}",
            "Authorization: {$authorization}",
        ];

        if ($contentType !== '') {
            $headers[] = "Content-Type: {$contentType}";
        }

        return $headers;
    }
}
