<?php

declare(strict_types=1);

namespace Totoglu\Inertia;

use Stringable;

/**
 * Buffers and emits HTTP status codes and `X-Inertia*` response headers.
 */
final class Response implements Stringable
{
	/** @var array<string, string> Buffered outgoing HTTP headers */
	private array $headers = [];

	/** @var array<string, string> Headers already emitted to the SAPI */
	private array $emittedHeaders = [];

	private ?int $statusCode = null;

	private ?int $emittedStatusCode = null;

	private function safeHeaderValue(string $value): string
	{
		return str_replace(["\r", "\n", "\0"], '', $value);
	}

	/**
	 * Enforce an explicit navigation URL allowlist before emitting `Location`,
	 * `X-Inertia-Location`, or `X-Inertia-Redirect` headers.
	 *
	 * Allows relative paths/queries/fragments starting with `/`, `?`, `#`, relative paths
	 * without `:`, `//`, `\`, or `http://` / `https://` URLs with non-empty host and empty user/pass.
	 */
	public function sanitizeNavigationUrl(string $url): string
	{
		$clean = $this->safeHeaderValue(trim($url));
		if ($clean === '' || preg_match('/[\x00-\x1F\x7F]/', $clean) === 1) {
			return '/';
		}

		if ($clean[0] === '?' || $clean[0] === '#') {
			return $clean;
		}

		$beforeQueryOrHash = (string) strtok($clean, '?#');
		if (str_contains($beforeQueryOrHash, '\\')) {
			return '/';
		}

		if ($clean[0] === '/') {
			if (str_starts_with($clean, '//')) {
				return '/';
			}

			return $clean;
		}

		if (preg_match('#^https?://#i', $clean) === 1) {
			$parts = parse_url($clean);
			if (
				!is_array($parts)
				|| !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
				|| empty($parts['host'])
				|| isset($parts['user'])
				|| isset($parts['pass'])
			) {
				return '/';
			}

			return $clean;
		}

		if (
			$beforeQueryOrHash === ''
			|| str_contains($beforeQueryOrHash, ':')
			|| str_contains($beforeQueryOrHash, '//')
		) {
			return '/';
		}

		return $clean;
	}

	public function setHeader(string $name, string $value, bool $emitNow = true): self
	{
		$cleanName = (string) preg_replace('/[^!#$%&\'*+.^_`|~0-9A-Za-z-]/', '', trim($name));
		if ($cleanName === '') {
			return $this;
		}

		$isNavHeader = in_array(strtolower($cleanName), ['location', 'x-inertia-location', 'x-inertia-redirect'], true);
		$clean = $isNavHeader ? $this->sanitizeNavigationUrl($value) : $this->safeHeaderValue($value);
		foreach (array_keys($this->headers) as $existingKey) {
			if ($existingKey !== $cleanName && strcasecmp($existingKey, $cleanName) === 0) {
				unset($this->headers[$existingKey], $this->emittedHeaders[$existingKey]);
			}
		}
		$this->headers[$cleanName] = $clean;

		if ($emitNow && !headers_sent()) {
			header("{$cleanName}: {$clean}");
			$this->emittedHeaders[$cleanName] = $clean;
		}

		return $this;
	}

	public function setStatusCode(int $code, bool $emitNow = true): self
	{
		$this->statusCode = $code;

		if ($emitNow && !headers_sent()) {
			http_response_code($code);
			$this->emittedStatusCode = $code;
		}

		return $this;
	}

	public function getHeader(string $name): ?string
	{
		foreach ($this->headers as $k => $v) {
			if (strcasecmp($k, $name) === 0) {
				return $v;
			}
		}

		return null;
	}

	/**
	 * @return array<string, string>
	 */
	public function getHeaders(): array
	{
		return $this->headers;
	}

	public function getStatusCode(): ?int
	{
		return $this->statusCode;
	}

