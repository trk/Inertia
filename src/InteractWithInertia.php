<?php

declare(strict_types=1);

namespace Totoglu\Inertia;

use ProcessWire\Inertia;
use ProcessWire\Page;
use ProcessWire\Process;
use ProcessWire\Wire;
use UnitEnum;

/**
 * Opt-in trait for ProcessWire Custom Page Classes (`site/classes/*Page.php`),
 * Admin `Process` modules (`ProcessInertia`), `MarkupInertia`, and `InputfieldInertia`.
 *
 * Binds host-level props (`inertiaShared()`, `inertiaWithData()`) and root view variables
 * (`inertiaViewData()`, `inertiaWithViewData()`) to Inertia responses without colliding with
 * native ProcessWire `Page` or `Process` methods.
 */
trait InteractWithInertia
{
	/** @var array<string, mixed> Explicit component props registered via `with()` */
	protected array $inertiaProps = [];

	/**
	 * Resolve the wired `Inertia` module instance.
	 */
	public function inertia(): Inertia
	{
		/** @var object $host */
		$host = $this;

		return Inertia::instance($host instanceof Wire ? $host : null);
	}

	/**
	 * Merge explicit component props into this instance.
	 *
	 * @param string|array<string, mixed> $key
	 */
	public function with(string|array $key, mixed $value = null): self
	{
		if (is_array($key)) {
			$this->inertiaProps = array_replace($this->inertiaProps, $key);
		} else {
			$this->inertiaProps[$key] = $value;
		}

		return $this;
	}

	/**
	 * Return merged component props (`inertiaWithData()` + explicit `with()` props).
	 *
	 * @return array<string, mixed>
	 */
	public function getInertiaProps(): array
	{
		return array_replace($this->inertiaWithData(), $this->inertiaProps);
	}

	/**
	 * Render a `PageResponse` bound to this `Page` or `Process` instance.
	 *
	 * @param array<string, mixed> $props
	 */
	public function inertiaRender(string|UnitEnum $component, array $props = []): PageResponse
	{
		$mergedProps = $this->inertiaProps !== []
			? array_replace($this->inertiaProps, $props)
			: $props;
		$response = $this->inertia()->render($component, $mergedProps);

		/** @var object $host */
		$host = $this;
		if ($host instanceof Page) {
			$response->forPage($host);
		} elseif ($host instanceof Process) {
			$response->forProcess($host);
		}

		return $response;
	}

	/**
	 * Optional custom Root View path or template name for this Page or Process module.
	 * Return `null` to use the globally configured Inertia Root View (`_inertia.php`).
	 */
	public function inertiaRootView(): ?string
	{
		return null;
	}

	/**
	 * When used on an Admin `Process` module, controls whether `Inertia` intercepts `Page::render`
	 * to render a standalone full-page application (bypassing classic `AdminThemeUikit`, `JqueryCore`, and `JqueryUI`).
	 */
	public function inertiaFullPageAdmin(): bool
	{
		return true;
	}

	/**
	 * Return shared Inertia props (`sharedProps`) contributed by this Page class.
	 * Automatically merged into `props` and `sharedProps` during `Inertia::buildPageObject()`.
	 *
	 * @return array<string, mixed>
	 */
	public function inertiaShared(): array
	{
		return [];
	}

	/**
	 * Return default component props contributed by this Page class.
	 * Automatically merged before explicit `$props` passed to `$inertia->render()` or `$page->inertiaRender()`.
	 *
	 * @return array<string, mixed>
	 */
	public function inertiaWithData(): array
	{
		return [];
	}

	/**
	 * Return server-only PHP variables for the Root View (`_inertia.php`), such as `['locale' => 'en', 'head' => '...']`.
	 *
	 * @param array<string, mixed> $pageObject
	 * @return array<string, mixed>
	 */
	public function inertiaViewData(array $pageObject = []): array
	{
		return [];
	}

	/**
	 * Return additional server-only PHP variables for the Root View (`_inertia.php`), merged on top of `inertiaViewData()`.
	 *
	 * @param array<string, mixed> $pageObject
	 * @return array<string, mixed>
	 */
	public function inertiaWithViewData(array $pageObject = []): array
	{
		return [];
	}

