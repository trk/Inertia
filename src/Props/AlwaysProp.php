<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Props;

use Closure;
use Totoglu\Inertia\Request;

/**
 * Prop wrapper evaluated and included on every response, even during partial reloads
 * where `X-Inertia-Partial-Data` does not explicitly list this key.
 */
final class AlwaysProp implements PropInterface
{
	public function __construct(
		private readonly mixed $value
	) {
	}

	public function __invoke(?Request $request = null): mixed
	{
		return $this->value instanceof Closure ? ($this->value)() : $this->value;
	}
}
