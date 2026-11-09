<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Props;

use Closure;
use Totoglu\Inertia\Request;

/**
 * Prop wrapper excluded from standard visits and evaluated only when explicitly
 * requested by key in `X-Inertia-Partial-Data`. Supports once-prop caching (`->once()`).
 */
final class OptionalProp implements PropInterface
{
	use ConfiguresOnceStrategy;

	private readonly Closure $callback;

	public function __construct(callable $callback)
	{
		$this->callback = Closure::fromCallable($callback);
	}

	public function __invoke(?Request $request = null): mixed
	{
		return ($this->callback)();
	}
}
