<?php

/**
 * Contract of ShippedConfigurationService
 *
 * The public surface of ShippedConfigurationService that other components call, so they depend
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

/**
 * Contract of ShippedConfigurationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ShippedConfigurationServiceInterface {

	/**
	 * Offer a newer shipped version of one object, and take it only when it is
	 * safe or accepted.
	 *
	 * 🔑 THE LOCAL CHANGE WINS BY DEFAULT. An upgrade that overwrites an edited
	 * case type destroys work an administrator did on purpose, and there is no
	 * undo for it. So a changed object is REFUSED and the newer version is
	 * reported as waiting; the administrator accepts it explicitly, having been
	 * told their change goes.
	 *
	 * @param string               $targetSchema The object's schema slug.
	 * @param string               $objectsKey   The app config key naming that schema.
	 * @param string               $targetObject The object's id.
	 * @param string               $set          The set the newer version comes from.
	 * @param array<string, mixed> $newShipped   The object as the newer set ships it.
	 * @param string|null          $version      The newer version, when the caller read it
	 *                                           from the package; null to look it up in ShippedSets.
	 *
	 * @return array{adopted: bool, reason: string} What happened, and why not when it did not.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
	 */
	public function adopt(
		string $targetSchema,
		string $objectsKey,
		string $targetObject,
		string $set,
		array $newShipped,
		?string $version = null,
	): array;

	/**
	 * Take the newer shipped version over a change somebody made here.
	 *
	 * 🔑 A SECOND NAMED ACT RATHER THAN A FLAG ON THE FIRST. `adopt($x, true)`
	 * reads the same at the call site whichever way the boolean goes, and what
	 * it does differs by whether an administrator's work survives. The two
	 * gestures are genuinely different and the caller has to pick one by name.
	 *
	 * @param string               $targetSchema The object's schema slug.
	 * @param string               $objectsKey   The app config key naming that schema.
	 * @param string               $targetObject The object's id.
	 * @param string               $set          The set the newer version comes from.
	 * @param array<string, mixed> $newShipped   The object as the newer set ships it.
	 *
	 * @return array{adopted: bool, reason: string} What happened.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function adoptOverLocalChange(
		string $targetSchema,
		string $objectsKey,
		string $targetObject,
		string $set,
		array $newShipped,
	): array;

	/**
	 * Every seeded object of one schema, with its state and whether a newer
	 * version of its set is waiting.
	 *
	 * @param string $targetSchema The schema slug, for example caseType.
	 * @param string $objectsKey   The app config key naming that schema.
	 *
	 * @return array<int, array<string, mixed>>|null The rows, or null when the
	 *                                               store is unreachable.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function overview(string $targetSchema, string $objectsKey): ?array;
}//end interface
