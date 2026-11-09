<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Contracts;

use Totoglu\Inertia\Ssr\SsrResponse;

/**
 * Contract for dispatching an Inertia Page Object to a Server-Side Rendering (SSR) engine.
 */
interface SsrGatewayInterface
{
	/**
	 * Dispatch the Inertia Page Object array to the SSR server and return the rendered head/body,
	 * or null when SSR is disabled or unreachable.
	 *
	 * @param array<string, mixed> $pageObject
	 */
	public function dispatch(array $pageObject): ?SsrResponse;
}
