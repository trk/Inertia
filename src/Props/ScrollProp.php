<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Props;

use Closure;
use ProcessWire\Page;
use ProcessWire\PaginatedArray;
use Totoglu\Inertia\Provider\TemplateDataProvider;
use Totoglu\Inertia\Request;

/**
 * Wraps a paginated dataset (`PaginatedArray`, `PageArray`, `Closure`, or array) and generates
 * `mergeProps` / `prependProps` (`<prop>.<wrapper>`) and `scrollProps` pagination metadata.
 */
final class ScrollProp implements PropInterface
{
	/** @var list<string> */
	private array $matchOn = [];

	private ?int $customCurrentPage = null;

	private ?int $customNextPage = null;

	private ?int $customPreviousPage = null;

	private bool $resolved = false;

	private ?int $resolvedPageNumber = null;

	/** @var array<string, mixed> */
	private array $resolvedPayload = [];

	/** @var array{pageName: string, previousPage: int|null, nextPage: int|null, currentPage: int} */
	private array $resolvedMeta;

	public function __construct(
		private readonly mixed $value,
		private readonly string $wrapper = 'data',
		private readonly string $pageName = 'page'
	) {
		$this->resolvedMeta = [
			'pageName' => $this->pageName,
			'previousPage' => null,
			'nextPage' => null,
			'currentPage' => 1,
		];
	}

	public function wrapper(): string
	{
		return $this->wrapper;
	}

	/**
	 * Configure de-duplication key(s) for the merged `<prop>.<wrapper>` collection.
	 *
	 * @param string|list<string> $keys
	 */
	public function matchOn(string|array $keys): self
	{
		$normalized = is_array($keys) ? $keys : [$keys];
		foreach ($normalized as $key) {
			$trimmed = trim((string) $key);
			if ($trimmed !== '' && !in_array($trimmed, $this->matchOn, true)) {
				$this->matchOn[] = $trimmed;
			}
		}

		return $this;
	}

	/**
	 * @return list<string>
	 */
	public function matchesOn(): array
	{
		return $this->matchOn;
	}

	/**
	 * Explicitly override pagination cursors when passing a plain array instead of a `PaginatedArray`.
	 */
	public function withMeta(int $currentPage, ?int $nextPage = null, ?int $previousPage = null): self
	{
		$this->customCurrentPage = max(1, $currentPage);
		$this->customNextPage = $nextPage;
		$this->customPreviousPage = $previousPage;

		return $this;
	}

	/**
	 * Return the `scrollProps` metadata structure for this prop.
	 *
	 * @return array{pageName: string, previousPage: int|null, nextPage: int|null, currentPage: int, reset: bool}
	 */
	public function metadata(bool $reset = false, ?Request $request = null): array
	{
		$this->ensureResolved($request);

		return [
			'pageName' => $this->resolvedMeta['pageName'],
			'previousPage' => $this->resolvedMeta['previousPage'],
			'nextPage' => $this->resolvedMeta['nextPage'],
			'currentPage' => $this->resolvedMeta['currentPage'],
			'reset' => $reset,
		];
	}

	public function __invoke(?Request $request = null): mixed
	{
		$this->ensureResolved($request);

		return $this->resolvedPayload;
	}

	private function ensureResolved(?Request $request = null): void
	{
		$requestedPage = $this->detectRequestPageNumber($request);
		if ($this->resolved && $this->resolvedPageNumber === $requestedPage) {
			return;
		}

		$this->resolved = true;
		$this->resolvedPageNumber = $requestedPage;
		$raw = $this->evaluateRawValue($request, $requestedPage);

		if ($raw instanceof PaginatedArray) {
			$limit = (int) $raw->getLimit();
			$start = (int) $raw->getStart();
			$total = (int) $raw->getTotal();
			$count = $raw->count();

			$currentPage = $this->customCurrentPage ?? ($limit > 0 ? (int) floor($start / $limit) + 1 : 1);
			$previousPage = $this->customPreviousPage ?? ($currentPage > 1 ? $currentPage - 1 : null);
			$nextPage = $this->customNextPage ?? (($limit > 0 && ($start + $count) < $total) ? $currentPage + 1 : null);

			$items = [];
			foreach ($raw as $item) {
				if ($item instanceof Page && !TemplateDataProvider::isAccessiblePage($item)) {
					continue;
				}
				$items[] = $item;
			}

			$this->resolvedMeta = [
				'pageName' => $this->pageName,
				'previousPage' => $previousPage,
				'nextPage' => $nextPage,
				'currentPage' => $currentPage,
			];

			$this->resolvedPayload = [
				$this->wrapper => $items,
			];

			return;
		}

		if (is_array($raw) && array_key_exists($this->wrapper, $raw)) {
			$currentPage = $this->customCurrentPage ?? (int) ($raw['currentPage'] ?? $requestedPage);
			$rawPrev = $raw['previousPage'] ?? null;
			$rawNext = $raw['nextPage'] ?? null;
			$previousPage = $this->customPreviousPage ?? ($rawPrev !== null ? (int) $rawPrev : ($currentPage > 1 ? $currentPage - 1 : null));
			$nextPage = $this->customNextPage ?? ($rawNext !== null ? (int) $rawNext : null);

			$this->resolvedMeta = [
				'pageName' => (string) ($raw['pageName'] ?? $this->pageName),
				'previousPage' => $previousPage,
				'nextPage' => $nextPage,
				'currentPage' => $currentPage,
			];

			$this->resolvedPayload = $raw;

			return;
		}

		$currentPage = $this->customCurrentPage ?? $requestedPage;
		$this->resolvedMeta = [
			'pageName' => $this->pageName,
			'previousPage' => $this->customPreviousPage ?? ($currentPage > 1 ? $currentPage - 1 : null),
			'nextPage' => $this->customNextPage,
			'currentPage' => $currentPage,
		];

		$this->resolvedPayload = [
			$this->wrapper => is_array($raw) ? $raw : [$raw],
		];
	}

	private function evaluateRawValue(?Request $request = null, int $requestedPage = 1): mixed
	{
		if (!$this->value instanceof Closure) {
			return $this->value;
		}

		if ($request === null) {
			return ($this->value)();
		}

		$input = $request->wire()->input;
		$previousPageNum = (int) $input->pageNum();
		$input->setPageNum($requestedPage);

		try {
			return ($this->value)();
		} finally {
			$input->setPageNum($previousPageNum);
		}
	}

	private function detectRequestPageNumber(?Request $request = null): int
	{
		return $request !== null ? $request->pageNumber($this->pageName) : 1;
	}
}
