<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Props;

use Totoglu\Inertia\Request;

/**
 * Contract for Inertia prop wrappers (`AlwaysProp`, `OptionalProp`, `DeferredProp`, `MergeProp`, `OnceProp`, `ScrollProp`).
 */
interface PropInterface
{
	/**
	 * Resolve the underlying value or callback of the prop.
	 */
	public function __invoke(?Request $request = null): mixed;
}
