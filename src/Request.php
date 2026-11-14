<?php

declare(strict_types=1);

namespace Totoglu\Inertia;

use ProcessWire\Wire;
use ProcessWire\WireInput;
use ProcessWire\WirePermissionException;
use Throwable;

/**
 * Parses inbound `X-Inertia*` HTTP headers, decodes JSON request bodies,
 * delegates request methods and input access to ProcessWire's native `WireInput`,
 * synchronizes JSON fields into `$input->post`, and validates CSRF / `X-XSRF-TOKEN` tokens.
 */
class Request extends Wire
{
	/** Maximum JSON request body size read from `php://input` (10 MB) */
	public const int MAX_JSON_BYTES = 10_485_760;

	/** Maximum URL-encoded form body size read from `php://input` on non-POST mutating verbs (1 MB) */
	public const int MAX_FORM_BYTES = 1_048_576;

	/** Maximum number of URL-encoded form parameters parsed from `php://input` on non-POST mutating verbs */
	private const int MAX_FORM_VARS = 1000;

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

	/** @var array<string, mixed>|null Cached parsed URL-encoded form body for non-POST mutating verbs */
	private ?array $formBody = null;

	private ?string $rawBodyOverride = null;

	private ?string $methodOverride = null;

	private ?string $preDemoMethod = null;

	private bool $demoBlocked = false;

	public function isDemoMode(): bool
	{
		return (bool) ($this->wire()?->config?->demo ?? false);
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
		$currentRaw = $this->rawMethod();
		if ($currentRaw !== 'GET') {
			$this->preDemoMethod = $currentRaw;
			if (empty($_SERVER['PW_DEMO_ORIGINAL_METHOD'])) {
				$_SERVER['PW_DEMO_ORIGINAL_METHOD'] = $currentRaw;
			}
		}
		$this->demoBlocked = true;
		$this->jsonBody = [];
		$this->formBody = [];
		$_POST = [];
		$_FILES = [];
		$_REQUEST = $_GET;
		unset(
			$_SERVER['HTTP_X_FIELDNAME'],
			$_SERVER['HTTP_X_FILENAME'],
			$_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']
		);
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->wireInput()?->post->removeAll();
	}

	/**
	 * Determine if the inbound request has `X-Inertia: true`.
	 */
	public function isInertia(): bool
	{
		return $this->header('X-Inertia') === 'true';
	}

