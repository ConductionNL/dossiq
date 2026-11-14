<?php

/**
 * Contract of MandaatRegistryService
 *
 * The public surface of MandaatRegistryService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use RuntimeException;

/**
 * Contract of MandaatRegistryService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MandaatRegistryServiceInterface {

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
	public function deleteRole(string $id): void;

	/**
	 * List every object of one of the mandate registries.
	 *
	 * @param string $schemaConfigKey One of this class's SCHEMA_* constants.
	 *
	 * @return array<int, array<string, mixed>> The registry rows.
	 *
	 * @spec openspec/specs/mandaat-matrix/spec.md
	 */
	public function list(string $schemaConfigKey): array;

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
	public function save(string $schemaConfigKey, array $data, ?string $id = null): array;
}//end interface
