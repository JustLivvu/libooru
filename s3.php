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
            $host = parse_url($this->endpoint, PHP_URL_HOST);
            $scheme = parse_url($this->endpoint, PHP_URL_SCHEME) ?: 'https';
            $port = parse_url($this->endpoint, PHP_URL_PORT);
            $portStr = $port ? ':' . $port : '';

            return "{$scheme}://{$host}{$portStr}/{$this->bucket}/{$key}";
        }
        return "{$this->endpoint}/{$key}";
    }

    /**
     * Helper to make HTTP request using stream context (pure PHP fallback for cURL).
     */
    private function httpRequest(string $method, string $url, array $headers, string $payload = ''): array
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
                'timeout'       => 300,
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
            throw new RuntimeException("S3 upload failed (HTTP {$res['code']}).");
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
            '--output', '/dev/null', '--write-out', '%{http_code}',
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
        $statusText = trim((string)stream_get_contents($pipes[1]));
        $errorText = trim((string)stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $httpCode = ctype_digit($statusText) ? (int)$statusText : 0;

        if ($exitCode !== 0) {
            throw new RuntimeException('Streaming S3 upload failed: ' . substr($errorText, 0, 300));
        }
        return ['code' => $httpCode, 'body' => ''];
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

    /**
     * Stream object directly to client output.
     */
    public function streamObject(string $key): void
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
            fpassthru($fp);
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
