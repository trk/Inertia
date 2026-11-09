<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Props;

use Closure;
use Totoglu\Inertia\Request;

/**
 * Prop wrapper evaluated once and cached across subsequent client navigations
 * (`onceProps` metadata + `X-Inertia-Except-Once-Props` request header).
 */
final class OnceProp implements PropInterface
{
	use ConfiguresOnceStrategy;

	private readonly Closure $callback;

	public function __construct(callable $callback)
	{
		$this->callback = Closure::fromCallable($callback);
		$this->onceEnabled = true;
	}

	public function __invoke(?Request $request = null): mixed
	{
		return ($this->callback)();
	}
}
