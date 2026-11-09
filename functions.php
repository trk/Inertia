<?php

declare(strict_types=1);

namespace ProcessWire {

	use Totoglu\Inertia\PageResponse;
	use UnitEnum;

	if (!function_exists(__NAMESPACE__ . '\inertia')) {
		/**
		 * Return the shared `ProcessWire\Inertia` module instance when called with no arguments,
		 * or create and register a `Totoglu\Inertia\PageResponse` when `$component` is given.
		 *
		 * @param array<string, mixed> $props
		 */
		function inertia(string|UnitEnum|null $component = null, array $props = []): Inertia|PageResponse
		{
			$service = Inertia::instance();

			if ($component !== null) {
				return $service->render($component, $props);
			}

			return $service;
		}
	}
}

namespace {

	use ProcessWire\Inertia;
	use Totoglu\Inertia\PageResponse;

	if (!function_exists('inertia')) {
		/**
		 * Return the shared `ProcessWire\Inertia` module instance when called with no arguments,
		 * or create and register a `Totoglu\Inertia\PageResponse` when `$component` is given.
		 *
		 * @param array<string, mixed> $props
		 */
		function inertia(string|UnitEnum|null $component = null, array $props = []): Inertia|PageResponse
		{
			return \ProcessWire\inertia($component, $props);
		}
	}
}
