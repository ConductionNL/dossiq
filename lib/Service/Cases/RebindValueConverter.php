<?php

/**
 * Dossiq rebind value converter.
 *
 * Whether one stored answer fits one field of the target case type, and what
 * it reads as once it lands there.
 *
 * Every answer on a case is stored as text (`case.properties[].value`), so a
 * rebind never moves a typed value: it moves a string onto a field that will
 * read it as a number, a date, a choice or a yes/no. That is safe only when
 * the string means the same thing on the other side. "120" fits a number
 * field; "groot" does not, and carrying it over would put a value on the case
 * that every lens reading the new type treats as broken.
 *
 * 🔴 A VALUE THAT DOES NOT FIT IS NOT CONVERTED BY FORCE. It is reported as
 * not fitting, and the impact lists the answer as dropped, where the
 * coordinator sees it and decides. A converter that "did its best" with
 * "groot" would turn a visible loss into an invisible corruption.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use DateTimeImmutable;

/**
 * Fit a stored answer onto a target property definition.
 *
 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
 */
class RebindValueConverter {

	/**
	 * Kinds the dialog can render a field for and this class can check.
	 *
	 * Everything else (files, geometry, objects, lists, the Nextcloud
	 * references) is `structured:<type>`, which carries over only onto the
	 * same kind, because no rule here can tell whether one shape means the
	 * same as another.
	 *
	 * @var array<string, string>
	 */
	private const SIMPLE_KINDS = [
		'string' => 'text',
		'color' => 'text',
		'number' => 'number',
		'integer' => 'integer',
		'boolean' => 'boolean',
		'date' => 'date',
		'email' => 'email',
		'url' => 'url',
	];

	/**
	 * What a string field holds, by its declared `format`.
	 *
	 * @var array<string, string>
	 */
	private const STRING_FORMATS = [
		'date' => 'date',
		'date-time' => 'date-time',
		'email' => 'email',
		'uri' => 'url',
		'url' => 'url',
	];

	/**
	 * The kind of answer a property definition holds.
	 *
	 * @param array<string, mixed> $definition The property definition, or [] for an undeclared answer.
	 *
	 * @return string One of text, number, integer, boolean, date, date-time, email, url, choice, or structured:<type>.
	 *
	 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
	 */
	public function kindOf(array $definition): string {
		if ($this->choicesOf(definition: $definition) !== []) {
			return 'choice';
		}

		$type = trim((string)($definition['propertyType'] ?? ''));
		if ($type === '' || $type === 'enum') {
			$type = 'string';
		}

		if ($type === 'string') {
			$format = trim((string)($definition['format'] ?? ''));

			return (self::STRING_FORMATS[$format] ?? 'text');
		}

		return (self::SIMPLE_KINDS[$type] ?? 'structured:' . $type);
	}//end kindOf()

	/**
	 * The answers a field accepts, when it declares a list.
	 *
	 * @param array<string, mixed> $definition The property definition.
	 *
	 * @return array<int, string> The choices, or [] when any answer of its kind will do.
	 *
	 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
	 */
	public function choicesOf(array $definition): array {
		$values = ($definition['enumValues'] ?? []);
		if (is_array($values) === false) {
			return [];
		}

		$choices = [];
		foreach ($values as $value) {
			if (is_scalar($value) === true && trim((string)$value) !== '') {
				$choices[] = (string)$value;
			}
		}

		return $choices;
	}//end choicesOf()

	/**
	 * Any answer as the text the case stores it as.
	 *
	 * @param mixed $value The answer as posted or stored.
	 *
	 * @return string The text, '' for nothing.
	 *
	 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
	 */
	public function asText(mixed $value): string {
		if ($value === null) {
			return '';
		}

		if (is_bool($value) === true) {
			return $value === true ? 'true' : 'false';
		}

		if (is_scalar($value) === true) {
			return trim((string)$value);
		}

		if ($value === []) {
			return '';
		}

		return (string)json_encode($value);
	}//end asText()

	/**
	 * The value this answer lands with on the target field, or null when it does not fit.
	 *
	 * @param string               $value  The stored answer, as text.
	 * @param array<string, mixed> $source The definition it was answered under, or [] for a fresh answer.
	 * @param array<string, mixed> $target The target's definition.
	 *
	 * @return string|null The value on the target, or null when it does not fit.
	 *
	 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
	 */
	public function fit(string $value, array $source, array $target): ?string {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		$targetKind = $this->kindOf(definition: $target);
		if ($targetKind === 'choice') {
			return $this->fitChoice(value: $value, choices: $this->choicesOf(definition: $target));
		}

		$sourceKind = 'text';
		if ($source !== []) {
			$sourceKind = $this->kindOf(definition: $source);
		}

		if (str_starts_with($targetKind, 'structured:') === true || str_starts_with($sourceKind, 'structured:') === true) {
			return $this->fitStructured(value: $value, source: $source, sourceKind: $sourceKind, targetKind: $targetKind);
		}

		return $this->fitSimple(value: $value, kind: $targetKind);
	}//end fit()

