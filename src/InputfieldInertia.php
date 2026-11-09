<?php

declare(strict_types=1);

namespace Totoglu\Inertia;

use ProcessWire\Inputfield;
use ProcessWire\WireInputData;
use Totoglu\Inertia\Contracts\InertiaSerializable;

/**
 * Base ProcessWire `Inputfield` class for Inertia form controls.
 *
 * Serializes field metadata, value, and errors via `toInertiaProp()` when passed as an Inertia prop,
 * or renders a synchronized hidden `<input>` and `<div class="pw-inertia-inputfield-island" data-inertia-island="...">`
 * mount node inside classic `InputfieldForm` / `ProcessPageEdit` contexts.
 *
 * @method string render()
 * @method string renderValue()
 * @method self processInput(WireInputData $input)
 */
abstract class InputfieldInertia extends Inputfield implements InertiaSerializable
{
	use InteractWithInertia;

	/**
	 * Return the frontend component identifier (defaults to class name without `Inputfield` prefix).
	 */
	public function inertiaComponent(): string
	{
		$className = $this->className();
		$stripped = preg_replace('/^Inputfield(?=[A-Z])/', '', $className);

		return is_string($stripped) && $stripped !== '' ? $stripped : $className;
	}

	/**
	 * Whether `___processInput()` should automatically decode JSON object/array strings from the hidden input.
	 */
	protected function decodeJsonInput(): bool
	{
		return true;
	}

	protected function serializeValueForProps(mixed $value): mixed
	{
		return $value;
	}

	protected function serializeValueForHiddenInput(mixed $value): string
	{
		$normalized = $this->serializeValueForProps($value);
		if ($normalized === null || $normalized === '') {
			return '';
		}
		if (is_scalar($normalized)) {
			return (string) $normalized;
		}

		$encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return is_string($encoded) ? $encoded : '';
	}

	protected function sanitizeInertiaInput(mixed $raw): mixed
	{
		if (is_string($raw) && $this->decodeJsonInput()) {
			$trimmed = trim($raw);
			if (
				strlen($trimmed) <= Request::MAX_JSON_BYTES
				&& (
					(str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}'))
					|| (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))
				)
			) {
				$decoded = json_decode($trimmed, true, Request::MAX_JSON_DEPTH);
				if (json_last_error() === JSON_ERROR_NONE) {
					return $decoded;
				}
			}
		}

		return $raw;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toInertiaProp(): array
	{
		return [
			'component' => $this->inertiaComponent(),
			'id' => (string) $this->attr('id'),
			'name' => (string) $this->attr('name'),
			'label' => (string) $this->label,
			'description' => (string) $this->description,
			'notes' => (string) $this->notes,
			'icon' => (string) $this->icon,
			'value' => $this->serializeValueForProps($this->attr('value')),
			'required' => (bool) $this->required,
			'collapsed' => (int) $this->collapsed,
			'columnWidth' => (int) ($this->columnWidth ?: 100),
			'showIf' => (string) $this->showIf,
			'requiredIf' => (string) $this->requiredIf,
			'errors' => array_values(array_map('strval', $this->getErrors())),
			'props' => $this->getInertiaProps(),
		];
	}

	public function renderReady(?Inputfield $parent = null, $renderValueMode = false): bool
	{
		$result = (bool) parent::renderReady($parent, (bool) $renderValueMode);

		return $this->enqueueInertiaIslandAssets() || $result;
	}

	public function ___render(): string
	{
		$payload = $this->inertia()->resolver->resolveValue($this->toInertiaProp());
		$data = is_array($payload) ? $payload : $this->toInertiaProp();

		$id = (string) ($this->attr('id') ?: ($this->attr('name') ?: 'inertia_input'));
		$name = (string) $this->attr('name');
		$component = is_string($data['component'] ?? null) ? $data['component'] : $this->inertiaComponent();
		$hiddenVal = $this->serializeValueForHiddenInput($this->attr('value'));

		$safeId = $this->encodeHtmlAttribute($id);
		$safeName = $this->encodeHtmlAttribute($name);
		$safeVal = $this->encodeHtmlAttribute($hiddenVal);
		$safeComponent = $this->encodeHtmlAttribute($component);

		$jsonProps = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$safeProps = $this->encodeHtmlAttribute(is_string($jsonProps) ? $jsonProps : '{}');

		$out = '';
		if ($name !== '') {
			$out .= "<input type=\"hidden\" id=\"{$safeId}\" name=\"{$safeName}\" value=\"{$safeVal}\" data-inertia-input=\"{$safeName}\" />";
		}
		$nameAttr = $name !== '' ? " data-input-name=\"{$safeName}\"" : '';
		$out .= "<div class=\"pw-inertia-inputfield-island\" data-inertia-island=\"{$safeComponent}\" data-input-id=\"{$safeId}\"{$nameAttr} data-props=\"{$safeProps}\"></div>";

		return $out;
	}

	public function ___renderValue(): string
	{
		$payload = $this->inertia()->resolver->resolveValue($this->toInertiaProp());
		$data = is_array($payload) ? $payload : $this->toInertiaProp();
		$data['readOnly'] = true;

		$component = is_string($data['component'] ?? null) ? $data['component'] : $this->inertiaComponent();
		$safeComponent = $this->encodeHtmlAttribute($component);

		$jsonProps = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$safeProps = $this->encodeHtmlAttribute(is_string($jsonProps) ? $jsonProps : '{}');

		return "<div class=\"pw-inertia-inputfield-island\" data-inertia-island=\"{$safeComponent}\" data-readonly=\"true\" data-props=\"{$safeProps}\"></div>";
	}

	/**
	 * Replace `value` directly so array<->scalar transitions do not merge with previous array values.
	 *
	 * @param string|array<string, mixed> $key
	 * @param mixed $value
	 */
	public function setAttribute($key, $value)
	{
		if ($key === 'value') {
			$this->attributes['value'] = $value;

			return $this;
		}

		return parent::setAttribute($key, $value);
	}

	public function ___processInput(WireInputData $input): self
	{
		$name = (string) $this->attr('name');
		if ($name === '' || !isset($input->$name)) {
			return $this;
		}

		$sanitized = $this->sanitizeInertiaInput($input->$name);
		$current = $this->attr('value');

		if ($sanitized !== $current) {
			if (!$this->trackChanges()) {
				$this->setTrackChanges(true);
			}
			$this->setAttribute('value', $sanitized);
			$this->trackChange('value');
		}

		return $this;
	}
}
