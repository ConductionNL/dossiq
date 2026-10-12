<?php

/**
 * Dossiq link marker rule.
 *
 * A marker on an object that another object references, derived from both of
 * them: "the earlier case's decision is still open to a follow-up while this
 * later record is in time" is one such marker. A rule names the source schema,
 * the field on the source that references the target, the target schema, the
 * marker field on the target and the conditions under which the marker holds.
 *
 * The condition list is closed on purpose. Each condition reads one field of
 * the source or the target and asks one of five questions: is it present, is
 * it one of these values, is it none of these values, is it a date not after
 * another field's date. A condition this class cannot evaluate is refused when
 * the rule is read, rather than silently never holding.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Relation
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
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Relation;

use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * A validated marker rule, and its evaluation.
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-a-marker-rule-derives-a-flag-on-the-referenced-object-req-lcr-4
 */
final class LinkMarkerRule {

	/**
	 * The questions a condition may ask.
	 *
	 * @var array<int, string>
	 */
	private const QUESTIONS = ['present', 'in', 'notIn', 'notAfter'];

	/**
	 * Config key of the source schema.
	 *
	 * @var string
	 */
	public readonly string $source;

	/**
	 * Source field that references the target.
	 *
	 * @var string
	 */
	public readonly string $field;

	/**
	 * Config key of the target schema.
	 *
	 * @var string
	 */
	public readonly string $target;

	/**
	 * Target field the marker is written to.
	 *
	 * @var string
	 */
	public readonly string $marker;

	/**
	 * The conditions, all of which must hold.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public readonly array $conditions;

	/**
	 * Build and validate a rule.
	 *
	 * @param mixed $config The stored rule.
	 *
	 * @throws InvalidArgumentException When the rule is not well formed.
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-a-marker-rule-derives-a-flag-on-the-referenced-object-req-lcr-4
	 */
	public function __construct(mixed $config) {
		if (is_array($config) === false) {
			throw new InvalidArgumentException('A marker rule must be an object.');
		}

		foreach (['source', 'field', 'target', 'marker'] as $key) {
			if (is_string($config[$key] ?? null) === false || trim($config[$key]) === '') {
				throw new InvalidArgumentException("A marker rule needs `$key`.");
			}
		}

		$conditions = ($config['when'] ?? []);
		if (is_array($conditions) === false) {
			throw new InvalidArgumentException("Marker rule '{$config['marker']}' has a `when` that is not a list.");
		}

		foreach ($conditions as $condition) {
			self::guardCondition(marker: $config['marker'], condition: $condition);
		}

		$this->source     = trim($config['source']);
		$this->field      = trim($config['field']);
		$this->target     = trim($config['target']);
		$this->marker     = trim($config['marker']);
		$this->conditions = array_values($conditions);
	}//end __construct()

	/**
	 * Whether the marker holds for this source and target.
	 *
	 * @param array<string, mixed> $source The referencing object.
	 * @param array<string, mixed> $target The referenced object.
	 *
	 * @return bool True when every condition holds.
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-a-marker-rule-derives-a-flag-on-the-referenced-object-req-lcr-4
	 */
	public function holds(array $source, array $target): bool {
		foreach ($this->conditions as $condition) {
			$subject = $source;
			if (($condition['on'] ?? 'source') === 'target') {
				$subject = $target;
			}

			if ($this->conditionHolds(subject: $subject, condition: $condition) === false) {
				return false;
			}
		}

		return true;
	}//end holds()

	/**
	 * Whether one condition holds on its subject.
	 *
	 * @param array<string, mixed> $subject   The object the condition reads.
	 * @param array<string, mixed> $condition The condition.
	 *
	 * @return bool True when it holds.
	 */
	private function conditionHolds(array $subject, array $condition): bool {
		$value = $this->scalar(value: ($subject[$condition['field']] ?? null));

		if (array_key_exists('present', $condition) === true) {
			return ($value !== '') === ($condition['present'] === true);
		}

		if (array_key_exists('in', $condition) === true) {
			return in_array($value, $condition['in'], true);
		}

		if (array_key_exists('notIn', $condition) === true) {
			return in_array($value, $condition['notIn'], true) === false;
		}

		return $this->notAfter(
			value: $value,
			limit: $this->scalar(value: ($subject[$condition['notAfter']] ?? null))
		);
	}//end conditionHolds()

	/**
	 * Whether a date is not after a limit date.
	 *
	 * A missing date does not hold. A missing or unreadable limit does: no
	 * limit set yet is not a limit passed.
	 *
	 * @param string $value The date.
	 * @param string $limit The limit.
	 *
	 * @return bool True when the date is on or before the limit.
	 */
	private function notAfter(string $value, string $limit): bool {
		if ($value === '') {
			return false;
		}

		if ($limit === '') {
			return true;
		}

		try {
			return new DateTimeImmutable($value) <= new DateTimeImmutable($limit);
		} catch (Throwable) {
			return true;
		}
	}//end notAfter()

	/**
	 * A field value as a comparable string; '' for absent, null, or a non-scalar.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The string.
	 */
	private function scalar(mixed $value): string {
		if (is_bool($value) === true) {
			return var_export($value, true);
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end scalar()

	/**
	 * Refuse a condition this class cannot evaluate.
	 *
	 * @param string $marker    The rule's marker, for the message.
	 * @param mixed  $condition The condition.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the condition is not one of the known questions.
	 */
	private static function guardCondition(string $marker, mixed $condition): void {
		if (is_array($condition) === false || is_string($condition['field'] ?? null) === false) {
			throw new InvalidArgumentException("Marker rule '$marker' has a condition without a `field`.");
		}

		$side = ($condition['on'] ?? 'source');
		if (in_array($side, ['source', 'target'], true) === false) {
			throw new InvalidArgumentException("Marker rule '$marker' reads `on` '$side'; use source or target.");
		}

		$asked = array_values(array_intersect(self::QUESTIONS, array_keys($condition)));
		if (count($asked) !== 1) {
			throw new InvalidArgumentException("Marker rule '$marker' needs exactly one of present, in, notIn, notAfter per condition.");
		}

		$question = $asked[0];
		$valid    = match ($question) {
			'present'     => is_bool($condition['present']),
			'in', 'notIn' => is_array($condition[$question]),
			default       => is_string($condition['notAfter']),
		};
		if ($valid === false) {
			throw new InvalidArgumentException("Marker rule '$marker' has a `$question` condition of the wrong type.");
		}
	}//end guardCondition()
}//end class
