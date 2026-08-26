<?php
/**
 * @file classes/ColophonClient.php
 *
 * A small HTTP client for the Colophon API, and the webhook verifier.
 *
 * Deliberately free of OJS classes so it can be unit-tested with plain PHPUnit
 * on any machine, and so the verifier is one function the README can quote.
 * Mirrors the contract in /api/v1/reference: Api-Key auth, Idempotency-Key on
 * job creation, 202 + status_url, and X-Colophon-Signature on deliveries.
 */

namespace APP\plugins\generic\colophon\classes;

class ColophonClient
{
    public const SIGNATURE_HEADER = 'X-Colophon-Signature';
    public const DELIVERY_HEADER = 'X-Colophon-Delivery';
    public const CONNECT_TIMEOUT = 10;
    public const READ_TIMEOUT = 60;

    private string $base;
    private string $apiKey;
    /** @var callable|null injectable transport for tests: fn(method, url, headers, body): [status, body] */
    private $transport;

    public function __construct(string $base, string $apiKey, ?callable $transport = null)
    {
        $this->base = rtrim($base, '/');
        $this->apiKey = $apiKey;
        $this->transport = $transport;
    }

    /**
     * POST /api/v1/articles/{code}/jobs — start a production job.
     * Returns the decoded 202 body (job_id, status_url, ...), or throws.
     */
    public function startJob(string $articleCode, string $idempotencyKey, ?string $callbackUrl, string $kind = 'produce_package'): array
    {
        $payload = ['kind' => $kind];
        if ($callbackUrl) {
            $payload['callback_url'] = $callbackUrl;
        }
        [$status, $body] = $this->request('POST', "/api/v1/articles/{$articleCode}/jobs", [
            'Content-Type: application/json',
            'Idempotency-Key: ' . $idempotencyKey,
        ], json_encode($payload));
        $data = json_decode($body, true) ?: [];
        if ($status === 202) {
            return $data;
        }
        throw new ColophonApiException($status, $data['code'] ?? 'error', $data['message'] ?? $body);
    }

    /** GET /api/v1/articles/{code}/package — the ZIP bytes, or throws. */
    public function downloadPackage(string $articleCode): string
    {
        [$status, $body] = $this->request('GET', "/api/v1/articles/{$articleCode}/package", [], null);
        if ($status === 200) {
            return $body;
        }
        $data = json_decode($body, true) ?: [];
        throw new ColophonApiException($status, $data['code'] ?? 'error', $data['message'] ?? 'package not available');
    }

    /** GET /api/v1/jobs/{id} */
    public function getJob(int $jobId): array
    {
        [$status, $body] = $this->request('GET', "/api/v1/jobs/{$jobId}", [], null);
        $data = json_decode($body, true) ?: [];
        if ($status === 200) {
            return $data;
        }
        throw new ColophonApiException($status, $data['code'] ?? 'error', $data['message'] ?? $body);
    }

    // ----- pairing (device flow; no API key yet) ---------------------------

    /**
     * POST /api/v1/connect — begin pairing. Returns pairing_code, confirm_url,
     * poll_url, expires_in. Unauthenticated by design: this is how the key is
     * obtained in the first place.
     */
    public function connectStart(array $journalMeta): array
    {
        [$status, $body] = $this->request('POST', '/api/v1/connect', [
            'Content-Type: application/json',
        ], json_encode($journalMeta), false);
        $data = json_decode($body, true) ?: [];
        if ($status === 201) {
            return $data;
        }
        throw new ColophonApiException($status, $data['code'] ?? 'error', $data['message'] ?? $body);
    }

    /** GET /api/v1/connect/{code} — state only; never carries credentials. */
    public function connectState(string $code): array
    {
        [$status, $body] = $this->request('GET', '/api/v1/connect/' . rawurlencode($code), [], null, false);
        return json_decode($body, true) ?: ['state' => 'error', 'http' => $status];
    }

    /**
     * POST /api/v1/connect/{code}/claim — the single credential delivery.
     * On the first successful claim the response carries api_key and
     * webhook_secret; every later claim only the state.
     */
    public function connectClaim(string $code): array
    {
        [$status, $body] = $this->request('POST', '/api/v1/connect/' . rawurlencode($code) . '/claim', [], null, false);
        return json_decode($body, true) ?: ['state' => 'error', 'http' => $status];
    }

