<?php

/**
 * The KCC routing dialect's shared readings of a rule set and a contact moment
 *
 * Which rules take part and in what order (the enabled ones, ascending by
 * priority, first match wins), and which kind of customer a contact moment
 * is from. Both the decision-table routing and the legacy engine it is
 * checked against read them the same way, so they read them here.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Kcc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

/**
 * Static readings of routing rules and contact moments.
 *
 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-03
 */
final class RoutingRuleOrder {

	/**
	 * The enabled rules, ascending by priority.
	 *
	 * @param array<int, array<string, mixed>> $rules Routing rules.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-03
	 */
	public static function enabledByPriority(array $rules): array {
		$enabled = array_values(
			array_filter(
				$rules,
				static function (array $rule): bool {
					return (($rule['enabled'] ?? true) === true);
				}
			)
		);

		usort(
			$enabled,
			static function (array $first, array $second): int {
				return (((int)($first['priority'] ?? 0)) <=> ((int)($second['priority'] ?? 0)));
			}
		);

		return $enabled;
	}//end enabledByPriority()

	/**
	 * Derive the customer type from the contact moment.
	 *
	 * An 8-digit numeric customerRef is treated as a KvK number (bedrijf);
	 * any other non-empty reference is a burger; empty is anonymous.
	 *
	 * @param array<string, mixed> $contactMoment The contact moment.
	 *
	 * @return string One of 'bedrijf', 'burger', 'anoniem'.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-03
	 */
	public static function customerType(array $contactMoment): string {
		$ref = trim((string)($contactMoment['customerRef'] ?? ''));
		if ($ref === '') {
			return 'anoniem';
		}

		if (preg_match('/^\d{8}$/', $ref) === 1) {
			return 'bedrijf';
		}

		return 'burger';
	}//end customerType()
}//end class
