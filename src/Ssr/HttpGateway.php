<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Ssr;

use Closure;
use ProcessWire\Wire;
use Throwable;
use Totoglu\Inertia\Contracts\SsrGatewayInterface;

/**
 * Dispatches the Inertia Page Object JSON payload to a local Node/Bun SSR server
 * (default `http://127.0.0.1:13714/render`), falling back to `null` (CSR) on failure.
 */
class HttpGateway extends Wire implements SsrGatewayInterface
{
	/** Maximum SSR response body size accepted from the SSR server (16 MB). */
	public const int MAX_SSR_RESPONSE_BYTES = 16_777_216;

	public function __construct(
		private string $url = 'http://127.0.0.1:13714/render',
		private float $timeoutSeconds = 1.5,
		private bool $enabled = false,
		private ?Closure $transport = null,
		private ?Closure $dnsResolver = null
	) {
		parent::__construct();
		$this->setUrl($url);
		$this->setTimeout($timeoutSeconds);
	}

	public static function normalizeUrl(string $url): string
	{
		$trimmed = rtrim(trim($url), '/');
		if ($trimmed !== '' && !str_ends_with($trimmed, '/render')) {
			$trimmed .= '/render';
		}

		return $trimmed !== '' ? $trimmed : 'http://127.0.0.1:13714/render';
	}

	public function setUrl(string $url): self
	{
		$this->url = self::normalizeUrl($url);

		return $this;
	}

	public function setEnabled(bool $enabled): self
	{
		$this->enabled = $enabled;

		return $this;
	}

	public function setTimeout(float $seconds): self
	{
		$this->timeoutSeconds = max(0.1, $seconds);

		return $this;
	}

	/**
	 * Validate whether the candidate SSR server URL complies with strict loopback policy
	 * (`127.0.0.1`, `::1`, `localhost`) or `$config->inertiaSsrAllowedHosts`.
	 */
	public function isSafeSsrUrl(string $url): bool
	{
		return $this->resolveSafeTarget($url) !== null;
	}

	/**
	 * Dispatch the Page Object to the SSR server.
	 *
	 * @param array<string, mixed> $pageObject
	 */
	public function dispatch(array $pageObject): ?SsrResponse
	{
		if (!$this->enabled) {
			return null;
		}

		$target = $this->resolveSafeTarget($this->url);
		if ($target === null) {
			return null;
		}

		$json = json_encode($pageObject, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		if (!is_string($json) || $json === '') {
			return null;
		}

		try {
			$result = $this->transport !== null
				? $this->executeCustomTransport($this->transport, $json, $target['pinnedIp'])
				: $this->executeCurlTransport($json, $target);

			if ($result === null) {
				$this->logSsrFailure(sprintf($this->_('SSR server unreachable or returned empty response at %s'), $this->url));

				return null;
			}

			if ($result['abortedBySize'] || strlen($result['body']) > self::MAX_SSR_RESPONSE_BYTES) {
				$this->logSsrFailure(sprintf($this->_('SSR server unreachable or returned empty response at %s'), $this->url));

				return null;
			}

			$httpCode = $result['status'];
			if ($httpCode < 200 || $httpCode >= 300) {
				$this->logSsrFailure(sprintf($this->_('SSR server returned HTTP %1$d at %2$s'), $httpCode, $this->url));

				return null;
			}

			$raw = $result['body'];
			if (trim($raw) === '') {
				$this->logSsrFailure(sprintf($this->_('SSR server unreachable or returned empty response at %s'), $this->url));

				return null;
			}

			$decoded = json_decode($raw, true, 128, JSON_INVALID_UTF8_SUBSTITUTE);
			if (!is_array($decoded) || !isset($decoded['body']) || !is_string($decoded['body'])) {
				$this->logSsrFailure($this->_('SSR server returned malformed JSON payload.'));

				return null;
			}

			$head = [];
			if (isset($decoded['head']) && is_array($decoded['head'])) {
				foreach ($decoded['head'] as $tag) {
					if (is_string($tag) && trim($tag) !== '') {
						$head[] = $tag;
					}
				}
			}

			return new SsrResponse($head, $decoded['body']);
		} catch (Throwable $e) {
			$this->logSsrFailure(sprintf($this->_('SSR dispatch exception: %s'), $e->getMessage()));

			return null;
		}
	}

	/**
	 * @return array{status: int, body: string, abortedBySize: bool}|null
	 */
	private function executeCustomTransport(Closure $transport, string $json, string $pinnedIp): ?array
	{
		$response = $transport($this->url, $json, $this->timeoutSeconds, $pinnedIp);
		if (is_string($response)) {
			return [
				'status' => 200,
				'body' => $response,
				'abortedBySize' => strlen($response) > self::MAX_SSR_RESPONSE_BYTES,
			];
		}

		if (!is_array($response)) {
			return null;
		}

		$status = isset($response['status']) ? (int) $response['status'] : 200;
		$body = isset($response['body']) && is_string($response['body']) ? $response['body'] : '';
		$aborted = (bool) ($response['abortedBySize'] ?? false);

		return [
			'status' => $status,
			'body' => $body,
			'abortedBySize' => $aborted,
		];
	}

	/**
	 * @param array{scheme: string, resolveHost: string, port: int, pinnedIp: string, isIpLiteral: bool} $target
	 * @return array{status: int, body: string, abortedBySize: bool}|null
	 */
	private function executeCurlTransport(string $json, array $target): ?array
	{
		$ch = curl_init($this->url);
		if ($ch === false) {
			return null;
		}

		$timeoutMs = max(100, (int) round($this->timeoutSeconds * 1000));
		$connectTimeoutMs = min($timeoutMs, 2000);
		$buffer = '';
		$abortedBySize = false;

		$options = [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $json,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'Accept: application/json',
				'Content-Length: ' . strlen($json),
				'Expect:',
			],
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => 0,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_MAXREDIRS => 0,
			CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
			CURLOPT_TIMEOUT_MS => $timeoutMs,
			CURLOPT_NOSIGNAL => true,
			CURLOPT_WRITEFUNCTION => static function (mixed $handle, string $chunk) use (&$buffer, &$abortedBySize): int {
				$chunkLength = strlen($chunk);
				if (strlen($buffer) + $chunkLength > self::MAX_SSR_RESPONSE_BYTES) {
					$abortedBySize = true;

					return 0;
				}
				$buffer .= $chunk;

				return $chunkLength;
			},
		];

		if (!$target['isIpLiteral']) {
			$formattedIp = str_contains($target['pinnedIp'], ':')
				? '[' . $target['pinnedIp'] . ']'
				: $target['pinnedIp'];
			$options[CURLOPT_RESOLVE] = [
				$target['resolveHost'] . ':' . $target['port'] . ':' . $formattedIp,
			];
		}

		curl_setopt_array($ch, $options);
		$ok = curl_exec($ch);
		$errno = curl_errno($ch);
		$statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		unset($ch);

		if ($abortedBySize) {
			return [
				'status' => $statusCode > 0 ? $statusCode : 200,
				'body' => '',
				'abortedBySize' => true,
			];
		}

		if ($ok === false || $errno !== 0) {
			return null;
		}

		return [
			'status' => $statusCode,
			'body' => $buffer,
			'abortedBySize' => false,
		];
	}