    // ----- one-call article intake -----------------------------------------

    /**
     * POST /api/v1/articles (multipart) — create the article from the accepted
     * manuscript, with the journal-side metadata as a JATS front the platform
     * applies right after extraction. 202 body carries code + status_url; a
     * repeated submission_ref answers 200 with the existing article.
     */
    public function createArticle(
        string $docxPath,
        string $docxName,
        array $fields,
        string $idempotencyKey
    ): array {
        $post = $fields;
        $post['docx_file'] = new \CURLFile($docxPath, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $docxName);
        [$status, $body] = $this->requestMultipart('/api/v1/articles', $post, [
            'Idempotency-Key: ' . $idempotencyKey,
        ]);
        $data = json_decode($body, true) ?: [];
        if ($status === 202 || $status === 200) {
            return $data;
        }
        throw new ColophonApiException($status, $data['code'] ?? 'error', $data['message'] ?? $body);
    }

    /**
     * Verify X-Colophon-Signature: "t=<unix>,v1=<hex HMAC-SHA256 of '{t}.{body}'>".
     * Rejects outside the tolerance window (replay) and uses a constant-time compare.
     */
    public static function verifySignature(string $secret, string $rawBody, string $header, int $tolerance = 300): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $kv) {
            $pair = explode('=', $kv, 2);
            if (count($pair) === 2) {
                $parts[trim($pair[0])] = trim($pair[1]);
            }
        }
        $ts = (int) ($parts['t'] ?? 0);
        $their = $parts['v1'] ?? '';
        if ($ts <= 0 || $their === '' || abs(time() - $ts) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $ts . '.' . $rawBody, $secret);
        return hash_equals($expected, $their);
    }

    /** @return array{0:int,1:string} [status, body] — multipart POST (CURLFile-aware). */
    /**
     * POST /api/v1/panel-link — a short-lived signed URL that lands the
     * journal owner signed in on their Colophon panel. The key is the
     * authority; no secrets ride the response.
     */
    public function panelLink(string $next = ''): array
    {
        $payload = $next === '' ? '{}' : json_encode(['next' => $next]);
        [$status, $body] = $this->request('POST', '/api/v1/panel-link', ['Content-Type: application/json'], $payload);
        $data = json_decode($body, true) ?: [];
        if ($status === 200) {
            return $data;
        }
        throw new ColophonApiException($status, $data['code'] ?? 'error', $data['message'] ?? $body);
    }

    /** GET /api/v1/credits — the journal's balance, credits only. */
    public function credits(): array
    {
        [$status, $body] = $this->request('GET', '/api/v1/credits', [], null);
        $data = json_decode($body, true) ?: [];
        if ($status === 200) {
            return $data;
        }
        throw new ColophonApiException($status, $data['code'] ?? 'error', $data['message'] ?? $body);
    }

    private function requestMultipart(string $path, array $post, array $headers): array
    {
        $url = $this->base . $path;
        $headers[] = 'Authorization: Api-Key ' . $this->apiKey;
        $headers[] = 'Accept: application/json';
        $headers[] = 'User-Agent: Colophon-OJS-Plugin/2.0';
        if ($this->transport) {
            return ($this->transport)('POST', $url, $headers, $post);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) {
            throw new ColophonApiException(0, 'transport', $err ?: 'connection failed');
        }
        return [$status, (string) $out];
    }

    /** @return array{0:int,1:string} [status, body] */
    private function request(string $method, string $path, array $headers, ?string $body, bool $withAuth = true): array
    {
        $url = $this->base . $path;
        if ($withAuth) {
            $headers[] = 'Authorization: Api-Key ' . $this->apiKey;
        }
        $headers[] = 'Accept: application/json';
        $headers[] = 'User-Agent: Colophon-OJS-Plugin/2.0';
        if ($this->transport) {
            return ($this->transport)($method, $url, $headers, $body);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::READ_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) {
            throw new ColophonApiException(0, 'transport', $err ?: 'connection failed');
        }
        return [$status, (string) $out];
    }
}

class ColophonApiException extends \RuntimeException
{
    public int $httpStatus;
    public string $apiCode;

    public function __construct(int $httpStatus, string $apiCode, string $message)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->apiCode = $apiCode;
    }
}
