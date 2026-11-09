<?php

declare(strict_types=1);

namespace Totoglu\Inertia;

use ProcessWire\Wire;
use ProcessWire\WirePermissionException;

/**
 * Parses inbound `X-Inertia*` HTTP headers, decodes JSON request bodies,
 * synchronizes JSON fields into `$input->post`, and validates CSRF / `X-XSRF-TOKEN` tokens.
 */
class Request extends Wire
{
	/** Maximum JSON request body size read from `php://input` (10 MB) */
	public const int MAX_JSON_BYTES = 10_485_760;

	/** Maximum JSON nesting depth for `json_decode()` */
	public const int MAX_JSON_DEPTH = 64;

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

		return in_array($this->rawMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
	}

	/**
	 * Mark the current request as blocked by demo mode and purge mutating superglobals (`$_POST`, `$_FILES`, `$input->post`).
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
	 * Return the effective HTTP request method in uppercase (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`).
	 * Supports `_method` spoofing in JSON or POST payloads, and downgrades state-mutating methods to `GET`
	 * when ProcessWire demo mode (`$config->demo = true`) is active.
	 */
	public function method(): string
	{
		if ($this->isDemoModeBlocked()) {
			return 'GET';
		}

		return $this->rawMethod();
	}

	/**
	 * Return the raw HTTP request method (including `_method` spoofing) before demo-mode downgrading.
	 */
	public function rawMethod(): string
	{
		$baseMethod = $this->methodOverride !== null
			? strtoupper($this->methodOverride)
			: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

		if ($baseMethod === 'POST') {
			$spoofed = $_POST['_method']
				?? $this->wire()->input->post->get('_method')
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
		$contentType = $this->header('Content-Type') ?? (string) ($_SERVER['CONTENT_TYPE'] ?? '');

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
		$this->jsonBody = is_array($decoded) ? $decoded : [];

		return $this->jsonBody;
	}

	/**
	 * Synchronize decoded JSON body fields into ProcessWire's `$input->post` and `$_POST`
	 * so native ProcessWire form processors and `$session->CSRF` can read them directly.
	 * Skipped on read-only requests and when ProcessWire demo mode (`$config->demo = true`) is active.
	 */
	public function syncJsonToPost(): void
	{
		if ($this->isDemoMode() || !in_array($this->rawMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
			return;
		}

		$payload = $this->json();
		if ($payload === []) {
			return;
		}

		$input = $this->wire()->input;
		foreach ($payload as $key => $value) {
			if (!is_string($key) || $key === '' || str_contains($key, "\0")) {
				continue;
			}
			if (!isset($_POST[$key])) {
				$_POST[$key] = $value;
			}
			if ($input->post->get($key) === null) {
				$input->post->set($key, $value);
			}
		}
	}

	/**
	 * Retrieve all request input data (merged GET, POST, and JSON body).
	 * When a state-mutating request is blocked by ProcessWire demo mode (`$config->demo = true`),
	 * only read-only GET parameters are returned.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array
	{
		$get = is_array($_GET ?? null) ? $_GET : [];
		$input = $this->wire()->input;
		$get = array_merge($get, $input->get->getArray());

		if ($this->isDemoModeBlocked()) {
			return $get;
		}

		$post = is_array($_POST ?? null) ? $_POST : [];
		$post = array_merge($post, $input->post->getArray());

		return array_merge($get, $post, $this->json());
	}

	/**
	 * Retrieve a single input parameter from JSON body, POST, or GET (supports dot-notation),
	 * or all input data when `$key === null`.
	 */
	public function input(?string $key = null, mixed $default = null): mixed
	{
		$all = $this->all();
		if ($key === null) {
			return $all;
		}

		if (array_key_exists($key, $all)) {
			return $all[$key];
		}

		if (!str_contains($key, '.')) {
			return $default;
		}

		$cursor = $all;
		foreach (explode('.', $key) as $segment) {
			if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
				return $default;
			}
			$cursor = $cursor[$segment];
		}

		return $cursor;
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

		$pageNum = (int) $this->wire()->input->pageNum();
		if ($pageNum >= 1) {
			return $pageNum;
		}

		return 1;
	}

	/**
	 * Validate ProcessWire's CSRF token from native POST/header or `X-XSRF-TOKEN` / `X-CSRF-TOKEN`.
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

		$this->syncJsonToPost();

		$csrf = $this->wire()->session->CSRF;

		// Check X-XSRF-TOKEN or X-CSRF-TOKEN header or POST/JSON body (never $_GET) against ProcessWire's expected token value
		$tokenName = (string) $csrf->getTokenName();
		$expectedValue = (string) $csrf->getTokenValue();
		$post = $this->wire()->input->post;

		$candidate = $this->header('X-CSRF-TOKEN')
			?? $this->header('X-XSRF-TOKEN')
			?? $post->get($tokenName)
			?? $post->get('_token')
			?? ($_POST[$tokenName] ?? null)
			?? ($_POST['_token'] ?? null);

		if (is_string($candidate) && $candidate !== '') {
			$decodedCandidate = rawurldecode($candidate);
			if ($expectedValue !== '' && (hash_equals($expectedValue, $candidate) || hash_equals($expectedValue, $decodedCandidate))) {
				return true;
			}
		}

		$valid = (bool) $csrf->hasValidToken('', false);

		if (!$valid && $throwException) {
			throw new WirePermissionException($this->_('Request security validation failed. Please refresh the page and try again.'));
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

		$serverKeys = [
			'HTTP_' . strtoupper(str_replace('-', '_', $canonical)),
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
