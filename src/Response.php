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

	public function setHeader(string $name, string $value, bool $emitNow = true): self
	{
		$cleanName = trim(str_replace(["\r", "\n", "\0", ':'], '', $name));
		if ($cleanName === '') {
			return $this;
		}

		$clean = $this->safeHeaderValue($value);
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
	 * Configure headers for an Inertia JSON response (`X-Inertia: true`, `Vary: X-Inertia`, `Content-Type: application/json`),
	 * preserving any existing 4xx/5xx status code.
	 */
	public function markAsInertiaJson(bool $emitNow = true): self
	{
		$this->setHeader('X-Inertia', 'true', $emitNow);
		$this->setHeader('Vary', 'X-Inertia', $emitNow);
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
		$this->setHeader('X-Inertia-Location', $url, $emitNow);

		return $this;
	}

	/**
	 * Configure a `409 Conflict` + `X-Inertia-Redirect` response (internal SPA redirect).
	 */
	public function conflictRedirect(string $url, bool $emitNow = true): self
	{
		$this->setStatusCode(409, $emitNow);
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
