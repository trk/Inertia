<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Contracts;

/**
 * Contract for domain objects and Custom Page Classes (`site/classes/*Page.php`)
 * that define their own Inertia prop representation.
 */
interface InertiaSerializable
{
	/**
	 * Convert the object into an Inertia-compatible array or scalar value.
	 */
	public function toInertiaProp(): mixed;
}
