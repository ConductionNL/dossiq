<?php

/**
 * Convert one dossiq sentry, its on-part and its if-part, through the closed conversion tables.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use UnexpectedValueException;

/**
 * The sentry half of the caseModel conversion: pure, no I/O.
 *
 * Every branch is one shape the conversion declines to guess at, each with its
 * own message naming the construct, because design.md section 2 of
 * retire-cmmn-caseplanstate calls the conversion tables closed. Moved out of
 * {@see CasePlanProjectionService} unchanged (method-decomposition).
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */
final class SentryConverter {
	/**
	 * The closed if-part operator table (design.md section 2).
	 *
	 * Closed on purpose. An operator outside it is refused by name rather
	 * than approximated, because an approximated sentry fires at the wrong
	 * moment and nothing reports it.
	 *
	 * @var array<string, string>
	 */
	public const OPERATORS = [
		'eq' => '==',
		'neq' => '!=',
		'gt' => '>',
		'gte' => '>=',
		'lt' => '<',
		'lte' => '<=',
		'in' => 'in',
		'notIn' => 'notIn',
		'truthy' => '!!',
		'falsy' => '!',
	];
	/**
	 * dossiq `onPart.standardEvent` to the OpenRegister flow event catalog.
	 *
	 * @var array<string, string>
	 */
	public const STANDARD_EVENTS = [
		'complete' => 'case.item.completed',
		'terminate' => 'case.item.terminated',
		'disable' => 'case.item.disabled',
	];
	/**
	 * The catalog event an ordinary write to the case object dispatches.
	 *
	 * A dossiq `onPart.caseFileItem` watched a named slot inside the blob.
	 * OpenRegister has no case file: the slots become declared properties on
	 * the case object, and a write to them is an object write. Both
	 * `caseFileEvent` values map here, because OpenRegister does not separate
	 * a first write from a later one; the fidelity note is in the class docs
	 * of the change's design.md section 2.
	 *
	 * @var string
	 */
	public const OBJECT_WRITE_EVENT = 'object.updated';

	/**
	 * Convert one dossiq sentry into an OpenRegister sentry.
	 *
	 * @param array<string, mixed> $sentry The dossiq `{id?, onPart?, ifPart?}` sentry.
	 * @param string               $where  Which criteria list, for the refusal message.
	 *
	 * @return array<string, mixed> The OpenRegister `{id?, on?, if?}` sentry.
	 *
	 * @throws UnexpectedValueException Naming the part it refuses.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function convertSentry(array $sentry, string $where): array {
		$converted = [];
		$id = trim((string)($sentry['id'] ?? ''));
		if ($id !== '') {
			$converted['id'] = $id;
		}

		$parts = $this->convertSentryParts(sentry: $sentry, where: $where);
		if ($parts === []) {
			throw new UnexpectedValueException(sprintf('%s has neither an onPart nor an ifPart; there is nothing to convert.', $where));
		}

		return array_merge($converted, $parts);
	}//end convertSentry()

	/**
	 * The converted on- and if-part of a sentry, each only when declared as a non-empty object.
	 *
	 * @param array<string, mixed> $sentry The dossiq sentry.
	 * @param string               $where  Which criteria list, for the refusal message.
	 *
	 * @return array<string, mixed> `{on?, if?}`.
	 *
	 * @throws UnexpectedValueException Naming the part it refuses.
	 */
	private function convertSentryParts(array $sentry, string $where): array {
		$parts = [];
		$onPart = ($sentry['onPart'] ?? null);
		if (is_array($onPart) === true && $onPart !== []) {
			$parts['on'] = $this->convertOnPart(onPart: $onPart, where: $where);
		}

		$ifPart = ($sentry['ifPart'] ?? null);
		if (is_array($ifPart) === true && $ifPart !== []) {
			$parts['if'] = $this->convertIfPart(ifPart: $ifPart, where: $where);
		}

		return $parts;
	}//end convertSentryParts()

