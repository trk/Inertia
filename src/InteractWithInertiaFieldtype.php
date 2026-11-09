<?php

declare(strict_types=1);

namespace Totoglu\Inertia;

use ProcessWire\Field;
use ProcessWire\Page;

/**
 * Default implementation of `InertiaFieldtypeInterface` for ProcessWire `Fieldtype` modules.
 */
trait InteractWithInertiaFieldtype
{
	/**
	 * Transform a field value into its JSON-serializable Inertia prop representation.
	 */
	public function toInertiaFieldValue(mixed $value, Field $field, ?Page $page = null): mixed
	{
		return $value;
	}

	/**
	 * Return the PHP type annotation used in generated Custom Page Class `@property` docblocks.
	 */
	public function toInertiaPhpType(Field $field): string
	{
		return 'mixed';
	}

	/**
	 * Return the TypeScript type expression used in generated `inertia.d.ts` interfaces.
	 */
	public function toInertiaTsType(Field $field): string
	{
		return 'unknown';
	}

	/**
	 * Return optional auxiliary TypeScript interface/type blocks (`['InterfaceName' => 'export interface ...']`).
	 *
	 * @return array<string, string>
	 */
	public function toInertiaTsDefinitions(?Field $field = null): array
	{
		return [];
	}
}
