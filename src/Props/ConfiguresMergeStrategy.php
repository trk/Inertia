<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Props;

/**
 * Fluent merge configuration (`append()`, `prepend()`, `deepMerge()`, `matchOn()`) for `MergeProp` and `DeferredProp`.
 */
trait ConfiguresMergeStrategy
{
	protected bool $shouldMerge = false;

	protected MergeStrategy $mergeStrategy = MergeStrategy::Append;

	/** @var list<string> */
	protected array $matchOn = [];

	/** @var list<string> Nested paths targeted for append (`->append('data')`) */
	protected array $appendPaths = [];

	/** @var list<string> Nested paths targeted for prepend (`->prepend('announcements')`) */
	protected array $prependPaths = [];

	/**
	 * Append incoming items to the end of the existing client array (root or nested paths).
	 *
	 * @param bool|string|array<int|string, string> $paths
	 * @param string|list<string>|null $matchOn
	 */
	public function append(bool|string|array $paths = true, string|array|null $matchOn = null): static
	{
		$this->shouldMerge = true;
		$this->registerTargetedPaths($paths, $matchOn, MergeStrategy::Append);

		return $this;
	}

	/**
	 * Prepend incoming items to the beginning of the existing client array (root or nested paths).
	 *
	 * @param bool|string|array<int|string, string> $paths
	 * @param string|list<string>|null $matchOn
	 */
	public function prepend(bool|string|array $paths = true, string|array|null $matchOn = null): static
	{
		$this->shouldMerge = true;
		$this->registerTargetedPaths($paths, $matchOn, MergeStrategy::Prepend);

		return $this;
	}

	/**
	 * Deeply merge nested associative arrays / objects on the client.
	 */
	public function deepMerge(): static
	{
		$this->shouldMerge = true;
		$this->mergeStrategy = MergeStrategy::Deep;

		return $this;
	}

	/**
	 * Specify one or more unique key paths (e.g. 'id' or ['id', 'slug']) used by the client
	 * to deduplicate and update matching entries when merging.
	 *
	 * @param string|list<string> $keys
	 */
	public function matchOn(string|array $keys): static
	{
		$this->shouldMerge = true;
		$this->addMatchOnKeys($keys);

		return $this;
	}

	public function shouldMerge(): bool
	{
		return $this->shouldMerge;
	}

	/**
	 * Normalize and append unique match keys for client-side deduplication.
	 * Bare keys (`'id'`) are automatically qualified against targeted paths (`'data.id'`) in `matchesOn()`.
	 *
	 * @param string|list<string> $keys
	 */
	protected function addMatchOnKeys(string|array $keys, ?string $prefixPath = null): void
	{
		$targetPaths = $prefixPath !== null && $prefixPath !== ''
			? [$prefixPath]
			: $this->targetedPaths();

		$this->matchOn = array_values(array_unique(array_merge(
			$this->matchOn,
			self::qualifyMatchKeys((array) $keys, $targetPaths)
		)));
	}

	/**
	 * Register root or nested append/prepend targets and optional `matchOn` keys (Inertia v3).
	 *
	 * @param bool|string|array<int|string, string> $paths
	 * @param string|list<string>|null $matchOn
	 */
	protected function registerTargetedPaths(
		bool|string|array $paths,
		string|array|null $matchOn,
		MergeStrategy $strategy
	): void {
		if ($paths === true) {
			$this->mergeStrategy = $strategy;
			if ($matchOn !== null) {
				$this->addMatchOnKeys($matchOn);
			}

			return;
		}

		if (is_string($paths)) {
			$trimmed = trim($paths);
			if ($trimmed !== '') {
				$this->addTargetPath($trimmed, $strategy);
				if ($matchOn !== null) {
					$this->addMatchOnKeys($matchOn, $trimmed);
				}
			}

			return;
		}

		if (is_array($paths)) {
			foreach ($paths as $k => $v) {
				if (is_string($k) && is_string($v)) {
					$targetPath = trim($k);
					if ($targetPath !== '') {
						$this->addTargetPath($targetPath, $strategy);
						$this->addMatchOnKeys($v, $targetPath);
					}
				} elseif (is_string($v)) {
					$targetPath = trim($v);
					if ($targetPath !== '') {
						$this->addTargetPath($targetPath, $strategy);
						if ($matchOn !== null) {
							$this->addMatchOnKeys($matchOn, $targetPath);
						}
					}
				}
			}
		}
	}

	private function addTargetPath(string $path, MergeStrategy $strategy): void
	{
		if ($strategy === MergeStrategy::Prepend) {
			if (!in_array($path, $this->prependPaths, true)) {
				$this->prependPaths[] = $path;
			}
		} else {
			if (!in_array($path, $this->appendPaths, true)) {
				$this->appendPaths[] = $path;
			}
		}
	}

	public function mergeStrategy(): MergeStrategy
	{
		return $this->mergeStrategy;
	}

	/**
	 * @return list<string>
	 */
	public function appendPaths(): array
	{
		return $this->appendPaths;
	}

	/**
	 * @return list<string>
	 */
	public function prependPaths(): array
	{
		return $this->prependPaths;
	}

	/**
	 * @return list<string>
	 */
	public function matchesOn(): array
	{
		return self::qualifyMatchKeys($this->matchOn, $this->targetedPaths());
	}

	/**
	 * @return list<string>
	 */
	private function targetedPaths(): array
	{
		return array_values(array_unique(array_merge($this->appendPaths, $this->prependPaths)));
	}

	/**
	 * @param iterable<mixed> $keys
	 * @param list<string> $targetPaths
	 * @return list<string>
	 */
	private static function qualifyMatchKeys(iterable $keys, array $targetPaths): array
	{
		$resolved = [];
		foreach ($keys as $key) {
			if (!is_string($key)) {
				continue;
			}

			$trimmed = trim($key);
			if ($trimmed === '') {
				continue;
			}

			if ($targetPaths !== [] && !str_contains($trimmed, '.')) {
				foreach ($targetPaths as $targetPath) {
					$resolved[] = $targetPath . '.' . $trimmed;
				}
			} else {
				$resolved[] = $trimmed;
			}
		}

		return array_values(array_unique($resolved));
	}
}
