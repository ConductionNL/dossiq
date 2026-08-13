<?php

/**
 * Procest mandaat registry service.
 *
 * The administrator's read/write surface over the four mandate-matrix
 * registries the admin settings panel edits: MandateringsBesluiten, Mandaten,
 * OrganisatieRollen and MedewerkerRolToewijzingen.
 *
 * This exists because the shipped admin panel called four procest URLs that
 * `appinfo/routes.php` never declared (procest#794). Nextcloud answers an
 * unmatched app URL with its own HTML page under HTTP 200, so the tabs failed
 * silently: the callers use the correct `Array.isArray(x) ? x : (x?.results ||
 * [])` guard, which discards the HTML string and renders an empty table with no
 * error. The guard is right; the caller had nowhere to call.
 *
 * `MandaatRepository` deliberately keeps the *import* paths — the rolNaam→rolId
 * index, the prior-besluit lookup, bulk activation. This class owns the
 * administrator's CRUD instead, and the one piece of domain logic that is not
 * generic: the referential-integrity guard the spec requires before a role may
 * be deleted. Everything schema-generic is delegated to
 * `ConfiguredRegistryService`.
 *
 * @category Service
 * @package  OCA\Procest\Service\Mandaat
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
 * @spec openspec/specs/mandaat-matrix/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Mandaat;

use OCA\Procest\Service\Support\ConfiguredRegistryService;
use RuntimeException;

/**
 * Administrator read/write surface for the mandate-matrix registries.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/mandaat-matrix/spec.md
 */
class MandaatRegistryService {

	/**
	 * Config key naming the MandateringsBesluit schema.
	 *
	 * @var string
	 */
	public const SCHEMA_BESLUIT = 'mandaterings_besluit_schema';

	/**
	 * Config key naming the Mandaat schema.
	 *
	 * @var string
	 */
	public const SCHEMA_MANDAAT = 'mandaat_schema';

	/**
	 * Config key naming the OrganisatieRol schema.
	 *
	 * @var string
	 */
	public const SCHEMA_ROL = 'organisatie_rol_schema';

	/**
	 * Config key naming the MedewerkerRolToewijzing schema.
	 *
	 * @var string
	 */
	public const SCHEMA_TOEWIJZING = 'medewerker_rol_toewijzing_schema';

	/**
	 * Field names under which a row may reference an OrganisatieRol.
	 *
	 * ⚠️ These are read from the SHIPPED SCHEMAS, not guessed: `Mandaat`
	 * (`mandaat_schema`) names it `gemandateerdeRol`, and
	 * `MedewerkerRolToewijzing` (`medewerker_rol_toewijzing_schema`) names it
	 * `rolId`. The two registries genuinely disagree, so a single field name
	 * cannot cover both — an earlier draft of this guard checked
	 * `organisatieRol`/`rol`/`rolId` and would have failed OPEN for every
	 * Mandaat, silently permitting deletion of a role a mandate depends on.
	 * Verify against the register before adding or removing a name here.
	 *
	 * @var string[]
	 */
	private const ROLE_REFERENCE_FIELDS = ['gemandateerdeRol', 'rolId'];

