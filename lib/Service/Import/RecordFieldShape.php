<?php

/**
 * Dossiq record field shape.
 *
 * A raw value of a source record in the shape its field declaration asks
 * for: JSON, a boolean, a date, a moment, an integer, a number, a value from
 * a declared list, or text with its whitespace collapsed and cut at a word.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Import;

use DateTimeInterface;
use InvalidArgumentException;
use OCA\Dossiq\Service\CaseDateNormaliser;

/**
 * One declared field's value, shaped; reads no store.
 *
 * @spec openspec/specs/case-record-import/spec.md#requirement-a-declaration-reads-nested-typed-source-values-and-answers-the-case-types-fields-req-cri-005
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class RecordFieldShape {

	/**
	 * The words `as: boolean` reads as true.
	 */
	private const TRUE_WORDS = ['1', 'true', 'yes'];

	/**
	 * Constructor.
	 *
	 * @param CaseDateNormaliser $dates  The one reader of a date and the tenant's time zone.
	 * @param RecordValues       $values Lists, numbers and answers as the case stores them.
	 */
	public function __construct(
		private readonly CaseDateNormaliser $dates,
		private readonly RecordValues $values = new RecordValues(),
	) {
	}//end __construct()

	/**
	 * A raw value in the shape its declaration asks for.
	 *
	 * @param array<string, mixed> $field The field declaration.
	 * @param mixed                $raw   The raw value.
	 *
	 * @return mixed The value, or null when empty or unreadable.
	 *
	 * @throws InvalidArgumentException When a value is outside its declared list.
	 *
	 * @spec openspec/specs/case-record-import/spec.md#requirement-a-declaration-reads-nested-typed-source-values-and-answers-the-case-types-fields-req-cri-005
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function shaped(array $field, mixed $raw): mixed {
		$shape = (string)($field['as'] ?? 'text');
		if ($shape === 'json') {
			return $this->values->json(raw: $raw);
		}

		if ($shape === 'boolean' && is_bool($raw) === true) {
			return $raw;
		}

		$text = $this->textOf(raw: $raw);

		if (isset($field['values']) === true) {
			return $this->listed(field: $field, text: $text);
		}

		return $this->typed(field: $field, text: $text);
	}//end shaped()

	/**
	 * A scalar raw value as text with its whitespace collapsed; '' for anything else.
	 *
	 * @param mixed $raw The raw value.
	 *
	 * @return string The text.
	 */
	private function textOf(mixed $raw): string {
		$text = '';
		if (is_scalar($raw) === true) {
			$text = trim((string)preg_replace('/\s+/u', ' ', (string)$raw));
		}

		return $text;
	}//end textOf()

	/**
	 * A text value in the shape its declaration asks for.
	 *
	 * @param array<string, mixed> $field The field declaration, with its shape (`as`, text when absent).
	 * @param string               $text  The raw value as text.
	 *
	 * @return mixed The value, or null when empty or unreadable.
	 */
	private function typed(array $field, string $text): mixed {
		if ($text === '') {
			return null;
		}

		return match ((string)($field['as'] ?? 'text')) {
			'date' => $this->dates->toCalendarDateOrNull(value: $text),
			'dateTime' => $this->dates->tryParse(value: $text)?->format(DateTimeInterface::ATOM),
			'integer' => (int)$text,
			'number' => $this->values->number(text: $text),
			'boolean' => in_array(strtolower($text), self::TRUE_WORDS, true),
			default => $this->cut(text: $text, length: (int)($field['maxLength'] ?? 0)),
		};
	}//end typed()

	/**
	 * A value read through its declared list.
	 *
	 * @param array<string, mixed> $field The field declaration, with `values` and an optional `default`.
	 * @param string               $text  The raw value as text.
	 *
	 * @return mixed The listed value, or the default for an empty one.
	 *
	 * @throws InvalidArgumentException When the value is not in the list.
	 */
	private function listed(array $field, string $text): mixed {
		$values = (array)$field['values'];
		if ($text === '') {
			return ($field['default'] ?? null);
		}

		if (array_key_exists($text, $values) === false) {
			throw new InvalidArgumentException(
				'The ' . (string)($field['from'] ?? '') . ' must be one of: ' . implode(', ', array_keys($values)) . '.'
			);
		}

		return $values[$text];
	}//end listed()

	/**
	 * Text cut to a length at the last word that fits.
	 *
	 * @param string $text   The text.
	 * @param int    $length The length, or 0 for no limit.
	 *
	 * @return string The text.
	 */
	private function cut(string $text, int $length): string {
		if ($length <= 0 || mb_strlen($text) <= $length) {
			return $text;
		}

		$head = mb_substr($text, 0, $length + 1);
		$space = mb_strrpos($head, ' ');
		if ($space === false || $space === 0) {
			return mb_substr($text, 0, $length);
		}

		return rtrim(mb_substr($head, 0, $space));
	}//end cut()
}//end class
