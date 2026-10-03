<?php

/**
 * What every case type projects onto the case schema's properties, as a record.
 *
 * The pure half of {@see CaseFieldRoleProjector}. That class resolves a live
 * schema and writes to it; this one only turns three arrays into a fourth, and
 * every failure it prevents is silent:
 *
 * - a merge that replaced a property's authorization would unpublish the other
 *   case types' rules, and the register's own, with nothing to report it;
 * - a merge that only ever added could never take a rule off again;
 * - a withdrawal that reached for the register's floor would take a grant
 *   nobody withdrew.
 *
 * It is a class of its own rather than four more methods on the projector
 * because the two halves fail differently. A mistake here is arithmetic, and a
 * unit test can hold the whole of it. A mistake there needs a schema, a mapper
 * and an instance. Splitting them also keeps the projector under the complexity
 * the gate allows: it was AT the threshold with both halves in it, which is one
 * branch away from refusing the next person who touches it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Access
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
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

/**
 * Keeps the record of what each case type projects, and merges it onto the properties.
 *
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */
class FieldRoleLedger {

	/**
	 * The property key OpenRegister reads field-level security from.
	 */
	public const AUTHORIZATION_KEY = 'authorization';

	/**
	 * Constructor.
	 *
	 * @param FieldRoleRuleDeclaration $declaration Owns the grant list and how two of them join.
	 */
	public function __construct(
		private readonly FieldRoleRuleDeclaration $declaration,
	) {
	}//end __construct()

	/**
	 * The ledger with one case type's entry brought up to date.
	 *
	 * Public because it is the half of this class that cannot be seen from the
	 * outside, and the failure it prevents — another case type's rules
	 * disappearing — is invisible from the editor. An empty projection REMOVES
	 * the key rather than writing an empty block, so a case type that declares
	 * nothing leaves nothing behind for the next reader to carry forward as if
	 * it meant something.
	 *
	 * @param array<string, mixed> $ledger     The ledger as it stands.
	 * @param string               $caseTypeId The case type being published.
	 * @param array<string, mixed> $own        What that case type now projects.
	 *
	 * @return array<string, mixed> The ledger.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	public function ledgerWith(array $ledger, string $caseTypeId, array $own): array {
		if ($caseTypeId === '') {
			return $ledger;
		}

		unset($ledger[$caseTypeId]);
		if ($own !== []) {
			$ledger[$caseTypeId] = $own;
		}

		return $ledger;
	}//end ledgerWith()

	/**
	 * The schema properties carrying the register's grants plus the ledger's.
	 *
	 * Only a property NAMED by one of the two is touched. A property whose
	 * authorization came from somewhere else entirely keeps it, because this
	 * class knows what it declared and must not infer ownership of what it did
	 * not.
	 *
	 * A property that ends up with no grants at all loses the `authorization`
	 * key rather than keeping an empty one. `read: []` is a non-empty
	 * authorization block holding nobody, so leaving it behind would strip the
	 * field for every non-administrator on the instance while the case type
	 * declares nothing at all.
	 *
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param array<string, mixed> $base       The authorization the register JSON declares.
	 * @param array<string, mixed> $ledger     What every case type projects now.
	 * @param array<string, mixed> $previous   What every case type projected before.
	 *
	 * @return array<string, mixed> The properties.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	public function propertiesWith(
		array $properties,
		array $base,
		array $ledger,
		array $previous = []
	): array {
		// 🔴 THE FIELDS THE LEDGER HAS JUST STOPPED OWNING HAVE TO BE VISITED
		// TOO, OR A RULE CAN BE ADDED AND NEVER TAKEN OFF. A withdrawal removes
		// the field from the new ledger, so a loop over the new ledger alone
		// never reaches the property and the grant written for a rule nobody
		// declares any more stays on the schema for ever. Seeding those fields
		// with an empty block is what makes the withdrawal a write.
		$wanted = $this->withdrawn(ledger: $ledger, previous: $previous);
		foreach ($base as $field => $block) {
			$wanted[$field] = $block;
		}

		foreach ($ledger as $own) {
			if (is_array($own) === false) {
				continue;
			}

			foreach ($own as $field => $block) {
				if (is_array($block) === false) {
					continue;
				}

				$wanted[$field] = $this->mergeBlocks(
					current: ($wanted[$field] ?? []),
					added: $block
				);
			}
		}

		foreach ($wanted as $field => $block) {
			if (is_array(($properties[$field] ?? null)) === false) {
				// A rule naming a property the case schema does not declare is
				// left unpublished rather than invented: OpenRegister refuses a
				// whole schema save over an authorization block on a property
				// it cannot find, and one stale rule must not make every other
				// rule on the schema unpublishable.
				continue;
			}

			if ($block === []) {
				unset($properties[$field][self::AUTHORIZATION_KEY]);
				continue;
			}

			$properties[$field][self::AUTHORIZATION_KEY] = $block;
		}

		return $properties;
	}//end propertiesWith()

	/**
	 * The fields the ledger owned a moment ago and does not own now.
	 *
	 * Each answers an EMPTY block, which the caller reads as "clear it". A
	 * field the register JSON still declares is written back over that empty
	 * block a line later, so a withdrawal never takes the register's own grant
	 * with it.
	 *
	 * @param array<string, mixed> $ledger   What every case type projects now.
	 * @param array<string, mixed> $previous What every case type projected before.
	 *
	 * @return array<string, array<string, mixed>> The fields to clear.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	private function withdrawn(array $ledger, array $previous): array {
		$owned = [];
		foreach ($ledger as $own) {
			if (is_array($own) === true) {
				$owned = array_merge($owned, array_keys($own));
			}
		}

		$clear = [];
		foreach ($previous as $own) {
			if (is_array($own) === false) {
				continue;
			}

			foreach (array_keys($own) as $field) {
				if (in_array($field, $owned, true) === false) {
					$clear[$field] = [];
				}
			}
		}

		return $clear;
	}//end withdrawn()

	/**
	 * Two authorization blocks as one, verb by verb.
	 *
	 * @param array<string, mixed> $current The block already gathered.
	 * @param array<string, mixed> $added   The block to add.
	 *
	 * @return array<string, mixed> The merged block.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	private function mergeBlocks(array $current, array $added): array {
		foreach ($added as $verb => $grants) {
			if (is_array($grants) === false) {
				continue;
			}

			$held = ($current[$verb] ?? []);
			if (is_array($held) === false) {
				$held = [];
			}

			$current[$verb] = $this->declaration->union(current: $held, added: $grants);
		}

		return $current;
	}//end mergeBlocks()
}//end class