	/**
	 * Constructor.
	 *
	 * @param ConfiguredRegistryService $registry Generic configured-schema CRUD.
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	public function __construct(
		private readonly ConfiguredRegistryService $registry,
	) {
	}//end __construct()

	/**
	 * List every object of one of the mandate registries.
	 *
	 * @param string $schemaConfigKey One of this class's SCHEMA_* constants.
	 *
	 * @return array<int, array<string, mixed>> The registry rows.
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	public function list(string $schemaConfigKey): array {
		return $this->registry->list(schemaConfigKey: $schemaConfigKey);
	}//end list()

	/**
	 * Create or update a single mandate-registry object.
	 *
	 * @param string $schemaConfigKey One of this class's SCHEMA_* constants.
	 * @param array<string, mixed> $data The object payload.
	 * @param string|null $id Existing id, or null to create.
	 *
	 * @return array<string, mixed> The saved object.
	 *
	 * @throws RuntimeException When the registry is not configured.
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	public function save(string $schemaConfigKey, array $data, ?string $id = null): array {
		return $this->registry->save(schemaConfigKey: $schemaConfigKey, data: $data, id: $id);
	}//end save()

	/**
	 * Delete an OrganisatieRol, refusing when it is still referenced.
	 *
	 * The spec requires the guard explicitly: a role referenced by a Mandaat or
	 * by an *active* MedewerkerRolToewijzing may not be deleted. "Active" means
	 * the assignment has no end date, or an end date that has not yet passed —
	 * an expired assignment is history and must not pin a role forever.
	 *
	 * @param string $id The OrganisatieRol id.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the registry is unconfigured, or when the
	 *                          role is still referenced.
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	public function deleteRole(string $id): void {
		$blockers = $this->findRoleReferences(roleId: $id);
		if ($blockers !== []) {
			throw new RuntimeException(
				'This role cannot be deleted while it is still referenced by ' . implode(' and ', $blockers)
			);
		}

		$this->registry->delete(schemaConfigKey: self::SCHEMA_ROL, id: $id);
	}//end deleteRole()

	/**
	 * Describe the references that block deleting a role.
	 *
	 * Returned as human-readable phrases so the caller can put them straight
	 * into the refusal message. An empty array means the role is deletable.
	 *
	 * @param string $roleId The OrganisatieRol id.
	 *
	 * @return array<int, string> Phrases naming each blocking reference.
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	public function findRoleReferences(string $roleId): array {
		$blockers = [];

		$byMandate = 0;
		foreach ($this->list(schemaConfigKey: self::SCHEMA_MANDAAT) as $mandate) {
			if ($this->referencesRole(row: $mandate, roleId: $roleId) === true) {
				$byMandate++;
			}
		}

		if ($byMandate > 0) {
			$blockers[] = $byMandate . ' mandaat(en)';
		}

		$active = 0;
		foreach ($this->list(schemaConfigKey: self::SCHEMA_TOEWIJZING) as $toewijzing) {
			$matches = $this->referencesRole(row: $toewijzing, roleId: $roleId);
			if ($matches === true && $this->isAssignmentActive(row: $toewijzing) === true) {
				$active++;
			}
		}

		if ($active > 0) {
			$blockers[] = $active . ' active role assignment(s)';
		}

		return $blockers;
	}//end findRoleReferences()

	/**
	 * Decide whether a row points at a given role through any known field.
	 *
	 * @param array<string, mixed> $row The registry row.
	 * @param string $roleId The role id being deleted.
	 *
	 * @return bool True when the row references the role.
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	private function referencesRole(array $row, string $roleId): bool {
		foreach (self::ROLE_REFERENCE_FIELDS as $field) {
			$value = ($row[$field] ?? null);
			if (is_array($value) === true) {
				$value = ($value['id'] ?? null);
			}

			if ($value !== null && (string)$value === $roleId) {
				return true;
			}
		}

		return false;
	}//end referencesRole()

	/**
	 * Decide whether a MedewerkerRolToewijzing is still in force today.
	 *
	 * ⚠️ The end date lives in `validUntil`. The spec prose for this scenario
	 * says `toewijzingTotEnMet` SHALL be set, but the shipped
	 * `medewerker_rol_toewijzing_schema` declares
	 * `userId, rolId, toewijzingType, validFrom, validUntil` and has no
	 * `toewijzingTotEnMet` property at all. Implemented against the schema,
	 * because that is what the data is actually stored under; the prose/schema
	 * disagreement is reported separately rather than resolved here by editing
	 * the spec.
	 *
	 * An absent end date means an open-ended assignment, which counts as
	 * active — the guard therefore fails CLOSED when the field is missing.
	 *
	 * @param array<string, mixed> $row The assignment row.
	 *
	 * @return bool True when the assignment has not ended.
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	private function isAssignmentActive(array $row): bool {
		$end = (string)($row['validUntil'] ?? '');
		if ($end === '') {
			return true;
		}

		return $end >= date('Y-m-d');
	}//end isAssignmentActive()
}//end class
