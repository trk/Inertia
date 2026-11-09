<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Provider;

use ProcessWire\Field;
use ProcessWire\Inertia;
use ProcessWire\Page;
use ProcessWire\Wire;

/**
 * Base class for `Fieldtype` and domain-object data providers.
 *
 * Defines both runtime JSON serialization (`transform()`) and static PHP/TypeScript
 * type generation (`phpType()`, `tsType()`, `tsDefinitions()`).
 */
abstract class FieldDataProvider extends Wire
{
	private ?ProviderRegistry $registry = null;

	public function setRegistry(ProviderRegistry $registry): void
	{
		$this->registry = $registry;
	}

	protected function registry(): ?ProviderRegistry
	{
		if ($this->registry !== null) {
			return $this->registry;
		}

		$inertia = $this->wire()->inertia;

		return $inertia instanceof Inertia ? $inertia->providers() : null;
	}

	/**
	 * Return supported `Fieldtype` class names (`'FieldtypeImage'`), field keys (`'field:summary'`),
	 * domain class names, or `'*'` for the default scalar fallback.
	 *
	 * @return list<string>
	 */
	abstract public function supports(): array;

	/**
	 * Transform a field or domain value into a JSON-serializable representation, independent of `$page->of()`.
	 */
	abstract public function transform(mixed $value, ?Field $field = null, ?Page $page = null): mixed;

	/**
	 * Return the PHP `@property` type annotation for Custom Page Classes (e.g. `'Pageimage|null'`).
	 */
	public function phpType(Field $field): string
	{
		return 'mixed';
	}

	/**
	 * Return the TypeScript property type for `inertia.d.ts` (e.g. `'InertiaImage | null'`).
	 */
	public function tsType(Field $field): string
	{
		return 'unknown';
	}

	/**
	 * Return auxiliary TypeScript `export interface ...` declarations keyed by interface name.
	 *
	 * @return array<string, string>
	 */
	public function tsDefinitions(?Field $field = null): array
	{
		return [];
	}
}
