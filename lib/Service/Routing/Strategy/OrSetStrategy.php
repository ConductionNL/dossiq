<?php

/**
 * Dossiq OR-Set Routing Strategy
 *
 * Returns the union of participants whose role binding matches any of the
 * rule's `roleTypes`. Used to normalise the legacy
 * `StatusTransition.allowedRoles` array — any of the listed roles may act.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Routing\Strategy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Routing\Strategy;

use OCA\Dossiq\Service\Routing\RoutingStrategyInterface;

/**
 * OR-set strategy.
 *
 * @spec openspec/changes/role-based-step-routing/tasks.md#T03
 */
class OrSetStrategy implements RoutingStrategyInterface {
	/**
	 * {@inheritDoc}
	 *
	 * @return string The strategy name.
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function name(): string {
		return 'or-set';
	}//end name()

	/**
	 * Union over `roleTypes`. Duplicate participants are collapsed; the order
	 * follows first-match across the supplied role types.
	 *
	 * @param array<string, mixed> $rule The routing rule
	 * @param array<string, mixed> $case The case object
	 * @param array<int, array<string, mixed>> $roles Roles bound to the case
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function resolve(array $rule, array $case, array $roles): array {
		$rawTypes = $rule['roleTypes'] ?? [];
		if (is_array($rawTypes) === false) {
			return [];
		}

		$allowed = self::allowedTypes(rawTypes: $rawTypes);
		if ($allowed === []) {
			return [];
		}

		return self::participantsOf(roles: $roles, allowed: $allowed);
	}//end resolve()

	/**
	 * The role types the rule allows, as a set.
	 *
	 * @param array<int|string, mixed> $rawTypes The rule's role types.
	 *
	 * @return array<string, true> The allowed types.
	 */
	private static function allowedTypes(array $rawTypes): array {
		$allowed = [];
		foreach ($rawTypes as $type) {
			$value = (string)$type;
			if ($value !== '') {
				$allowed[$value] = true;
			}
		}

		return $allowed;
	}//end allowedTypes()

	/**
	 * Each participant holding an allowed role type, once, in role order.
	 *
	 * @param array<int, array<string, mixed>> $roles   The case's roles.
	 * @param array<string, true>              $allowed The allowed types.
	 *
	 * @return array<int, string> The participants.
	 */
	private static function participantsOf(array $roles, array $allowed): array {
		$seen = [];
		$result = [];
		foreach ($roles as $role) {
			$participant = (string)($role['participant'] ?? '');
			if (isset($allowed[(string)($role['roleType'] ?? '')]) === false || $participant === '' || isset($seen[$participant]) === true) {
				continue;
			}

			$seen[$participant] = true;
			$result[] = $participant;
		}

		return $result;
	}//end participantsOf()
}//end class