	/**
	 * Validate the SSR URL and resolve a pinned IP address to prevent DNS-rebinding TOCTOU.
	 *
	 * @return array{scheme: string, resolveHost: string, port: int, pinnedIp: string, isIpLiteral: bool}|null
	 */
	private function resolveSafeTarget(string $url): ?array
	{
		if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1 || preg_match('#^https?://#i', $url) !== 1) {
			return null;
		}

		$parts = parse_url($url);
		if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
			return null;
		}

		$scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
		if ($scheme !== 'http' && $scheme !== 'https') {
			return null;
		}

		$port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
		if ($port < 1 || $port > 65535) {
			return null;
		}

		$rawHost = trim((string) $parts['host'], '[]');
		$host = rtrim(strtolower($rawHost), '.');
		if (
			$host === ''
			|| $host === '0.0.0.0'
			|| $host === 'metadata.google.internal'
			|| $host === 'metadata'
			|| $host === 'instance-data'
		) {
			return null;
		}

		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			if (!$this->isStrictLoopbackIp($host)) {
				return null;
			}

			return [
				'scheme' => $scheme,
				'resolveHost' => $host,
				'port' => $port,
				'pinnedIp' => $host === '127.0.0.1' ? '127.0.0.1' : '::1',
				'isIpLiteral' => true,
			];
		}

		// Block non-canonical integer, octal, or hex IPv4 literals (e.g. 2852039166, 0251.0376.0251.0376, 0xa9fea9fe)
		if (preg_match('/^(?:0x[0-9a-f]+|\d+)(?:\.(?:0x[0-9a-f]+|\d+))*$/i', $host) === 1) {
			return null;
		}

		if ($host === 'localhost') {
			$resolvedIps = $this->resolveHostIps('localhost');
			if ($resolvedIps === []) {
				return null;
			}
			foreach ($resolvedIps as $resolvedIp) {
				if (!$this->isStrictLoopbackIp($resolvedIp)) {
					return null;
				}
			}

			$pinned = in_array('127.0.0.1', $resolvedIps, true) ? '127.0.0.1' : '::1';

			return [
				'scheme' => $scheme,
				'resolveHost' => strtolower($rawHost),
				'port' => $port,
				'pinnedIp' => $pinned,
				'isIpLiteral' => false,
			];
		}

		$allowedHosts = $this->configuredAllowedHosts();
		if (!in_array($host, $allowedHosts, true)) {
			return null;
		}

		$resolvedIps = $this->resolveHostIps($host);
		if ($resolvedIps === []) {
			return null;
		}

		foreach ($resolvedIps as $resolvedIp) {
			if (!$this->isSafeAllowedHostIp($resolvedIp)) {
				return null;
			}
		}

		return [
			'scheme' => $scheme,
			'resolveHost' => strtolower($rawHost),
			'port' => $port,
			'pinnedIp' => $resolvedIps[0],
			'isIpLiteral' => false,
		];
	}

	/**
	 * @return list<string>
	 */
	private function configuredAllowedHosts(): array
	{
		$rawAllowed = $this->wire()?->config?->inertiaSsrAllowedHosts ?? null;
		if (!is_array($rawAllowed)) {
			return [];
		}

		$hosts = [];
		foreach ($rawAllowed as $entry) {
			if (!is_string($entry)) {
				continue;
			}
			$normalized = rtrim(strtolower(trim(trim($entry), '[]')), '.');
			if ($normalized !== '' && filter_var($normalized, FILTER_VALIDATE_IP) === false) {
				$hosts[] = $normalized;
			}
		}

		return array_values(array_unique($hosts));
	}

	/**
	 * @return list<string>
	 */
	private function resolveHostIps(string $host): array
	{
		if ($this->dnsResolver !== null) {
			$custom = ($this->dnsResolver)($host);
			if (!is_array($custom)) {
				return [];
			}

			$ips = [];
			foreach ($custom as $candidate) {
				if (is_string($candidate) && trim($candidate) !== '') {
					$ips[] = trim($candidate);
				}
			}

			return $ips;
		}

		$resolvedIps = [];
		if (function_exists('gethostbynamel')) {
			$v4List = @gethostbynamel($host);
			if (is_array($v4List)) {
				foreach ($v4List as $ip) {
					if (is_string($ip) && $ip !== '') {
						$resolvedIps[] = $ip;
					}
				}
			}
		}

		if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
			$v6Records = @dns_get_record($host, DNS_AAAA);
			if (is_array($v6Records)) {
				foreach ($v6Records as $rec) {
					if (is_array($rec) && isset($rec['ipv6']) && is_string($rec['ipv6']) && $rec['ipv6'] !== '') {
						$resolvedIps[] = $rec['ipv6'];
					}
				}
			}
		}

		return array_values(array_unique($resolvedIps));
	}

	private function isStrictLoopbackIp(string $ip): bool
	{
		if ($ip === '127.0.0.1') {
			return true;
		}

		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
			$packed = @inet_pton($ip);

			return is_string($packed) && strlen($packed) === 16 && $packed === str_repeat("\x00", 15) . "\x01";
		}

		return false;
	}

	/**
	 * Validate resolved IPs for explicitly allowlisted hostnames in `$config->inertiaSsrAllowedHosts`,
	 * blocking unspecified, link-local, cloud metadata, multicast, and NAT64/mapped metadata addresses.
	 */
	private function isSafeAllowedHostIp(string $ip): bool
	{
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
			$packed = @inet_pton($ip);
			if (!is_string($packed) || strlen($packed) !== 4) {
				return false;
			}
			$b0 = ord($packed[0]);
			$b1 = ord($packed[1]);
			// Block 0.0.0.0/8, 169.254.0.0/16, Alibaba metadata 100.100.100.200, and multicast/reserved 224.0.0.0/3
			if ($b0 === 0 || ($b0 === 169 && $b1 === 254) || $b0 >= 224 || $ip === '100.100.100.200') {
				return false;
			}

			return true;
		}

		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
			$packed = @inet_pton($ip);
			if (!is_string($packed) || strlen($packed) !== 16) {
				return false;
			}
			// Block IPv6 unspecified (::), multicast (ff00::/8), and AWS IMDSv2 IPv6 (fd00:ec2::254)
			if (
				$packed === str_repeat("\x00", 16)
				|| ord($packed[0]) === 0xFF
				|| $packed === "\xfd\x00\x0e\xc2\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x02\x54"
			) {
				return false;
			}
			// Block IPv4-mapped (::ffff:0:0/96), IPv4-compatible (::/96 except ::1), and NAT64 (64:ff9b::/96) targeting blocked IPv4 ranges
			if (
				str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")
				|| (str_starts_with($packed, str_repeat("\x00", 12)) && $packed !== str_repeat("\x00", 15) . "\x01")
				|| str_starts_with($packed, "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00")
			) {
				$mappedIpv4 = inet_ntop(substr($packed, 12, 4));
				if (!is_string($mappedIpv4) || !$this->isSafeAllowedHostIp($mappedIpv4)) {
					return false;
				}
			}
			$firstWord = ((ord($packed[0]) << 8) | ord($packed[1]));
			// Block IPv6 link-local (fe80::/10)
			if (($firstWord & 0xFFC0) === 0xFE80) {
				return false;
			}

			return true;
		}

		return false;
	}

	private function logSsrFailure(string $message): void
	{
		try {
			$this->wire()?->log?->save('inertia-ssr', $message);
		} catch (Throwable) {
			// Guard against recursive logging errors when filesystem log is unwritable
		}
	}
}
