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

		$styles = is_array($viewData['styles'] ?? null) ? $viewData['styles'] : [];
		if ($config->styles) {
			foreach ($styles as $styleUrl) {
				if (is_string($styleUrl) && trim($styleUrl) !== '') {
					$config->styles->add(trim($styleUrl));
				}
			}
		}

		$scripts = is_array($viewData['scripts'] ?? null) ? $viewData['scripts'] : [];
		if ($config->scripts) {
			foreach ($scripts as $scriptUrl) {
				if (is_string($scriptUrl) && trim($scriptUrl) !== '') {
					$config->scripts->add(trim($scriptUrl));
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
			$config->js('InertiaShared', $mergedShared);
		}

		return $styles !== [] || $scripts !== [] || $shared !== [];
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
