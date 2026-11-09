<?php

declare(strict_types=1);

namespace Totoglu\Inertia;

use ProcessWire\ModuleJS;
use Totoglu\Inertia\Contracts\InertiaSerializable;

/**
 * Base ProcessWire `Markup` (`ModuleJS`) class for Inertia view components.
 *
 * Serializes to `['component' => string, 'props' => array]` when passed as an Inertia prop,
 * or renders a `<div class="pw-inertia-island" data-inertia-island="...">` mount node on classic pages.
 *
 * @method string render(array $props = [])
 */
abstract class MarkupInertia extends ModuleJS implements InertiaSerializable
{
	use InteractWithInertia;

	/**
	 * Return the frontend component identifier (defaults to class name without `Markup` prefix).
	 */
	public function inertiaComponent(): string
	{
		$className = $this->className();
		$stripped = preg_replace('/^Markup(?=[A-Z])/', '', $className);

		return is_string($stripped) && $stripped !== '' ? $stripped : $className;
	}

	/**
	 * @return array{component: string, props: array<string, mixed>}
	 */
	public function toInertiaProp(): array
	{
		return [
			'component' => $this->inertiaComponent(),
			'props' => $this->getInertiaProps(),
		];
	}

	/**
	 * Enqueue `scripts` and `styles` from `inertiaViewData()` and publish `inertiaShared()`
	 * to `$config->js('InertiaShared', ...)` when rendering on a classic page.
	 */
	public function renderReady(): bool
	{
		$this->enqueueInertiaIslandAssets();

		return true;
	}

	/**
	 * @param array<string, mixed> $props
	 */
	public function ___render(array $props = []): string
	{
		if ($props !== []) {
			$this->with($props);
		}

		$this->renderReady();

		$payload = $this->inertia()->resolver->resolveValue($this->toInertiaProp());
		$component = is_array($payload) && is_string($payload['component'] ?? null)
			? $payload['component']
			: $this->inertiaComponent();
		$resolvedProps = is_array($payload) && is_array($payload['props'] ?? null)
			? $payload['props']
			: [];

		$safeComponent = $this->encodeHtmlAttribute($component);
		$jsonProps = json_encode($resolvedProps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$safeProps = $this->encodeHtmlAttribute(is_string($jsonProps) ? $jsonProps : '{}');

		return "<div class=\"pw-inertia-island\" data-inertia-island=\"{$safeComponent}\" data-props=\"{$safeProps}\"></div>";
	}
}
