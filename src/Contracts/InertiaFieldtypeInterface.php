<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Contracts;

use ProcessWire\Field;
use ProcessWire\Page;

/**
 * Implemented by custom ProcessWire `Fieldtype` modules that define their own
 * Inertia runtime serialization, PHPDoc `@property` type, and TypeScript `.d.ts` type.
 */
interface InertiaFieldtypeInterface
{
	/**
	 * Transform a formatted or unformatted field value into its JSON-serializable Inertia prop representation.
	 */
	public function toInertiaFieldValue(mixed $value, Field $field, ?Page $page = null): mixed;

	/**
	 * Return the PHP type annotation used in generated Custom Page Class `@property` docblocks.
	 */
	public function toInertiaPhpType(Field $field): string;

	/**
	 * Return the TypeScript type expression used in generated `inertia.d.ts` interfaces.
	 */
	public function toInertiaTsType(Field $field): string;

	/**
	 * Return optional auxiliary TypeScript interface/type blocks (`['InterfaceName' => 'export interface ...']`).
	 *
	 * @return array<string, string>
	 */
	public function toInertiaTsDefinitions(?Field $field = null): array;
}