	/**
	 * A value onto a field that lists its answers.
	 *
	 * @param string             $value   The answer.
	 * @param array<int, string> $choices The answers the field accepts.
	 *
	 * @return string|null The answer, or null when it is not one of them.
	 */
	private function fitChoice(string $value, array $choices): ?string {
		if (in_array($value, $choices, true) === true) {
			return $value;
		}

		return null;
	}//end fitChoice()

	/**
	 * A value whose source or target holds a shape rather than a word.
	 *
	 * A fresh answer (no source definition) is accepted as given, because the
	 * register validates the shape on write; a stored one carries over only
	 * onto the same kind.
	 *
	 * @param string               $value      The answer.
	 * @param array<string, mixed> $source     Its definition, or [].
	 * @param string               $sourceKind Its kind.
	 * @param string               $targetKind The target's kind.
	 *
	 * @return string|null The value, or null.
	 */
	private function fitStructured(string $value, array $source, string $sourceKind, string $targetKind): ?string {
		if ($source === [] || $sourceKind === $targetKind) {
			return $value;
		}

		return null;
	}//end fitStructured()

	/**
	 * A value onto a field of one of the simple kinds.
	 *
	 * @param string $value The answer.
	 * @param string $kind  The target's kind.
	 *
	 * @return string|null The value as the target reads it, or null.
	 */
	private function fitSimple(string $value, string $kind): ?string {
		return match ($kind) {
			'number' => $this->fitNumber(value: $value),
			'integer' => $this->fitInteger(value: $value),
			'boolean' => $this->fitBoolean(value: $value),
			'date' => $this->fitDate(value: $value),
			'date-time' => $this->fitDateTime(value: $value),
			'email' => $this->fitFiltered(value: $value, filter: FILTER_VALIDATE_EMAIL),
			'url' => $this->fitFiltered(value: $value, filter: FILTER_VALIDATE_URL),
			default => $value,
		};
	}//end fitSimple()

	/**
	 * A number, written the way it was written.
	 *
	 * @param string $value The answer.
	 *
	 * @return string|null The value, or null.
	 */
	private function fitNumber(string $value): ?string {
		if (is_numeric($value) === true) {
			return $value;
		}

		return null;
	}//end fitNumber()

	/**
	 * A whole number.
	 *
	 * @param string $value The answer.
	 *
	 * @return string|null The value, or null.
	 */
	private function fitInteger(string $value): ?string {
		if (preg_match('/^-?\d+$/', $value) === 1) {
			return $value;
		}

		return null;
	}//end fitInteger()

	/**
	 * Yes or no, in the words a stored answer uses for them.
	 *
	 * @param string $value The answer.
	 *
	 * @return string|null 'true', 'false', or null.
	 */
	private function fitBoolean(string $value): ?string {
		$key = mb_strtolower($value);
		if (in_array($key, ['true', '1', 'yes', 'ja'], true) === true) {
			return 'true';
		}

		if (in_array($key, ['false', '0', 'no', 'nee'], true) === true) {
			return 'false';
		}

		return null;
	}//end fitBoolean()

	/**
	 * A calendar date, from a date or the date part of a timestamp.
	 *
	 * @param string $value The answer.
	 *
	 * @return string|null The date as Y-m-d, or null.
	 */
	private function fitDate(string $value): ?string {
		if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:$|T)/', $value, $match) !== 1) {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $match[1]);
		if ($date === false || $date->format('Y-m-d') !== $match[1]) {
			return null;
		}

		return $match[1];
	}//end fitDate()

	/**
	 * A timestamp, or a date read as its start.
	 *
	 * @param string $value The answer.
	 *
	 * @return string|null The value, or null.
	 */
	private function fitDateTime(string $value): ?string {
		if ($this->fitDate(value: $value) === null) {
			return null;
		}

		if (strtotime($value) === false) {
			return null;
		}

		return $value;
	}//end fitDateTime()

	/**
	 * A value PHP's own filter accepts.
	 *
	 * @param string  $value  The answer.
	 * @param integer $filter The FILTER_VALIDATE_* constant.
	 *
	 * @return string|null The value, or null.
	 */
	private function fitFiltered(string $value, int $filter): ?string {
		if (filter_var($value, $filter) === false) {
			return null;
		}

		return $value;
	}//end fitFiltered()
}//end class
