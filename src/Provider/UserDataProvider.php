<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Provider;

use ProcessWire\Field;
use ProcessWire\Fieldgroup;
use ProcessWire\Role;
use ProcessWire\Template;
use ProcessWire\User;
use ProcessWire\Wire;

/**
 * Default User Data Provider for ProcessWire `User` instances.
 *
 * Safely serializes `id`, `name`, `email`, `roles`, `isLoggedin`, `isSuperuser`,
 * and any non-system custom fields attached to the `user` template while strictly
 * excluding credentials (`pass`) and internal admin fields.
 */
class UserDataProvider extends Wire
{
	/**
	 * System fields on the `user` template that are either handled explicitly or must never be serialized.
	 *
	 * @var list<string>
	 */
	public const array SYSTEM_USER_FIELDS = [
		'pass',
		'roles',
		'admin_theme',
		'language',
		'email',
		'title',
		'tfa_type',
	];

	/**
	 * @return array<string, mixed>
	 */
	public function provide(User $user, ProviderRegistry $registry): array
	{
		$roles = [];
		$rolesField = $user->get('roles');
		if (is_iterable($rolesField)) {
			foreach ($rolesField as $role) {
				if ($role instanceof Role && $role->name !== '') {
					$roles[] = (string) $role->name;
				}
			}
		}

		$data = [
			'id' => (int) $user->id,
			'name' => (string) $user->name,
			'email' => (string) $user->get('email'),
			'roles' => array_values(array_unique($roles)),
			'isLoggedin' => $user->isLoggedin(),
			'isSuperuser' => $user->isSuperuser(),
		];

		foreach ($this->customFields($user->template) as $name => $field) {
			$data[$name] = $registry->transformField($field, $user);
		}

		$extra = $this->extraData($user);
		if ($extra !== []) {
			$data = array_merge($data, $extra);
		}

		return $data;
	}

	/**
	 * Override in custom `site/inertia/UserDataProvider.php` subclasses to append custom user properties.
	 *
	 * @return array<string, mixed>
	 */
	protected function extraData(User $user): array
	{
		return [];
	}

	/**
	 * Override in custom `site/inertia/UserDataProvider.php` subclasses to exclude additional user fields.
	 *
	 * @return list<string>
	 */
	protected function excludedFields(): array
	{
		return [];
	}

	/**
	 * Override in custom `site/inertia/UserDataProvider.php` subclasses to declare TypeScript types for `extraData()`.
	 *
	 * @return array<string, string>
	 */
	public function extraTsTypes(): array
	{
		return [];
	}

	public function tsDefinition(ProviderRegistry $registry): string
	{
		$lines = [
			"\tid: number;",
			"\tname: string;",
			"\temail: string;",
			"\troles: string[];",
			"\tisLoggedin: boolean;",
			"\tisSuperuser: boolean;",
		];

		$userTpl = $this->wire()->templates->get('user');
		foreach ($this->customFields($userTpl instanceof Template ? $userTpl : null) as $name => $field) {
			$tsType = $registry->resolveFieldProvider($field)->tsType($field);
			$safeKey = ProviderRegistry::formatTsKey($name);
			$lines[] = "\t{$safeKey}?: {$tsType};";
		}

		foreach ($this->extraTsTypes() as $prop => $tsType) {
			$safeKey = ProviderRegistry::formatTsKey($prop);
			$lines[] = "\t{$safeKey}: {$tsType};";
		}

		$body = implode("\n", $lines);

		return <<<TS
export interface InertiaUser {
{$body}
}
TS;
	}

	/**
	 * Collect auxiliary TypeScript interface definitions required by custom fields on the `user` template.
	 *
	 * @return array<string, string>
	 */
	public function tsDefinitions(ProviderRegistry $registry): array
	{
		$definitions = [];
		$userTpl = $this->wire()->templates->get('user');
		foreach ($this->customFields($userTpl instanceof Template ? $userTpl : null) as $field) {
			foreach ($registry->resolveFieldProvider($field)->tsDefinitions($field) as $name => $def) {
				$definitions[$name] = $def;
			}
		}

		return $definitions;
	}

	/**
	 * Return eligible non-system custom fields on the `user` template.
	 *
	 * @return array<string, Field>
	 */
	private function customFields(?Template $template): array
	{
		$fieldgroup = $template?->fieldgroup;
		if (!$fieldgroup instanceof Fieldgroup) {
			return [];
		}

		$excluded = array_fill_keys(array_merge(self::SYSTEM_USER_FIELDS, $this->excludedFields()), true);
		$fields = [];

		foreach ($fieldgroup as $field) {
			if (!$field instanceof Field) {
				continue;
			}
			$name = trim((string) $field->name);
			if ($name === '' || isset($excluded[$name]) || self::isSensitiveFieldName($name) || TemplateDataProvider::isUiFieldset($field)) {
				continue;
			}
			if (($field->flags & Field::flagSystem) !== 0) {
				continue;
			}
			$fields[$name] = $field;
		}

		return $fields;
	}

	private static function isSensitiveFieldName(string $name): bool
	{
		return preg_match('/^(?:.*_(?:secret|token|password|hash)|secret|token|password|api_key)$/i', $name) === 1;
	}
}
