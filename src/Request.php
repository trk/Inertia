<?php

declare(strict_types=1);

namespace Totoglu\Inertia;

use ProcessWire\Wire;
use ProcessWire\WireInput;
use ProcessWire\WirePermissionException;

/**
 * Parses inbound `X-Inertia*` HTTP headers, decodes JSON request bodies,
 * delegates request methods and input access to ProcessWire's native `WireInput`,
 * synchronizes JSON fields into `$input->post`, and validates CSRF / `X-XSRF-TOKEN` tokens.
 */
class Request extends Wire
{
	/** Maximum JSON request body size read from `php://input` (10 MB) */
	public const int MAX_JSON_BYTES = 10_485_760;

	/** Maximum JSON nesting depth for `json_decode()` */
	public const int MAX_JSON_DEPTH = 64;

	/** Maximum length of an individual input key or CSRF token */
	private const int MAX_KEY_LENGTH = 128;

	private const int MAX_CSRF_TOKEN_LENGTH = 512;

	/**
	 * Reserved superglobal and prototype-pollution keys rejected when decoding JSON or syncing into `$input->post`.
	 *
	 * @var list<string>
	 */
	private const array BLOCKED_INPUT_KEYS = [
		'GLOBALS',
		'_GET',
		'_POST',
		'_COOKIE',
		'_REQUEST',
		'_SERVER',
		'_ENV',
		'_FILES',
		'_SESSION',
		'__proto__',
		'constructor',
		'prototype',
	];

	/** @var array<string, string> Test/runtime header overrides */
	private array $headerOverrides = [];

	/** @var array<string, mixed>|null Cached parsed JSON body */
	private ?array $jsonBody = null;

	private ?string $rawBodyOverride = null;

	private ?string $methodOverride = null;

	private bool $demoBlocked = false;

	public function isDemoMode(): bool
	{
		return (bool) $this->wire()->config->demo;
	}

