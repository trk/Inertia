<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Props;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Fluent once-prop configuration (`once()`, `as()`, `until()`, `fresh()`) and TTL calculation.
 */
trait ConfiguresOnceStrategy
{
	protected bool $onceEnabled = false;

	protected ?string $customKey = null;

	protected bool $forceFresh = false;

	protected ?int $expiresAtMs = null;

	protected ?int $ttlSeconds = null;

	/**
	 * Mark this prop to be evaluated once and remembered by the client across subsequent navigations.
	 */
	public function once(
		bool $once = true,
		?string $as = null,
		DateTimeInterface|DateInterval|int|null $until = null
	): static {
		$this->onceEnabled = $once;

		if ($as !== null) {
			$this->as($as);
		}

		if ($until !== null) {
			$this->until($until);
		}

		return $this;
	}

	public function isOnce(): bool
	{
		return $this->onceEnabled;
	}

	/**
	 * Assign a custom key so multiple pages can share the same remembered once-prop under different prop names.
	 */
	public function as(string $key): static
	{
		$this->customKey = trim($key);

		return $this;
	}

	public function key(): ?string
	{
		return $this->customKey !== null && $this->customKey !== '' ? $this->customKey : null;
	}

	/**
	 * Force the once-prop to be re-evaluated and sent even if the client already holds a cached copy.
	 */
	public function fresh(bool $condition = true): static
	{
		$this->forceFresh = $condition;

		return $this;
	}

	public function isFresh(): bool
	{
		return $this->forceFresh;
	}

	/**
	 * Configure an expiration timestamp for the cached once-prop on the client and server.
	 * Accepts a `DateTimeInterface`, a `DateInterval`, or a TTL in seconds (`int`).
	 */
	public function until(DateTimeInterface|DateInterval|int $expiration): static
	{
		$now = time();

		if ($expiration instanceof DateInterval) {
			$expiration = (new DateTimeImmutable())->add($expiration);
		}

		if ($expiration instanceof DateTimeInterface) {
			$targetTs = $expiration->getTimestamp();
			$this->ttlSeconds = max(0, $targetTs - $now);
			$this->expiresAtMs = $targetTs * 1000;
		} else {
			$this->ttlSeconds = max(0, $expiration);
			$this->expiresAtMs = ($now + $this->ttlSeconds) * 1000;
		}

		return $this;
	}

	/**
	 * Return the configured TTL in seconds, or `$fallbackTtlSeconds` when `->until(...)` was omitted.
	 */
	public function ttlSeconds(?int $fallbackTtlSeconds = null): ?int
	{
		if ($this->ttlSeconds !== null) {
			return $this->ttlSeconds;
		}

		return $fallbackTtlSeconds !== null && $fallbackTtlSeconds > 0 ? $fallbackTtlSeconds : null;
	}

	/**
	 * Return the expiration timestamp in milliseconds, falling back to `$fallbackTtlSeconds` when `->until(...)` was omitted.
	 */
	public function expiresAt(?int $fallbackTtlSeconds = null): ?int
	{
		if ($this->expiresAtMs !== null) {
			return $this->expiresAtMs;
		}

		$ttl = $this->ttlSeconds($fallbackTtlSeconds);

		return $ttl !== null && $ttl > 0 ? (time() + $ttl) * 1000 : null;
	}
}
