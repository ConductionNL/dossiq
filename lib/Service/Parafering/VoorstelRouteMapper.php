<?php

/**
 * Procest voorstel route mapper.
 *
 * Pure shaping of the two voorstel fields the parafering route engine reads
 * and writes as loosely-typed data: `routeSnapshot`, which may arrive as a
 * JSON string or an array and must come back as an order-sorted list of step
 * arrays, and `auditTrail`, which is appended to under the same
 * string-or-array ambiguity. Split out of ParafeerRouteService so that service
 * keeps workflow execution and this conversion has one owner.
 *
 * Every path is total: an unparsable snapshot yields an empty step list and a
 * non-array audit trail is replaced rather than appended to, so a malformed
 * stored value can never make the engine throw mid-transition.
 *
 * @category Service
 * @package  OCA\Procest\Service\Parafering
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/parafeerroute-engine/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Parafering;

/**
 * Normalises route snapshots and appends audit-trail entries.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/parafeerroute-engine/tasks.md#T04
 */
class VoorstelRouteMapper {
	/**
	 * Append an entry to the voorstel auditTrail field.
	 *
	 * @param array<string, mixed> $proposal The voorstel.
	 * @param array<string, mixed> $entry The entry to append.
	 *
	 * @return array<string, mixed> The voorstel with the entry appended.
	 *
	 * @spec openspec/changes/parafeerroute-engine/tasks.md#T04
	 */
	public function appendAuditTrail(array $proposal, array $entry): array {
		$trail = $proposal['auditTrail'] ?? [];
		if (is_string($trail) === true) {
			$decoded = json_decode($trail, true);
			$trail = [];
			if (is_array($decoded) === true) {
				$trail = $decoded;
			}
		}

		if (is_array($trail) === false) {
			$trail = [];
		}

		$trail[] = $entry;
		$proposal['auditTrail'] = $trail;

		return $proposal;
	}//end appendAuditTrail()

	/**
	 * Normalize a steps value (JSON string or array) to a plain ordered array.
	 *
	 * @param mixed $value The raw value from routeSnapshot or schema field.
	 *
	 * @return array<int, array<string, mixed>> The steps, sorted by `order`.
	 *
	 * @spec openspec/changes/parafeerroute-engine/tasks.md#T04
	 */
	public function normalizeSteps(mixed $value): array {
		if (is_string($value) === true) {
			$decoded = json_decode($value, true);
			$value = [];
			if (is_array($decoded) === true) {
				$value = $decoded;
			}
		}

		if (is_array($value) === false) {
			return [];
		}

		$steps = [];
		foreach ($value as $candidate) {
			if (is_array($candidate) === true) {
				$steps[] = $candidate;
			}
		}

		usort(
			$steps,
			static function (array $left, array $right): int {
				return ((int)($left['order'] ?? 0)) <=> ((int)($right['order'] ?? 0));
			},
		);

		return $steps;
	}//end normalizeSteps()
}//end class