	/**
	 * Determine if the raw HTTP method is a state-mutating method (`POST`, `PUT`, `PATCH`, `DELETE`).
	 */
	public function isMutatingMethod(): bool
	{
		return in_array($this->rawMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
	}

	/**
	 * Determine if a state-mutating request (`POST`, `PUT`, `PATCH`, `DELETE`) is blocked by `$config->demo`.
	 */
	public function isDemoModeBlocked(): bool
	{
		if (!$this->isDemoMode()) {
			return false;
		}

		if ($this->demoBlocked) {
			return true;
		}

		return $this->isMutatingMethod();
	}

	/**
	 * Mark the current request as blocked by demo mode and purge mutating input (`$input->post`, `$_POST`, `$_FILES`).
	 */
	public function purgeMutatingGlobals(): void
	{
		$this->demoBlocked = true;
		$this->jsonBody = [];
		$_POST = [];
		$_FILES = [];
		unset(
			$_SERVER['HTTP_X_FIELDNAME'],
			$_SERVER['HTTP_X_FILENAME'],
			$_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']
		);
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->wire()->input->post->removeAll();
	}

	/**
	 * Determine if the inbound request has `X-Inertia: true`.
	 */
	public function isInertia(): bool
	{
		return $this->header('X-Inertia') === 'true';
	}

	/**
	 * Determine if the inbound request is a prefetch visit (`Purpose: prefetch`).
	 */
	public function isPrefetch(): bool
	{
		return strtolower(trim((string) ($this->header('Purpose') ?? ''))) === 'prefetch';
	}

	/**
	 * Return the client asset version from `X-Inertia-Version`.
	 */
	public function version(): ?string
	{
		return $this->header('X-Inertia-Version');
	}

	/**
	 * Return the target component name from `X-Inertia-Partial-Component`.
	 */
	public function partialComponent(): ?string
	{
		return $this->header('X-Inertia-Partial-Component');
	}

	/**
	 * Determine if the current request is a partial reload targeting `$component`.
	 */
	public function isPartial(?string $component = null): bool
	{
		$partialComponent = $this->partialComponent();
		if ($partialComponent === null || $partialComponent === '') {
			return false;
		}

		if ($component !== null && $partialComponent !== $component) {
			return false;
		}

		return $this->partialOnly() !== [] || $this->partialExcept() !== [];
	}

	/**
	 * Return prop keys requested via `X-Inertia-Partial-Data`.
	 *
	 * @return list<string>
	 */
	public function partialOnly(): array
	{
		return $this->parseCsvHeader($this->header('X-Inertia-Partial-Data'));
	}

	/**
	 * Return prop keys excluded via `X-Inertia-Partial-Except`.
	 *
	 * @return list<string>
	 */
	public function partialExcept(): array
	{
		return $this->parseCsvHeader($this->header('X-Inertia-Partial-Except'));
	}

	/**
	 * Return merge prop keys to reset via `X-Inertia-Reset`.
	 *
	 * @return list<string>
	 */
	public function resetProps(): array
	{
		return $this->parseCsvHeader($this->header('X-Inertia-Reset'));
	}

	/**
	 * Return cached `OnceProp` keys sent via `X-Inertia-Except-Once-Props`.
	 *
	 * @return list<string>
	 */
	public function exceptOnceProps(): array
	{
		return $this->parseCsvHeader($this->header('X-Inertia-Except-Once-Props'));
	}

	/**
	 * Return the infinite scroll merge intent from `X-Inertia-Infinite-Scroll-Merge-Intent` (`'append'` or `'prepend'`).
	 */
	public function infiniteScrollMergeIntent(): string
	{
		$intent = strtolower(trim((string) ($this->header('X-Inertia-Infinite-Scroll-Merge-Intent') ?? 'append')));

		return $intent === 'prepend' ? 'prepend' : 'append';
	}

	/**
	 * Return the error bag name from `X-Inertia-Error-Bag`.
	 */
	public function errorBag(): ?string
	{
		return $this->header('X-Inertia-Error-Bag');
	}

	/**
	 * Return ProcessWire's native `WireInput` instance (`$this->wire()->input`).
	 */
	public function wireInput(): WireInput
	{
		return $this->wire()->input;
	}

	/**
	 * Return the effective HTTP request method in uppercase (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`).
	 * Managed via ProcessWire's `WireInput::requestMethod()`, supports `_method` spoofing in JSON or `$input->post`,
	 * and downgrades state-mutating methods to `GET` when ProcessWire demo mode (`$config->demo = true`) is active.
	 */
	public function method(): string
	{
		if ($this->isDemoModeBlocked()) {
			return 'GET';
		}

		return $this->rawMethod();
	}

	/**
	 * Check whether the effective HTTP request method matches `$method` (case-insensitive).
	 */
	public function isMethod(string $method): bool
	{
		$normalized = strtoupper(trim($method));
		if ($normalized === '') {
			return false;
		}

		return $this->method() === $normalized;
	}

	/**
	 * Return the raw HTTP request method via `WireInput::requestMethod()` (including `_method` spoofing) before demo-mode downgrading.
	 */
	public function rawMethod(): string
	{
		$input = $this->wireInput();
		$detectedMethod = (string) $input->requestMethod();
		$baseMethod = $this->methodOverride !== null
			? strtoupper(trim($this->methodOverride))
			: ($detectedMethod !== '' ? $detectedMethod : 'GET');

		if ($baseMethod === 'POST') {
			$spoofed = $input->post('_method', ['PUT', 'PATCH', 'DELETE', 'put', 'patch', 'delete'])
				?? ($this->decodeRawJson()['_method'] ?? null)
				?? $this->header('X-HTTP-Method-Override');
			if (is_string($spoofed)) {
				$candidate = strtoupper(trim($spoofed));
				if (in_array($candidate, ['PUT', 'PATCH', 'DELETE'], true)) {
					return $candidate;
				}
			}
		}

		return $baseMethod;
	}

	/**
	 * Determine whether the current request has a JSON content type.
	 */
	public function isJson(): bool
	{
		$contentType = $this->header('Content-Type') ?? '';

		return stripos($contentType, 'application/json') !== false
			|| stripos($contentType, '+json') !== false;
	}

	/**
	 * Parse and return the decoded JSON request body as an associative array.
	 * Returns an empty array when a state-mutating request is blocked by ProcessWire demo mode (`$config->demo = true`).
	 *
	 * @return array<string, mixed>
	 */
	public function json(): array
	{
		if ($this->isDemoModeBlocked()) {
			return [];
		}

		return $this->decodeRawJson();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodeRawJson(): array
	{
		if ($this->jsonBody !== null) {
			return $this->jsonBody;
		}

		$raw = $this->rawBodyOverride;
		if ($raw === null) {
			if (!$this->isJson()) {
				return $this->jsonBody = [];
			}
			$stream = @file_get_contents('php://input', false, null, 0, self::MAX_JSON_BYTES);
			$raw = is_string($stream) ? $stream : '';
		}

		if (trim($raw) === '' || strlen($raw) > self::MAX_JSON_BYTES) {
			return $this->jsonBody = [];
		}

		$decoded = json_decode($raw, true, self::MAX_JSON_DEPTH);
		$this->jsonBody = is_array($decoded) ? $this->sanitizeJsonTree($decoded) : [];

		return $this->jsonBody;
	}

	/**
	 * Recursively strip blocked superglobal / prototype-pollution keys and control-character keys from decoded JSON.
	 *
	 * @param array<mixed, mixed> $data
	 * @return array<string|int, mixed>
	 */
	private function sanitizeJsonTree(array $data, int $depth = 0): array
	{
		if ($depth >= self::MAX_JSON_DEPTH) {
			return [];
		}

		$clean = [];
		foreach ($data as $key => $value) {
			if (is_string($key) && !$this->isSafeInputKey($key)) {
				continue;
			}
			if (is_array($value)) {
				$clean[$key] = $this->sanitizeJsonTree($value, $depth + 1);
			} else {
				$clean[$key] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Validate that an input key is non-empty, within length limits, free of control characters,
	 * and not a reserved superglobal or prototype-pollution identifier.
	 */
	private function isSafeInputKey(string $key): bool
	{
		if ($key === '' || strlen($key) > self::MAX_KEY_LENGTH) {
			return false;
		}

		if (preg_match('/[\x00-\x20]/', $key) === 1) {
			return false;
		}

		return !in_array($key, self::BLOCKED_INPUT_KEYS, true);
	}

	/**
	 * Synchronize decoded JSON body fields and verified `X-XSRF-TOKEN` / `X-CSRF-TOKEN` headers
	 * into ProcessWire's native `$input->post` (`WireInputData`) so ProcessWire form processors
	 * and `$session->CSRF->validate()` can read them directly.
	 * Skipped on read-only requests and when ProcessWire demo mode (`$config->demo = true`) is active.
	 */
	public function syncJsonToPost(): void
	{
		if ($this->isDemoMode() || !$this->isMutatingMethod()) {
			return;
		}

		$post = $this->wireInput()->post;
		$payload = $this->json();

		foreach ($payload as $key => $value) {
			if (!is_string($key) || !$this->isSafeInputKey($key)) {
				continue;
			}
			if ($post->get($key) === null) {
				$post->set($key, $value);
			}
		}

		$this->syncCsrfToPost();
	}

	/**
	 * Bridge a verified `X-CSRF-TOKEN`, `X-XSRF-TOKEN`, or `_token` payload value into
	 * `$this->wire()->input->post` under ProcessWire's active CSRF token name so native
	 * `$session->CSRF->validate()` and `$session->CSRF->hasValidToken()` succeed on Inertia requests.
	 */
	public function syncCsrfToPost(bool $force = false): void
	{
		if ($this->isDemoMode() || (!$force && !$this->isMutatingMethod())) {
			return;
		}

		$session = $this->wire()->session;
		if ($session === null) {
			return;
		}

		$csrf = $session->CSRF;
		$tokenName = (string) $csrf->getTokenName();
		$expectedValue = (string) $csrf->getTokenValue();
		if ($tokenName === '' || $expectedValue === '' || !$this->isSafeInputKey($tokenName)) {
			return;
		}

		$post = $this->wireInput()->post;
		$candidates = [
			$post->get($tokenName),
			$this->header('X-CSRF-TOKEN'),
			$this->header('X-XSRF-TOKEN'),
			$post->get('_token'),
		];

		foreach ($candidates as $candidate) {
			if ($this->isMatchingCsrfToken($candidate, $expectedValue)) {
				if ($post->get($tokenName) !== $expectedValue) {
					$post->set($tokenName, $expectedValue);
				}

				return;
			}
		}
	}

	/**
	 * Verify a candidate CSRF token against the session's expected token value using timing-safe `hash_equals()`.
	 */
	private function isMatchingCsrfToken(mixed $candidate, string $expectedValue): bool
	{
		if (!is_string($candidate) || $candidate === '' || $expectedValue === '') {
			return false;
		}

		if (strlen($candidate) > self::MAX_CSRF_TOKEN_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
			return false;
		}

		$decodedCandidate = rawurldecode($candidate);

		return hash_equals($expectedValue, $candidate) || hash_equals($expectedValue, $decodedCandidate);
	}

	/**
	 * Retrieve a GET parameter (or all GET parameters as `WireInputData`) via ProcessWire's native `WireInput::get()`.
	 *
	 * @param array<int, string|int>|string|int|callable|null $valid Sanitizer name(s), allowed values whitelist, integer toggle, or validation callback
	 */
	public function get(string $key = '', array|string|int|callable|null $valid = null, mixed $fallback = null): mixed
	{
		return $this->wireInput()->get($key, $valid, $fallback);
	}

	/**
	 * Retrieve a POST / JSON parameter (or all POST parameters as `WireInputData`) via ProcessWire's native `WireInput::post()`.
	 * Synchronizes JSON body fields into `$input->post` before reading, and blocks access when demo mode is active.
	 *
	 * @param array<int, string|int>|string|int|callable|null $valid Sanitizer name(s), allowed values whitelist, integer toggle, or validation callback
	 */
	public function post(string $key = '', array|string|int|callable|null $valid = null, mixed $fallback = null): mixed
	{
		$input = $this->wireInput();
		if ($this->isDemoModeBlocked()) {
			$input->post->removeAll();

			return $key === '' ? $input->post : $fallback;
		}

		$this->syncJsonToPost();

		return $input->post($key, $valid, $fallback);
	}

	/**
	 * Retrieve all request input data (merged `$input->get`, `$input->post`, and JSON body).
	 * When a state-mutating request is blocked by ProcessWire demo mode (`$config->demo = true`),
	 * only read-only GET parameters are returned.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array
	{
		$input = $this->wireInput();
		$get = $input->get->getArray();

		if ($this->isDemoModeBlocked()) {
			return $get;
		}

		$post = $input->post->getArray();

		return array_merge($get, $post, $this->json());
	}

	/**
	 * Retrieve a single input parameter from JSON body, `$input->post`, or `$input->get` (supports dot-notation),
	 * or all input data when `$key === null`. Optionally sanitize/validate via ProcessWire's `$sanitizer`, whitelist, or integer toggle.
	 *
	 * @param array<int, string|int>|string|int|callable|null $sanitizer Optional ProcessWire Sanitizer method name (e.g. `'text'`, `'int'`, `'email'`), whitelist array, integer toggle, or callback
	 */
	public function input(?string $key = null, mixed $default = null, array|string|int|callable|null $sanitizer = null): mixed
	{
		$all = $this->all();
		if ($key === null) {
			return $all;
		}

		$found = false;
		$value = null;

		if (array_key_exists($key, $all)) {
			$found = true;
			$value = $all[$key];
		} elseif (str_contains($key, '.')) {
			$cursor = $all;
			$found = true;
			foreach (explode('.', $key) as $segment) {
				if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
					$found = false;
					break;
				}
				$cursor = $cursor[$segment];
			}
			if ($found) {
				$value = $cursor;
			}
		}

		if (!$found || $value === null) {
			return $default;
		}

		if ($sanitizer === null) {
			return $value;
		}

		return $this->applyInputSanitizer($value, $sanitizer, $default);
	}

	/**
	 * Apply a ProcessWire `Sanitizer` method (or CSV chain), whitelist array, integer toggle, or callable to a resolved input value.
	 *
	 * @param array<int, string|int>|string|int|callable $sanitizer
	 */
	private function applyInputSanitizer(mixed $value, array|string|int|callable $sanitizer, mixed $default = null): mixed
	{
		if (is_callable($sanitizer)) {
			$result = $sanitizer($value);

			return $result ?? $default;
		}

		if (is_int($sanitizer)) {
			if (is_scalar($value) && ctype_digit((string) $value) && (int) $value === $sanitizer) {
				return $sanitizer;
			}

			return $default;
		}

		if (is_array($sanitizer)) {
			while (is_array($value)) {
				$value = $value !== [] ? reset($value) : null;
			}
			if (is_scalar($value)) {
				foreach ($sanitizer as $allowed) {
					if ((string) $allowed === (string) $value) {
						return $allowed;
					}
				}
			}

			return $default;
		}

		$pwSanitizer = $this->wire()->sanitizer;
		$allSanitizers = $pwSanitizer->getAll(true);
		$current = $value;

		foreach (explode(',', $sanitizer) as $method) {
			$methodName = trim($method);
			if ($methodName === '') {
				continue;
			}
			$returnType = is_array($allSanitizers) ? (string) ($allSanitizers[$methodName] ?? '') : '';
			if (!str_contains($returnType, 'a')) {
				while (is_array($current)) {
					$current = $current !== [] ? reset($current) : '';
				}
			}
			$current = $pwSanitizer->sanitize($current, $methodName);
		}

		return $current ?? $default;
	}

	/**
	 * Resolve the current pagination page number (>= 1) from `$pageName` input or ProcessWire's `$input->pageNum()`.
	 */
	public function pageNumber(string $pageName = 'page'): int
	{
		$val = $this->input($pageName);
		if (is_numeric($val) && (int) $val >= 1) {
			return (int) $val;
		}

		$pageNum = (int) $this->wireInput()->pageNum();
		if ($pageNum >= 1) {
			return $pageNum;
		}

		return 1;
	}

	/**
	 * Validate ProcessWire's CSRF token from native `$input->post` or `X-XSRF-TOKEN` / `X-CSRF-TOKEN` headers.
	 *
	 * @throws WirePermissionException
	 */
	public function validateCsrf(bool $throwException = false): bool
	{
		if ($this->isDemoModeBlocked()) {
			if ($throwException) {
				throw new WirePermissionException($this->_('Write operations are disabled in demo mode.'));
			}

			return false;
		}

		if (!$this->wire()->config->protectCSRF) {
			return true;
		}

		$this->syncJsonToPost();
		$this->syncCsrfToPost(true);

		$session = $this->wire()->session;
		if ($session === null) {
			return false;
		}

		$csrf = $session->CSRF;
		$valid = (bool) $csrf->hasValidToken('', false);

		if (!$valid) {
			$csrf->resetToken();
			if ($throwException) {
				throw new WirePermissionException($this->_('Request security validation failed. Please refresh the page and try again.'));
			}
		}

		return $valid;
	}

	/**
	 * Read an inbound HTTP header by canonical name (e.g. `X-Inertia`) or CGI name (`HTTP_X_INERTIA`).
	 */
	public function header(string $name): ?string
	{
		$canonical = $name;
		if (str_starts_with(strtoupper($name), 'HTTP_')) {
			$canonical = str_replace('_', '-', substr($name, 5));
		}

		foreach ($this->headerOverrides as $k => $v) {
			if (strcasecmp($k, $canonical) === 0 || strcasecmp($k, $name) === 0) {
				return $v !== '' ? $v : null;
			}
		}

		if (function_exists('getallheaders')) {
			$headers = @getallheaders();
			if (is_array($headers)) {
				foreach ($headers as $k => $v) {
					if (is_string($k) && (strcasecmp($k, $canonical) === 0 || strcasecmp($k, $name) === 0) && is_scalar($v)) {
						$str = trim((string) $v);

						return $str !== '' ? $str : null;
					}
				}
			}
		}

		$upperSnake = strtoupper(str_replace('-', '_', $canonical));
		$serverKeys = [
			'HTTP_' . $upperSnake,
			$upperSnake,
			$name,
		];
		foreach ($serverKeys as $serverKey) {
			if (isset($_SERVER[$serverKey]) && is_scalar($_SERVER[$serverKey])) {
				$str = trim((string) $_SERVER[$serverKey]);

				return $str !== '' ? $str : null;
			}
		}

		return null;
	}

	/**
	 * Return all inbound `X-Inertia*` headers keyed in lowercase without the `x-inertia-` prefix.
	 *
	 * @return array<string, string>
	 */
	public function headers(): array
	{
		$result = [];

		$keys = [
			'X-Inertia' => 'inertia',
			'X-Inertia-Version' => 'version',
			'X-Inertia-Partial-Component' => 'partial-component',
			'X-Inertia-Partial-Data' => 'partial-data',
			'X-Inertia-Partial-Except' => 'partial-except',
			'X-Inertia-Reset' => 'reset',
			'X-Inertia-Except-Once-Props' => 'except-once-props',
			'X-Inertia-Infinite-Scroll-Merge-Intent' => 'infinite-scroll-merge-intent',
			'X-Inertia-Error-Bag' => 'error-bag',
			'Purpose' => 'purpose',
		];

		foreach ($keys as $headerName => $shortKey) {
			$val = $this->header($headerName);
			if ($val !== null) {
				$result[$shortKey] = $val;
			}
		}

		return $result;
	}

	/**
	 * Set simulated headers, method, or raw JSON body for isolated testing.
	 *
	 * @param array<string, string> $headers
	 */
	public function fake(array $headers = [], ?string $method = null, ?string $rawJsonBody = null): self
	{
		$this->headerOverrides = $headers;
		$this->methodOverride = $method;
		$this->rawBodyOverride = $rawJsonBody;
		$this->jsonBody = null;
		$this->demoBlocked = false;

		return $this;
	}

	/**
	 * Reset any simulated test state.
	 */
	public function resetFake(): self
	{
		$this->headerOverrides = [];
		$this->methodOverride = null;
		$this->rawBodyOverride = null;
		$this->jsonBody = null;
		$this->demoBlocked = false;

		return $this;
	}

	/**
	 * @return list<string>
	 */
	private function parseCsvHeader(?string $raw): array
	{
		if ($raw === null || trim($raw) === '') {
			return [];
		}

		$parts = explode(',', $raw);
		$cleaned = [];
		foreach ($parts as $part) {
			$trimmed = trim($part);
			if ($trimmed !== '') {
				$cleaned[] = $trimmed;
			}
		}

		return array_values(array_unique($cleaned));
	}
}