	/**
	 * Append one or more comma-separated header names to the outgoing `Vary` header without overwriting existing tokens.
	 */
	public function vary(string $header = 'X-Inertia', bool $emitNow = true): self
	{
		$existing = $this->getHeader('Vary');
		if ($existing !== null && trim($existing) === '*') {
			return $this;
		}

		$sources = [];
		if ($existing !== null && trim($existing) !== '') {
			$sources[] = $existing;
		}
		if (PHP_SAPI !== 'cli' && function_exists('headers_list')) {
			foreach (headers_list() as $rawHeaderLine) {
				if (stripos($rawHeaderLine, 'Vary:') === 0) {
					$sapiVary = trim(substr($rawHeaderLine, 5));
					if ($sapiVary === '*') {
						return $this->setHeader('Vary', '*', $emitNow);
					}
					if ($sapiVary !== '') {
						$sources[] = $sapiVary;
					}
				}
			}
		}
		$sources[] = $header;

		$tokens = [];
		$seen = [];
		foreach ($sources as $src) {
			foreach (explode(',', $src) as $rawToken) {
				$cleanToken = (string) preg_replace('/[^!#$%&\'*+.^_`|~0-9A-Za-z-]/', '', trim($rawToken));
				if ($cleanToken === '*') {
					return $this->setHeader('Vary', '*', $emitNow);
				}
				if ($cleanToken !== '') {
					$lower = strtolower($cleanToken);
					if (!isset($seen[$lower])) {
						$seen[$lower] = true;
						$tokens[] = $cleanToken;
					}
				}
			}
		}

		if ($tokens !== []) {
			$this->setHeader('Vary', implode(', ', $tokens), $emitNow);
		}

		return $this;
	}

	/**
	 * Configure headers for an Inertia JSON response (`X-Inertia: true`, `Vary: X-Inertia`, `Content-Type: application/json`),
	 * preserving any existing 4xx/5xx status code.
	 */
	public function markAsInertiaJson(bool $emitNow = true): self
	{
		$this->setHeader('X-Inertia', 'true', $emitNow);
		$this->vary('X-Inertia', $emitNow);
		$this->setHeader('Content-Type', 'application/json; charset=utf-8', $emitNow);

		if ($this->statusCode === null) {
			$currentHttpCode = http_response_code();
			$effectiveCode = is_int($currentHttpCode) && $currentHttpCode >= 400 ? $currentHttpCode : 200;
			$this->setStatusCode($effectiveCode, $emitNow);
		}

		return $this;
	}

	/**
	 * Configure a `409 Conflict` + `X-Inertia-Location` response (asset version mismatch or external full-page redirect).
	 */
	public function conflictLocation(string $url, bool $emitNow = true): self
	{
		$this->setStatusCode(409, $emitNow);
		$this->vary('X-Inertia', $emitNow);
		$this->setHeader('Cache-Control', 'no-store, private', $emitNow);
		$this->setHeader('X-Inertia-Location', $url, $emitNow);

		return $this;
	}

	/**
	 * Configure a `409 Conflict` + `X-Inertia-Redirect` response (internal SPA redirect).
	 */
	public function conflictRedirect(string $url, bool $emitNow = true): self
	{
		$this->setStatusCode(409, $emitNow);
		$this->vary('X-Inertia', $emitNow);
		$this->setHeader('Cache-Control', 'no-store, private', $emitNow);
		$this->setHeader('X-Inertia-Redirect', $url, $emitNow);

		return $this;
	}

	public function applyHeaders(): void
	{
		if (headers_sent()) {
			return;
		}

		if ($this->statusCode !== null && $this->emittedStatusCode !== $this->statusCode) {
			http_response_code($this->statusCode);
			$this->emittedStatusCode = $this->statusCode;
		}

		foreach ($this->headers as $name => $value) {
			if (($this->emittedHeaders[$name] ?? null) !== $value) {
				header("{$name}: {$value}");
				$this->emittedHeaders[$name] = $value;
			}
		}
	}

	public function reset(): self
	{
		$this->headers = [];
		$this->emittedHeaders = [];
		$this->statusCode = null;
		$this->emittedStatusCode = null;

		return $this;
	}

	public function __toString(): string
	{
		$this->applyHeaders();

		return '';
	}
}