	/**
	 * Enqueue any `scripts` and `styles` declared in `inertiaViewData()` / `inertiaWithViewData()`
	 * and publish `inertiaShared()` to `$config->js('InertiaShared', ...)` when rendering an island on a classic page.
	 */
	protected function enqueueInertiaIslandAssets(): bool
	{
		/** @var object $host */
		$host = $this;
		if (!$host instanceof Wire) {
			return false;
		}

		$config = $host->wire()->config;

		$viewData = array_replace(
			$this->inertiaViewData(),
			$this->inertiaWithViewData()
		);

		$enqueuedAny = false;
		$styles = is_array($viewData['styles'] ?? null) ? $viewData['styles'] : [];
		if ($config->styles) {
			foreach ($styles as $styleUrl) {
				if (is_string($styleUrl) && $this->isSafeAssetUrl($styleUrl)) {
					$config->styles->add(trim($styleUrl));
					$enqueuedAny = true;
				}
			}
		}

		$scripts = is_array($viewData['scripts'] ?? null) ? $viewData['scripts'] : [];
		if ($config->scripts) {
			foreach ($scripts as $scriptUrl) {
				if (is_string($scriptUrl) && $this->isSafeAssetUrl($scriptUrl)) {
					$config->scripts->add(trim($scriptUrl));
					$enqueuedAny = true;
				}
			}
		}

		$shared = $this->inertiaShared();
		if ($shared !== []) {
			$resolvedShared = $this->inertia()->resolver->resolveValue($shared);
			$existingShared = $config->js('InertiaShared');
			$mergedShared = is_array($existingShared) && is_array($resolvedShared)
				? array_replace($existingShared, $resolvedShared)
				: $resolvedShared;
			if (is_array($mergedShared)) {
				unset($mergedShared['__proto__'], $mergedShared['constructor'], $mergedShared['prototype']);
			}
			$config->js('InertiaShared', $mergedShared);
			$enqueuedAny = true;
		}

		return $enqueuedAny;
	}

	/**
	 * Reject dangerous client-side URI schemes, protocol-relative URLs, HTTPS-to-HTTP downgrades,
	 * and untrusted cross-origin hosts before enqueuing island stylesheet or script URLs.
	 */
	protected function isSafeAssetUrl(string $url): bool
	{
		$trimmed = trim(str_replace(["\r", "\n", "\0"], '', $url));
		if ($trimmed === '' || str_starts_with($trimmed, '//') || str_contains($trimmed, '\\')) {
			return false;
		}

		$probe = (string) preg_replace('/[\x00-\x20]+/', '', $trimmed);
		if (preg_match('/^(?:javascript|data|vbscript|blob|file|js):/i', $probe) === 1) {
			return false;
		}

		if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $probe) === 1) {
			if (preg_match('#^https?://#i', $trimmed) !== 1) {
				return false;
			}
			$parts = parse_url($trimmed);
			if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
				return false;
			}

			/** @var object $hostObj */
			$hostObj = $this;
			$config = $hostObj instanceof Wire ? $hostObj->wire()?->config : null;
			if ($config !== null) {
				if ((bool) $config->https && strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
					return false;
				}
				$candidateHost = strtolower((string) $parts['host']);
				$candidateAuthority = isset($parts['port']) ? $candidateHost . ':' . (int) $parts['port'] : $candidateHost;
				$allowedHosts = [];
				$currentHost = strtolower(trim((string) $config->httpHost));
				if ($currentHost !== '') {
					$allowedHosts[] = $currentHost;
				}
				if (is_array($config->httpHosts)) {
					foreach ($config->httpHosts as $whitelisted) {
						$norm = strtolower(trim((string) $whitelisted));
						if ($norm !== '') {
							$allowedHosts[] = $norm;
						}
					}
				}
				if ($allowedHosts !== [] && !in_array($candidateAuthority, $allowedHosts, true) && !in_array($candidateHost, $allowedHosts, true)) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Safely encode a string for inclusion inside a double-quoted HTML attribute.
	 */
	protected function encodeHtmlAttribute(string $value): string
	{
		/** @var object $host */
		$host = $this;
		if ($host instanceof Wire) {
			return $host->wire()->sanitizer->entities($value);
		}

		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
