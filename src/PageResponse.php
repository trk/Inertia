<?php

declare(strict_types=1);

namespace Totoglu\Inertia;

use JsonSerializable;
use ProcessWire\Inertia;
use ProcessWire\Page;
use ProcessWire\Process;
use ProcessWire\Wire;
use Stringable;
use Throwable;
use Totoglu\Inertia\Support\PropResolver;

/**
 * Pending Inertia response returned by `$inertia->render()` or `inertia()`.
 *
 * Compiles into an Inertia Page Object (JSON) on `X-Inertia: true` visits or renders
 * the configured Root View HTML shell (`_inertia.php`) on initial page loads.
 */
class PageResponse extends Wire implements JsonSerializable, Stringable
{
	/** @var array<string, mixed> PHP-only variables passed to the Root View (`_inertia.php`) */
	private array $viewData = [];

	/** @var array<string, mixed> Custom flashed data for top-level `page.flash` */
	private array $flashedData = [];

	private ?string $rootViewOverride = null;

	private ?string $urlOverride = null;

	private ?bool $encryptHistoryOverride = null;

	private ?bool $clearHistoryOverride = null;

	private ?bool $preserveFragmentOverride = null;

	private bool $withoutSsr = false;

	private ?Page $page = null;

	private ?Process $process = null;

	/** @var array<string, mixed>|null Cached compiled Page Object */
	private ?array $resolvedPageObject = null;

	/**
	 * @param array<string, mixed> $props
	 */
	public function __construct(
		private readonly Inertia $module,
		private string $component,
		private array $props = [],
		?Page $page = null,
		?Process $process = null
	) {
		$this->page = $page;
		$this->process = $process;
		$this->module->wire($this);
	}

	/**
	 * Bind a ProcessWire `Page` context to this response.
	 */
	public function forPage(?Page $page): self
	{
		$this->resolvedPageObject = null;
		$this->page = $page;
		if ($page instanceof Page) {
			if ($page->id > 0) {
				$this->module->configurePageTemplateFiles($page);
			}
			if ($this->rootViewOverride === null && method_exists($page, 'inertiaRootView')) {
				$rootView = $page->inertiaRootView();
				if (is_string($rootView) && trim($rootView) !== '') {
					$this->rootViewOverride = trim($rootView);
				}
			}
		}

		return $this;
	}

	public function getPage(): ?Page
	{
		return $this->page;
	}

	/**
	 * Bind a ProcessWire `Process` module context to this response.
	 */
	public function forProcess(?Process $process): self
	{
		$this->resolvedPageObject = null;
		$this->process = $process;
		if ($process instanceof Process && $this->rootViewOverride === null && method_exists($process, 'inertiaRootView')) {
			$rootView = $process->inertiaRootView();
			if (is_string($rootView) && trim($rootView) !== '') {
				$this->rootViewOverride = trim($rootView);
			}
		}

		return $this;
	}

	public function getProcess(): ?Process
	{
		return $this->process;
	}

	public function component(): string
	{
		return $this->component;
	}

	/**
	 * Add or merge component props on this response.
	 *
	 * @param string|array<string, mixed> $key
	 */
	public function with(string|array $key, mixed $value = null): self
	{
		$this->resolvedPageObject = null;
		$this->props = array_merge($this->props, is_array($key) ? $key : [$key => $value]);

		return $this;
	}

	/**
	 * Pass server-only PHP variables to the Root View template (`_inertia.php`) without exposing them in `props`.
	 *
	 * @param string|array<string, mixed> $key
	 */
	public function withViewData(string|array $key, mixed $value = null): self
	{
		$incoming = is_array($key) ? $key : [$key => $value];
		$this->viewData = PropResolver::mergeViewData($this->viewData, $incoming);

		return $this;
	}

	/**
	 * When called with no arguments, returns all Root View variables registered on this response.
	 * When called with `$key` (and optional `$value`), acts as a fluent alias for `withViewData()`.
	 *
	 * @param string|array<string, mixed>|null $key
	 * @return ($key is null ? array<string, mixed> : self)
	 */
	public function viewData(string|array|null $key = null, mixed $value = null): array|self
	{
		if ($key === null) {
			return $this->viewData;
		}

		return $this->withViewData($key, $value);
	}

	public function getViewData(?string $key = null, mixed $default = null): mixed
	{
		if ($key === null) {
			return $this->viewData;
		}

		return $this->viewData[$key] ?? $default;
	}

	/**
	 * Flash custom key-value data onto this response's top-level `page.flash` object.
	 *
	 * @param string|array<string, mixed> $key
	 */
	public function flash(string|array $key, mixed $value = null): self
	{
		$this->resolvedPageObject = null;
		$this->flashedData = array_merge($this->flashedData, is_array($key) ? $key : [$key => $value]);

		return $this;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getFlashedData(): array
	{
		return $this->flashedData;
	}

	/**
	 * Override the Root View file for this response (e.g. `_inertia-admin.php`).
	 */
	public function rootView(string $view): self
	{
		$this->rootViewOverride = trim($view);

		return $this;
	}

	public function getRootView(): ?string
	{
		return $this->rootViewOverride;
	}

	/**
	 * Override the `url` field in the Page Object.
	 */
	public function url(string $url): self
	{
		$this->resolvedPageObject = null;
		$this->urlOverride = $url;

		return $this;
	}

	public function getUrlOverride(): ?string
	{
		return $this->urlOverride;
	}

	public function encryptHistory(bool $encrypt = true): self
	{
		$this->resolvedPageObject = null;
		$this->encryptHistoryOverride = $encrypt;

		return $this;
	}

	public function getEncryptHistoryOverride(): ?bool
	{
		return $this->encryptHistoryOverride;
	}

	public function clearHistory(bool $clear = true): self
	{
		$this->resolvedPageObject = null;
		$this->clearHistoryOverride = $clear;

		return $this;
	}

	public function getClearHistoryOverride(): ?bool
	{
		return $this->clearHistoryOverride;
	}

	public function preserveFragment(bool $preserve = true): self
	{
		$this->resolvedPageObject = null;
		$this->preserveFragmentOverride = $preserve;

		return $this;
	}

	public function getPreserveFragmentOverride(): ?bool
	{
		return $this->preserveFragmentOverride;
	}

	public function withoutSsr(bool $withoutSsr = true): self
	{
		$this->withoutSsr = $withoutSsr;

		return $this;
	}

	public function isWithoutSsr(): bool
	{
		return $this->withoutSsr;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function rawProps(): array
	{
		return $this->props;
	}

	/**
	 * Build and return the resolved Page Object array.
	 *
	 * @return array<string, mixed>
	 */
	public function toPageObject(): array
	{
		if ($this->resolvedPageObject !== null) {
			return $this->resolvedPageObject;
		}

		return $this->resolvedPageObject = $this->module->buildPageObject($this);
	}

	/**
	 * Encode the Page Object as a JSON string.
	 */
	public function toJson(): string
	{
		$encoded = json_encode($this->toPageObject(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return is_string($encoded) ? str_replace('</', '<\/', $encoded) : '{}';
	}

	/**
	 * Render either the JSON Page Object (when `X-Inertia: true`) or the Root View HTML shell.
	 */
	public function toOutput(): string
	{
		return $this->module->renderPageResponse($this);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array
	{
		return $this->toPageObject();
	}

	public function __toString(): string
	{
		try {
			return $this->toOutput();
		} catch (Throwable $e) {
			$this->wire()->log->error(sprintf($this->_('Inertia PageResponse render error: %s'), $e->getMessage()));

			return '';
		}
	}
}
