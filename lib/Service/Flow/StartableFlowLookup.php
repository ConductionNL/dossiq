<?php

/**
 * Where a case's startable flows are looked up.
 *
 * Built by `CaseFlowActions::startableFlowIds()` and handed through the steps that read the case and
 * its case type: OpenRegister's ObjectService, the register and the case type schema. It also reads
 * what those steps get back without touching the store: an answer as an array, and the flow uuids a
 * case type's `startableFlows` holds.
 *
 * @category Flow
 * @package  OCA\Dossiq\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

/**
 * Where a case's startable flows are looked up, and how what it answers is read.
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class StartableFlowLookup {

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      The register slug.
	 * @param string $typeSchema    The case type schema slug.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $typeSchema,
	) {
	}//end __construct()

	/**
	 * The flow uuids in a case type's `startableFlows`.
	 *
	 * @param mixed $declared The property as stored.
	 *
	 * @return array<int, string> The uuids.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function flowIdsOf(mixed $declared): array {
		if (is_array($declared) === false) {
			return [];
		}

		$uuids = [];
		foreach ($declared as $entry) {
			// An entry is a plain uuid, but a hand-edited case type may carry the
			// object shape an OpenRegister picker writes, so both are read.
			$uuid = $entry;
			if (is_array($entry) === true) {
				$uuid = ($entry['id'] ?? '');
			}

			$uuid = trim((string)$uuid);
			if ($uuid !== '') {
				$uuids[] = $uuid;
			}
		}

		return $uuids;
	}//end flowIdsOf()

	/**
	 * An object as an array, or null when there is none.
	 *
	 * @param mixed $found What the ObjectService answered.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function asArray(mixed $found): ?array {
		if ($found === null) {
			return null;
		}

		if (is_array($found) === true) {
			return $found;
		}

		if (method_exists($found, 'jsonSerialize') === true) {
			$serialized = $found->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return (array)$found;
	}//end asArray()
}//end class
