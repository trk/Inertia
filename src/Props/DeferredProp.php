<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Props;

use Closure;
use Totoglu\Inertia\Request;

/**
 * Prop wrapper omitted on initial page visits and announced under `deferredProps[$group]`
 * for asynchronous client loading after mount.
 */
final class DeferredProp implements PropInterface
{
	use ConfiguresMergeStrategy;
	use ConfiguresOnceStrategy;

	private readonly Closure $callback;

	public function __construct(
		callable $callback,
		private readonly string $group = 'default'
	) {
		$this->callback = Closure::fromCallable($callback);
	}

	public function group(): string
	{
		return $this->group !== '' ? $this->group : 'default';
	}

	/**
	 * Mark this deferred prop to be shallow-merged (appended) on the client.
	 */
	public function merge(): self
	{
		$this->shouldMerge = true;
		$this->mergeStrategy = MergeStrategy::Append;

		return $this;
	}

	public function __invoke(?Request $request = null): mixed
	{
		return ($this->callback)();
	}
}