	/**
	 * Determine if the inbound request is a prefetch visit (`Purpose: prefetch` or `Sec-Purpose: prefetch`).
	 */
	public function isPrefetch(): bool
	{
		if (strtolower(trim((string) ($this->header('Purpose') ?? ''))) === 'prefetch') {
			return true;
		}

		foreach ($this->parseCsvHeader($this->header('Sec-Purpose')) as $directive) {
			$token = strtolower(trim((string) strtok($directive, ';')));
			if ($token === 'prefetch') {
				return true;
			}
		}

		return false;
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
		if (!$this->isInertia()) {
			return false;
		}

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
	 * Return ProcessWire's native `WireInput` instance (`$this->wire()->input`) when available.
	 */
	public function wireInput(): ?WireInput
	{
		try {
			$input = $this->wire()?->input;

			return $input instanceof WireInput ? $input : null;
		} catch (Throwable $e) {
			$this->logRequestError('wireInput', $e);

			return null;
		}
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
	 * Determine whether the effective HTTP request method is `POST`.
	 */
	public function isPost(): bool
	{
		return $this->isMethod('POST');
	}

	/**
	 * Return the sanitized current request URL (including query string) via `WireInput::url(true)`.
	 */
	public function uri(): string
	{
		$input = $this->wireInput();
		if ($input !== null && method_exists($input, 'url')) {
			try {
				return (string) $input->url(true);
			} catch (Throwable $e) {
				$this->logRequestError('uri', $e);

				return '';
			}
		}

		return '';
	}

	/**
	 * Return the raw HTTP request method via `WireInput::requestMethod()` (with `$_SERVER['REQUEST_METHOD']` fallback for `PATCH`
	 * and `_method` spoofing on `POST`) before demo-mode downgrading.
	 */
	public function rawMethod(): string
	{
		if (
			$this->methodOverride === null
			&& $this->isDemoMode()
			&& strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) === 'GET'
		) {
			if ($this->demoBlocked && $this->preDemoMethod !== null) {
				return $this->preDemoMethod;
			}

			$preserved = $_SERVER['PW_DEMO_ORIGINAL_METHOD'] ?? null;
			if (is_string($preserved)) {
				$normalizedPreserved = strtoupper(trim($preserved));
				if (in_array($normalizedPreserved, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
					$this->preDemoMethod = $normalizedPreserved;
					$this->demoBlocked = true;

					return $normalizedPreserved;
				}
			}
		}

		$input = $this->wireInput();
		$baseMethod = $this->resolveUnspoofedVerb();

		if ($baseMethod === 'POST') {
			$postSpoof = $input !== null ? $input->post('_method') : null;
			$jsonSpoof = ($this->isJson() || ($this->rawBodyOverride !== null && !$this->isUrlEncodedForm()))
				? ($this->decodeRawJson()['_method'] ?? null)
				: null;
			$headerSpoof = $this->header('X-HTTP-Method-Override');
			$spoofed = (is_string($postSpoof) && trim($postSpoof) !== '')
				? $postSpoof
				: ((is_string($jsonSpoof) && trim($jsonSpoof) !== '')
					? $jsonSpoof
					: ((is_string($headerSpoof) && trim($headerSpoof) !== '') ? $headerSpoof : null));
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
	 * Resolve the unspoofed HTTP verb (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`, `OPTIONS`)
	 * without reading or decoding the request body.
	 */
	private function resolveUnspoofedVerb(): string
	{
		if ($this->methodOverride !== null) {
			$override = strtoupper(trim($this->methodOverride));

			return $override !== '' ? $override : 'GET';
		}

		if ($this->isDemoMode() && strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) === 'GET') {
			if ($this->preDemoMethod !== null) {
				return $this->preDemoMethod;
			}
			$preserved = $_SERVER['PW_DEMO_ORIGINAL_METHOD'] ?? null;
			if (is_string($preserved)) {
				$normalized = strtoupper(trim($preserved));
				if (in_array($normalized, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
					return $normalized;
				}
			}
		}

		$input = $this->wireInput();
		if ($input !== null && method_exists($input, 'requestMethod')) {
			try {
				$detectedMethod = strtoupper(trim((string) $input->requestMethod()));
				if ($detectedMethod !== '') {
					return $detectedMethod;
				}
			} catch (Throwable $e) {
				$this->logRequestError('requestMethod', $e);
			}
		}

		$serverMethod = strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')));

		return in_array($serverMethod, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)
			? $serverMethod
			: 'GET';
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
	 * Determine whether the current request has an `application/x-www-form-urlencoded` content type.
	 */
	public function isUrlEncodedForm(): bool
	{
		$contentType = $this->header('Content-Type') ?? '';

		return stripos($contentType, 'application/x-www-form-urlencoded') !== false;
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
	 * Decode bounded `application/x-www-form-urlencoded` request bodies sent via `php://input`
	 * on non-`POST` mutating verbs (`PUT`, `PATCH`, `DELETE`) where PHP SAPI leaves `$_POST` empty.
	 *
	 * @return array<string, mixed>
	 */
	private function decodeRawFormBody(): array
	{
		if ($this->formBody !== null) {
			return $this->formBody;
		}

		if (!$this->isUrlEncodedForm()) {
			return $this->formBody = [];
		}

		$verb = $this->resolveUnspoofedVerb();
		if (!in_array($verb, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
			return $this->formBody = [];
		}

		$raw = $this->rawBodyOverride;
		if ($raw === null) {
			if (!in_array($verb, ['PUT', 'PATCH', 'DELETE'], true)) {
				return $this->formBody = [];
			}
			$stream = @file_get_contents('php://input', false, null, 0, self::MAX_FORM_BYTES + 1);
			$raw = is_string($stream) ? $stream : '';
		}

		if (
			trim($raw) === ''
			|| strlen($raw) > self::MAX_FORM_BYTES
			|| substr_count($raw, '&') >= self::MAX_FORM_VARS
			|| (substr_count($raw, '[') + substr_count(strtolower($raw), '%5b')) > 256
		) {
			return $this->formBody = [];
		}

		$parsed = [];
		parse_str($raw, $parsed);
		$this->formBody = is_array($parsed) ? $this->sanitizeJsonTree($parsed) : [];

		return $this->formBody;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodeRawJson(): array
	{
		if ($this->jsonBody !== null) {
			return $this->jsonBody;
		}

		if (!in_array($this->resolveUnspoofedVerb(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
			return $this->jsonBody = [];
		}

		$raw = $this->rawBodyOverride;
		if ($raw === null) {
			if (!$this->isJson()) {
				return $this->jsonBody = [];
			}
			$stream = @file_get_contents('php://input', false, null, 0, self::MAX_JSON_BYTES + 1);
			$raw = is_string($stream) ? $stream : '';
		} elseif (!$this->isJson() && $this->isUrlEncodedForm()) {
			return $this->jsonBody = [];
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

		if (preg_match('/[\x00-\x20\x7F]/', $key) === 1) {
			return false;
		}

		return !in_array($key, self::BLOCKED_INPUT_KEYS, true);
	}

	/**
	 * Synchronize decoded JSON body fields, non-`POST` URL-encoded form bodies (`PUT`, `PATCH`, `DELETE`),
	 * and verified `X-XSRF-TOKEN` / `X-CSRF-TOKEN` headers into ProcessWire's native `$input->post`
	 * (`WireInputData`) so ProcessWire form processors and `$session->CSRF->validate()` can read them directly.
	 * Skipped on read-only requests and when ProcessWire demo mode (`$config->demo = true`) is active.
	 */
	public function syncJsonToPost(): void
	{
		if ($this->isDemoMode() || !$this->isMutatingMethod()) {
			return;
		}

		$input = $this->wireInput();
		if ($input === null) {
			return;
		}

		$post = $input->post;
		if ($this->isUrlEncodedForm()) {
			$payload = $this->decodeRawFormBody();
		} elseif ($this->isJson() || $this->rawBodyOverride !== null) {
			$payload = $this->json();
		} else {
			$payload = [];
		}

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
	 * Bridge a verified `X-CSRF-TOKEN`, `X-XSRF-TOKEN`, `X-{tokenName}`, or `_token` payload value into
	 * `$this->wire()->input->post` under ProcessWire's active CSRF token name so native
	 * `$session->CSRF->validate()` and `$session->CSRF->hasValidToken()` succeed on Inertia requests.
	 */
	public function syncCsrfToPost(bool $force = false): void
	{
		if ($this->isDemoMode() || (!$force && !$this->isMutatingMethod())) {
			return;
		}

		if ($force && !$this->isMutatingMethod()) {
			return;
		}

		$session = $this->wire()?->session;
		if ($session === null) {
			return;
		}

		$csrf = $session->CSRF;
		$tokenName = (string) $csrf->getTokenName();
		$expectedValue = (string) $csrf->getTokenValue();
		if ($tokenName === '' || $expectedValue === '' || !$this->isSafeInputKey($tokenName)) {
			return;
		}

		$input = $this->wireInput();
		if ($input === null) {
			return;
		}

		$post = $input->post;
		$jsonPayload = ($this->isJson() || ($this->rawBodyOverride !== null && !$this->isUrlEncodedForm())) ? $this->decodeRawJson() : [];
		$formPayload = (!$this->isJson() && $this->isUrlEncodedForm()) ? $this->decodeRawFormBody() : [];
		$candidates = [
			$post->get($tokenName),
			$this->header('X-CSRF-TOKEN'),
			$this->header('X-XSRF-TOKEN'),
			$this->header('X-' . $tokenName),
			$post->get('_token'),
			$formPayload[$tokenName] ?? null,
			$formPayload['_token'] ?? null,
			$jsonPayload[$tokenName] ?? null,
			$jsonPayload['_token'] ?? null,
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
	 * Determine whether automatic JSON-to-`$input->post` synchronization is enabled on the `Inertia` module.
	 */
	private function shouldSyncJsonToPost(): bool
	{
		try {
			$modules = $this->wire()?->modules;
			if ($modules !== null && $modules->isInstalled('Inertia')) {
				$inertia = $modules->get('Inertia');
				if (is_object($inertia) && method_exists($inertia, 'get')) {
					return (bool) ($inertia->get('syncJsonToPost') ?? true);
				}
			}
		} catch (Throwable $e) {
			$this->logRequestError('shouldSyncJsonToPost', $e);
		}

		return true;
	}

	/**
	 * Verify a candidate CSRF token against the session's expected token value using timing-safe `hash_equals()`.
	 */
	private function isMatchingCsrfToken(mixed $candidate, string $expectedValue): bool
	{
		if (!is_string($candidate) || $expectedValue === '') {
			return false;
		}

		$candidate = trim($candidate);
		if ($candidate === '' || strlen($candidate) > self::MAX_CSRF_TOKEN_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
			return false;
		}

		$decodedCandidate = rawurldecode($candidate);
		if (preg_match('/[\x00-\x1F\x7F]/', $decodedCandidate) === 1) {
			return false;
		}

		return hash_equals($expectedValue, $candidate) || hash_equals($expectedValue, $decodedCandidate);
	}

	/**
	 * Retrieve a GET parameter (or all GET parameters as `WireInputData`) via ProcessWire's native `WireInput::get()`.
	 *
	 * @param array<int, string|int>|string|int|callable|null $valid Sanitizer name(s), allowed values whitelist, integer toggle, or validation callback
	 */
	public function get(?string $key = '', array|string|int|callable|null $valid = null, mixed $fallback = null): mixed
	{
		$normalizedKey = $key ?? '';
		if ($normalizedKey !== '') {
			$lookupKey = str_ends_with($normalizedKey, '[]') ? substr($normalizedKey, 0, -2) : $normalizedKey;
			if (!$this->isSafeInputKey($lookupKey)) {
				return $fallback;
			}
		}

		$input = $this->wireInput();
		if ($input === null) {
			return $normalizedKey === '' ? [] : $fallback;
		}

		try {
			return $input->get($normalizedKey, $valid, $fallback);
		} catch (Throwable $e) {
			$this->logRequestError('get', $e);

			return $fallback;
		}
	}

	/**
	 * Retrieve a POST / JSON parameter (or all POST parameters as `WireInputData`) via ProcessWire's native `WireInput::post()`.
	 * Synchronizes JSON body fields into `$input->post` before reading, and blocks access when demo mode is active.
	 *
	 * @param array<int, string|int>|string|int|callable|null $valid Sanitizer name(s), allowed values whitelist, integer toggle, or validation callback
	 */
	public function post(?string $key = '', array|string|int|callable|null $valid = null, mixed $fallback = null): mixed
	{
		$normalizedKey = $key ?? '';
		if ($normalizedKey !== '') {
			$lookupKey = str_ends_with($normalizedKey, '[]') ? substr($normalizedKey, 0, -2) : $normalizedKey;
			if (!$this->isSafeInputKey($lookupKey)) {
				return $fallback;
			}
		}

		$input = $this->wireInput();
		if ($input === null) {
			return $normalizedKey === '' ? [] : $fallback;
		}

		if ($this->isDemoModeBlocked()) {
			$this->purgeMutatingGlobals();

			return $normalizedKey === '' ? $input->post : $fallback;
		}

		$syncEnabled = $this->shouldSyncJsonToPost();
		if ($syncEnabled) {
			$this->syncJsonToPost();
		} else {
			$this->syncCsrfToPost();
		}

		if (!$syncEnabled && $normalizedKey !== '') {
			$postArr = $this->postArray();
			$arrayMode = str_ends_with($normalizedKey, '[]') || is_array($fallback);
			$cleanKey = str_ends_with($normalizedKey, '[]') ? substr($normalizedKey, 0, -2) : $normalizedKey;
			if (array_key_exists($cleanKey, $postArr) && $postArr[$cleanKey] !== null) {
				$rawVal = $postArr[$cleanKey];
				if ($arrayMode && !is_array($rawVal)) {
					$rawVal = $rawVal === '' ? [] : [$rawVal];
				}

				return $valid !== null
					? $this->applyInputSanitizer($rawVal, $valid, $fallback, $arrayMode)
					: $rawVal;
			}
		}

		try {
			return $input->post($normalizedKey, $valid, $fallback);
		} catch (Throwable $e) {
			$this->logRequestError('post', $e);

			return $fallback;
		}
	}

	/**
	 * Return all GET parameters as a sanitized associative array via `WireInput::$get`.
	 *
	 * @return array<string, mixed>
	 */
	public function getArray(): array
	{
		$input = $this->wireInput();
		if ($input !== null && is_object($input->get) && method_exists($input->get, 'getArray')) {
			try {
				$arr = $input->get->getArray();

				return is_array($arr) ? $this->sanitizeJsonTree($arr) : [];
			} catch (Throwable $e) {
				$this->logRequestError('getArray', $e);

				return [];
			}
		}

		return [];
	}

	/**
	 * Return all POST / JSON parameters as a sanitized associative array via `WireInput::$post`.
	 * Returns an empty array when a state-mutating request is blocked by ProcessWire demo mode (`$config->demo = true`).
	 *
	 * @return array<string, mixed>
	 */
	public function postArray(): array
	{
		if ($this->isDemoModeBlocked()) {
			return [];
		}

		if ($this->shouldSyncJsonToPost()) {
			$this->syncJsonToPost();
		} else {
			$this->syncCsrfToPost();
		}

		$input = $this->wireInput();
		$post = [];
		if ($input !== null && is_object($input->post) && method_exists($input->post, 'getArray')) {
			try {
				$arr = $input->post->getArray();
				$post = is_array($arr) ? $this->sanitizeJsonTree($arr) : [];
			} catch (Throwable $e) {
				$this->logRequestError('postArray', $e);
				$post = [];
			}
		}

		if (!$this->shouldSyncJsonToPost()) {
			$formPayload = $this->isUrlEncodedForm() ? $this->decodeRawFormBody() : [];

			return array_replace($post, $formPayload, $this->json());
		}

		return $post;
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
		$get = $input !== null ? $this->sanitizeJsonTree($input->get->getArray()) : [];

		if ($this->isDemoModeBlocked()) {
			return $get;
		}

		$post = $input !== null ? $this->sanitizeJsonTree($input->post->getArray()) : [];
		$formPayload = $this->isUrlEncodedForm() ? $this->decodeRawFormBody() : [];

		return array_replace($get, $post, $formPayload, $this->json());
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

		$arrayMode = is_array($default);
		$lookupKey = $key;
		if (str_ends_with($lookupKey, '[]')) {
			$arrayMode = true;
			$lookupKey = substr($lookupKey, 0, -2);
		}
		if ($lookupKey === '') {
			return $default;
		}

		$found = false;
		$value = null;

		if (array_key_exists($lookupKey, $all)) {
			$found = true;
			$value = $all[$lookupKey];
		} elseif (str_contains($lookupKey, '.')) {
			$cursor = $all;
			$found = true;
			foreach (explode('.', $lookupKey) as $segment) {
				if ($segment === '' || !$this->isSafeInputKey($segment) || !is_array($cursor) || !array_key_exists($segment, $cursor)) {
					$found = false;
					break;
				}
				$cursor = $cursor[$segment];
			}
			if ($found) {
				$value = $cursor;
			}
		} elseif (!$this->isSafeInputKey($lookupKey)) {
			return $default;
		}

		if (!$found || $value === null) {
			return $default;
		}

		if ($arrayMode && !is_array($value)) {
			$value = $value === '' ? [] : [$value];
		}

		if ($sanitizer === null) {
			return $value;
		}

		return $this->applyInputSanitizer($value, $sanitizer, $default, $arrayMode);
	}

	/**
	 * Apply a ProcessWire `Sanitizer` method (or CSV chain), whitelist array, integer toggle, or callable to a resolved input value.
	 *
	 * @param array<int, string|int>|string|int|callable $sanitizer
	 */
	private function applyInputSanitizer(mixed $value, array|string|int|callable $sanitizer, mixed $default = null, bool $getArray = false): mixed
	{
		if (is_callable($sanitizer) && !is_string($sanitizer)) {
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
			if ($getArray && is_array($value)) {
				$matched = [];
				foreach ($value as $item) {
					if (!is_scalar($item)) {
						continue;
					}
					foreach ($sanitizer as $allowed) {
						if ((string) $allowed === (string) $item) {
							$matched[] = $allowed;
							break;
						}
					}
				}

				return $matched;
			}

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

		$pwSanitizer = $this->wire()?->sanitizer;
		if ($pwSanitizer === null) {
			return $default;
		}

		$allSanitizers = $pwSanitizer->getAll(true);
		$current = $value;

		foreach (explode(',', $sanitizer) as $method) {
			$methodName = trim($method);
			if ($methodName === '') {
				continue;
			}
			$returnType = is_array($allSanitizers) ? (string) ($allSanitizers[$methodName] ?? '') : '';
			if (!str_contains($returnType, 'a')) {
				if ($getArray && is_array($current)) {
					$mapped = [];
					foreach ($current as $k => $item) {
						while (is_array($item)) {
							$item = $item !== [] ? reset($item) : '';
						}
						try {
							$sanitizedItem = $pwSanitizer->sanitize($item, $methodName);
						} catch (Throwable $e) {
							$this->logRequestError('sanitize', $e);

							return $default;
						}
						if ($sanitizedItem !== null) {
							$mapped[$k] = $sanitizedItem;
						}
					}
					$current = array_values($mapped);
					continue;
				}
				while (is_array($current)) {
					$current = $current !== [] ? reset($current) : '';
				}
			}
			try {
				$current = $pwSanitizer->sanitize($current, $methodName);
			} catch (Throwable $e) {
				$this->logRequestError('sanitize', $e);

				return $default;
			}
			if ($current === null) {
				return $default;
			}
		}

		return $current ?? $default;
	}

	/**
	 * Resolve the current pagination page number (`1..maxPageNum`) from `$pageName` input or ProcessWire's `$input->pageNum()`.
	 */
	public function pageNumber(string $pageName = 'page'): int
	{
		$maxPageNum = max(1, (int) ($this->wire()?->config?->maxPageNum ?: 999));
		$val = $this->input($pageName);
		if (is_int($val)) {
			return $val >= 1 ? min($val, $maxPageNum) : 1;
		}
		if (is_string($val)) {
			$trimmed = trim($val);
			if ($trimmed !== '') {
				if (ctype_digit($trimmed)) {
					$parsed = (int) (strlen($trimmed) > 9 ? $maxPageNum : $trimmed);

					return $parsed >= 1 ? min($parsed, $maxPageNum) : 1;
				}
				if ($trimmed[0] === '-' && strlen($trimmed) > 1 && ctype_digit(substr($trimmed, 1))) {
					return 1;
				}
			}
		}

		$input = $this->wireInput();
		if ($input !== null && method_exists($input, 'pageNum')) {
			$pageNum = (int) $input->pageNum();
			if ($pageNum >= 1) {
				return min($pageNum, $maxPageNum);
			}
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

		if (!$this->isMutatingMethod()) {
			if ($throwException) {
				throw new WirePermissionException($this->_('Request security validation failed. Please refresh the page and try again.'));
			}

			return false;
		}

		$config = $this->wire()?->config;
		if ($config !== null && isset($config->protectCSRF) && !(bool) $config->protectCSRF) {
			return true;
		}

		if ($this->shouldSyncJsonToPost()) {
			$this->syncJsonToPost();
		}
		$this->syncCsrfToPost(true);

		$session = $this->wire()?->session;
		$csrf = $session !== null ? $session->CSRF : null;
		if (!is_object($csrf) || !method_exists($csrf, 'hasValidToken')) {
			if ($throwException) {
				throw new WirePermissionException($this->_('Request security validation failed. Please refresh the page and try again.'));
			}

			return false;
		}

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
	 * Determine whether an inbound HTTP header was explicitly sent on the request (even if its value is empty).
	 */
	public function hasHeader(string $name): bool
	{
		$canonical = $name;
		if (str_starts_with(strtoupper($name), 'HTTP_')) {
			$canonical = str_replace('_', '-', substr($name, 5));
		}

		foreach ($this->headerOverrides as $k => $_v) {
			if (strcasecmp($k, $canonical) === 0 || strcasecmp($k, $name) === 0) {
				return true;
			}
		}

		$input = $this->wireInput();
		if ($input !== null && method_exists($input, 'getHeader')) {
			try {
				if ($input->getHeader($canonical) !== null || $input->getHeader($name) !== null) {
					return true;
				}
			} catch (Throwable $e) {
				$this->logRequestError('hasHeader', $e);
			}
		}

		if (function_exists('getallheaders')) {
			$headers = @getallheaders();
			if (is_array($headers)) {
				foreach ($headers as $k => $_v) {
					if (is_string($k) && (strcasecmp($k, $canonical) === 0 || strcasecmp($k, $name) === 0)) {
						return true;
					}
				}
			}
		}

		$upperSnake = strtoupper(str_replace('-', '_', $canonical));
		$serverKeys = ($upperSnake === 'CONTENT_TYPE' || $upperSnake === 'CONTENT_LENGTH')
			? [$upperSnake, 'HTTP_' . $upperSnake]
			: ['HTTP_' . $upperSnake];
		foreach ($serverKeys as $serverKey) {
			if (array_key_exists($serverKey, $_SERVER)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read an inbound HTTP header by canonical name (e.g. `X-Inertia`) or CGI name (`HTTP_X_INERTIA`).
	 */
	public function header(string $name, ?string $default = null): ?string
	{
		$canonical = $name;
		if (str_starts_with(strtoupper($name), 'HTTP_')) {
			$canonical = str_replace('_', '-', substr($name, 5));
		}

		foreach ($this->headerOverrides as $k => $v) {
			if (strcasecmp($k, $canonical) === 0 || strcasecmp($k, $name) === 0) {
				return $v !== '' ? $v : $default;
			}
		}

		$input = $this->wireInput();
		if ($input !== null && method_exists($input, 'getHeader')) {
			try {
				$value = $input->getHeader($canonical);
				if ($value !== null && trim((string) $value) !== '') {
					return trim((string) $value);
				}
				if ($canonical !== $name) {
					$rawVal = $input->getHeader($name);
					if ($rawVal !== null && trim((string) $rawVal) !== '') {
						return trim((string) $rawVal);
					}
				}
			} catch (Throwable $e) {
				$this->logRequestError('header', $e);
			}
		}

		if (function_exists('getallheaders')) {
			$headers = @getallheaders();
			if (is_array($headers)) {
				foreach ($headers as $k => $v) {
					if (is_string($k) && (strcasecmp($k, $canonical) === 0 || strcasecmp($k, $name) === 0) && is_scalar($v)) {
						$str = trim((string) $v);

						return $str !== '' ? $str : $default;
					}
				}
			}
		}

		$upperSnake = strtoupper(str_replace('-', '_', $canonical));
		$serverKeys = ($upperSnake === 'CONTENT_TYPE' || $upperSnake === 'CONTENT_LENGTH')
			? [$upperSnake, 'HTTP_' . $upperSnake]
			: ['HTTP_' . $upperSnake];
		$upperName = strtoupper($name);
		if (str_starts_with($upperName, 'HTTP_') || $upperName === 'CONTENT_TYPE' || $upperName === 'CONTENT_LENGTH') {
			$serverKeys[] = $upperName;
		}
		foreach (array_unique($serverKeys) as $serverKey) {
			if (isset($_SERVER[$serverKey]) && is_scalar($_SERVER[$serverKey])) {
				$str = trim((string) $_SERVER[$serverKey]);

				return $str !== '' ? $str : $default;
			}
		}

		return $default;
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
			'Sec-Purpose' => 'sec-purpose',
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
		$this->formBody = null;
		$this->preDemoMethod = null;
		$this->demoBlocked = false;
		unset($_SERVER['PW_DEMO_ORIGINAL_METHOD']);

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
		$this->formBody = null;
		$this->preDemoMethod = null;
		$this->demoBlocked = false;
		unset($_SERVER['PW_DEMO_ORIGINAL_METHOD']);

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

	private function logRequestError(string $context, Throwable $e): void
	{
		try {
			$this->wire()?->log?->error("Inertia Request {$context} failed: " . $e->getMessage());
		} catch (Throwable) {
			// Guard against recursive logging errors when filesystem log is unwritable
		}
	}
}