	/**
	 * Convert a dossiq `{field, operator, value}` if-part into JSONLogic.
	 *
	 * The table is closed (design.md section 2). An operator it does not name
	 * is refused, never guessed: `FlowExpression::isTrue()` answers false for
	 * an expression it cannot evaluate, so a mistranslated condition would
	 * look exactly like a sentry that has not fired yet.
	 *
	 * @param array<string, mixed> $ifPart The dossiq if-part.
	 * @param string               $where  Which sentry, for the refusal message.
	 *
	 * @return array<string, mixed> The JSONLogic rule.
	 *
	 * @throws UnexpectedValueException Naming the operator or the missing field.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function convertIfPart(array $ifPart, string $where): array {
		$field = trim((string)($ifPart['field'] ?? ''));
		if ($field === '') {
			throw new UnexpectedValueException(sprintf('%s has an ifPart with no field.', $where));
		}

		$operator = (string)($ifPart['operator'] ?? 'eq');
		if (array_key_exists($operator, self::OPERATORS) === false) {
			throw new UnexpectedValueException(
				sprintf(
					"%s uses operator '%s', which is outside the conversion table (%s).",
					$where,
					$operator,
					implode(', ', array_keys(self::OPERATORS))
				)
			);
		}

		$value = ($ifPart['value'] ?? null);
		$variable = ['var' => $field];

		return match ($operator) {
			'truthy' => ['!!' => $variable],
			'falsy' => ['!' => $variable],
			'in' => ['in' => [$variable, $value]],
			'notIn' => ['!' => ['in' => [$variable, $value]]],
			default => [self::OPERATORS[$operator] => [$variable, $value]],
		};
	}//end convertIfPart()

	/**
	 * Convert a criteria list, dropping nothing and refusing what it cannot map.
	 *
	 * @param mixed  $criteria The dossiq criteria list.
	 * @param string $where    Which list, for the refusal message.
	 *
	 * @return array<int, array<string, mixed>> The converted sentries.
	 *
	 * @throws UnexpectedValueException Naming the sentry it refuses.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function convertCriteria(mixed $criteria, string $where): array {
		if (is_array($criteria) === false || $criteria === []) {
			return [];
		}

		$converted = [];
		foreach ($criteria as $index => $sentry) {
			if (is_array($sentry) === false) {
				throw new UnexpectedValueException(sprintf('%s sentry #%d is not an object.', $where, (int)$index + 1));
			}

			$converted[] = $this->convertSentry(sentry: $sentry, where: sprintf('%s sentry #%d', $where, (int)$index + 1));
		}

		return $converted;
	}//end convertCriteria()

	/**
	 * Convert a dossiq on-part into an OpenRegister on-part.
	 *
	 * @param array<string, mixed> $onPart The dossiq on-part.
	 * @param string               $where  Which sentry, for the refusal message.
	 *
	 * @return array<string, mixed> The OpenRegister on-part.
	 *
	 * @throws UnexpectedValueException Naming the event it refuses.
	 */
	private function convertOnPart(array $onPart, string $where): array {
		$planItem = trim((string)($onPart['planItem'] ?? ''));
		if ($planItem !== '') {
			return $this->convertPlanItemOnPart(onPart: $onPart, where: $where);
		}

		$caseFileItem = trim((string)($onPart['caseFileItem'] ?? ''));
		if ($caseFileItem !== '') {
			return $this->convertCaseFileOnPart(onPart: $onPart, where: $where);
		}

		throw new UnexpectedValueException(sprintf('%s has an onPart naming neither a planItem nor a caseFileItem.', $where));
	}//end convertOnPart()

	/**
	 * Convert a dossiq on-part on a plan item into an OpenRegister on-part.
	 *
	 * The plan item it listens to is the on-part's trimmed `planItem`.
	 *
	 * @param array<string, mixed> $onPart The dossiq on-part.
	 * @param string               $where  Which sentry, for the refusal message.
	 *
	 * @return array<string, mixed> The OpenRegister on-part.
	 *
	 * @throws UnexpectedValueException Naming the event it refuses.
	 */
	private function convertPlanItemOnPart(array $onPart, string $where): array {
		$planItem = trim((string)($onPart['planItem'] ?? ''));
		$standardEvent = (string)($onPart['standardEvent'] ?? '');
		if (array_key_exists($standardEvent, self::STANDARD_EVENTS) === false) {
			throw new UnexpectedValueException(
				sprintf(
					"%s names standardEvent '%s', which is outside the conversion table (%s).",
					$where,
					$standardEvent,
					implode(', ', array_keys(self::STANDARD_EVENTS))
				)
			);
		}

		return ['event' => self::STANDARD_EVENTS[$standardEvent], 'item' => $planItem];
	}//end convertPlanItemOnPart()

	/**
	 * Convert a dossiq on-part on a case file item into an OpenRegister on-part.
	 *
	 * @param array<string, mixed> $onPart The dossiq on-part.
	 * @param string $where Which sentry, for the refusal message.
	 *
	 * @return array<string, mixed> The OpenRegister on-part.
	 *
	 * @throws UnexpectedValueException Naming the event it refuses.
	 */
	private function convertCaseFileOnPart(array $onPart, string $where): array {
		$caseFileEvent = (string)($onPart['caseFileEvent'] ?? 'set');
		if (in_array($caseFileEvent, ['set', 'changed'], true) === false) {
			throw new UnexpectedValueException(
				sprintf("%s names caseFileEvent '%s', which is outside the conversion table (set, changed).", $where, $caseFileEvent)
			);
		}

		return ['event' => self::OBJECT_WRITE_EVENT];
	}//end convertCaseFileOnPart()
}//end class
