<?php

declare(strict_types=1);

namespace Totoglu\Inertia\Support;

use ProcessWire\Inertia;
use ProcessWire\Inputfield;
use ProcessWire\InputfieldWrapper;
use ProcessWire\Wire;
use Throwable;

/**
 * Manages validation error flashing in `$session` and formats `props.errors`
 * (including named error bags via `X-Inertia-Error-Bag`).
 */
final class ErrorBag extends Wire
{
	public const string SESSION_NAMESPACE = Inertia::SESSION_NAMESPACE;
	public const string SESSION_KEY = 'errors';

	/** @var array<string, mixed> In-memory errors queued during current request */
	private array $runtimeErrors = [];

	/**
	 * Normalize errors from an associative array or a ProcessWire `InputfieldWrapper`
	 * and store them in both memory and session flash storage.
	 *
	 * @param array<string, mixed>|InputfieldWrapper $errors
	 */
	public function flash(array|InputfieldWrapper $errors, ?string $bag = null): self
	{
		$normalized = $errors instanceof InputfieldWrapper
			? self::extractFromInputfieldWrapper($errors)
			: self::normalizeArray($errors);

		if ($normalized === []) {
			return $this;
		}

		$targetBag = $bag !== null && trim($bag) !== '' ? trim($bag) : null;

		if ($targetBag !== null) {
			$existing = is_array($this->runtimeErrors[$targetBag] ?? null) ? $this->runtimeErrors[$targetBag] : [];
			$this->runtimeErrors[$targetBag] = array_replace_recursive($existing, $normalized);
		} else {
			$this->runtimeErrors = array_replace_recursive($this->runtimeErrors, $normalized);
		}

		$this->persistToSession();

		return $this;
	}

	/**
	 * Resolve and consume flashed validation errors for the current response.
	 *
	 * If `$headerErrorBag` (`X-Inertia-Error-Bag`) is provided and errors are flat,
	 * wraps them under `$headerErrorBag`. Always returns an associative array or
	 * `stdClass` (when empty) so JSON serialization produces `{}` instead of `[]`.
	 */
	public function resolve(?string $headerErrorBag = null): object|array
	{
		$errors = $this->pullFromSession();

		if ($this->runtimeErrors !== []) {
			$errors = array_replace_recursive($errors, $this->runtimeErrors);
			$this->runtimeErrors = [];
		}

		if ($errors === []) {
			return (object) [];
		}

		$bag = $headerErrorBag !== null ? trim($headerErrorBag) : '';
		if ($bag !== '' && !isset($errors[$bag]) && self::isFlatErrorMap($errors)) {
			return [
				$bag => $errors,
			];
		}

		return $errors;
	}

	/**
	 * Clear all queued and session-flashed Inertia errors.
	 */
	public function clear(): void
	{
		$this->runtimeErrors = [];
		$this->setSessionValue(self::SESSION_KEY, null);
	}

	/**
	 * Read (and optionally consume) a value from the `inertia` session namespace.
	 */
	public function getSessionValue(string $key, bool $consume = false): mixed
	{
		$wire = $this->wire();
		try {
			$session = $wire->session;
			$val = $session->getFor(self::SESSION_NAMESPACE, $key);
			if ($consume && $val !== null && $val !== false && $val !== []) {
				$session->removeFor(self::SESSION_NAMESPACE, $key);
			}

			return $val;
		} catch (Throwable $e) {
			$wire->log->error(sprintf($this->_('Inertia session read (%1$s) failed: %2$s'), $key, $e->getMessage()));

			return null;
		}
	}

	/**
	 * Write (or remove when `$value === null`) a value in the `inertia` session namespace.
	 */
	public function setSessionValue(string $key, mixed $value): void
	{
		$wire = $this->wire();
		try {
			$session = $wire->session;
			if ($value === null) {
				$session->removeFor(self::SESSION_NAMESPACE, $key);
			} else {
				$session->setFor(self::SESSION_NAMESPACE, $key, $value);
			}
		} catch (Throwable $e) {
			$wire->log->error(sprintf($this->_('Inertia session write (%1$s) failed: %2$s'), $key, $e->getMessage()));
		}
	}

	/**
	 * Extract field-keyed validation errors from a ProcessWire InputfieldWrapper form.
	 *
	 * @return array<string, string>
	 */
	private static function extractFromInputfieldWrapper(InputfieldWrapper $form): array
	{
		$result = [];

		foreach ($form->getAll() as $inputfield) {
			if (!$inputfield instanceof Inputfield) {
				continue;
			}

			$fieldErrors = $inputfield->getErrors(true);
			if (is_array($fieldErrors) && $fieldErrors !== []) {
				$attrName = (string) $inputfield->attr('name');
				$name = $attrName !== '' ? $attrName : (string) $inputfield->name;
				if ($name !== '') {
					$result[$name] = (string) reset($fieldErrors);
				}
			}
		}

		if ($result === []) {
			$formErrors = $form->getErrors(true);
			if (is_array($formErrors) && $formErrors !== []) {
				$result['form'] = (string) reset($formErrors);
			}
		}

		return $result;
	}

	/**
	 * Normalize an error map: if a field maps to an array of strings (e.g. `['email' => ['Invalid email']]`),
	 * flatten single-string lists or preserve nested error bags.
	 *
	 * @param array<string, mixed> $errors
	 * @return array<string, mixed>
	 */
	private static function normalizeArray(array $errors): array
	{
		$normalized = [];

		foreach ($errors as $key => $value) {
			if (!is_string($key) || $key === '') {
				continue;
			}

			if (is_scalar($value)) {
				$normalized[$key] = (string) $value;
				continue;
			}

			if (is_array($value) && $value !== []) {
				if (array_is_list($value)) {
					$first = reset($value);
					if (is_scalar($first)) {
						$normalized[$key] = (string) $first;
					}
				} else {
					$nested = self::normalizeArray($value);
					if ($nested !== []) {
						$normalized[$key] = $nested;
					}
				}
			}
		}

		return $normalized;
	}

	/**
	 * @param array<string, mixed> $errors
	 */
	private static function isFlatErrorMap(array $errors): bool
	{
		foreach ($errors as $value) {
			if (is_array($value)) {
				return false;
			}
		}

		return true;
	}

	private function persistToSession(): void
	{
		$existing = $this->getSessionValue(self::SESSION_KEY, false);
		$merged = is_array($existing)
			? array_replace_recursive($existing, $this->runtimeErrors)
			: $this->runtimeErrors;
		$this->setSessionValue(self::SESSION_KEY, $merged);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function pullFromSession(): array
	{
		$stored = $this->getSessionValue(self::SESSION_KEY, true);

		return is_array($stored) ? $stored : [];
	}
}
