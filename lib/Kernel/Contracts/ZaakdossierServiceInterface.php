<?php

/**
 * Contract of ZaakdossierService
 *
 * The public surface of ZaakdossierService that other components call, so they depend
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
 * Contract of ZaakdossierService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ZaakdossierServiceInterface {

	/**
	 * Apply a bulk status transition, returning a per-id success/failure list.
	 *
	 * @param string[] $infoObjectIds The informatieobject UUIDs.
	 * @param string $newStatus The requested status.
	 *
	 * @return array<int, array<string, mixed>> Per-id results with `id`, `success`, optional `error`.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	public function bulkTransitionStatus(array $infoObjectIds, string $newStatus): array;

	/**
	 * List the dossier for a case, grouped by informatieobjecttype with counts.
	 *
	 * The returned `informatieobjecten` list is NOT clearance-filtered here;
	 * the controller applies {@see InformatieobjectAccessGuard::filterDossierForUser()}
	 * so the service stays testable without a user context.
	 *
	 * @param string $caseId The case (zaak) UUID.
	 * @param string $correspondent Narrow to the documents naming this party, or '' for all.
	 *
	 * @return array<string, mixed> Structure with `total`, `groups` and `informatieobjecten`.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable or config missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-015-the-correspondents-are-on-screen-and-can-be-filtered
	 */
	public function getDossierForCase(string $caseId, string $correspondent = ''): array;

	/**
	 * Group a list of informatieobjecten by informatieobjecttype with counts.
	 *
	 * @param array<int, array<string, mixed>> $documents The documents to group.
	 *
	 * @return array<string, mixed> Grouped structure.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	public function groupByType(array $documents): array;

	/**
	 * Link an existing informatieobject to a case without duplicating the document.
	 *
	 * Deduplicates: when the join already exists no second join is created.
	 *
	 * @param string $caseId The case (zaak) UUID.
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return array<string, mixed> The join summary.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable or config missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	public function linkExistingInformatieobject(string $caseId, string $infoObjectId): array;

	/**
	 * Validate and apply a status transition, locking the document on definitief.
	 *
	 * @param string $infoObjectId The informatieobject UUID.
	 * @param string $newStatus The requested status.
	 *
	 * @return array<string, mixed> The updated informatieobject summary.
	 *
	 * @throws \RuntimeException When OpenRegister/config is unavailable or status invalid.
	 * @throws \InvalidArgumentException When the transition is not permitted (caller maps to HTTP 400).
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	public function transitionStatus(string $infoObjectId, string $newStatus): array;

	/**
	 * Unlink an informatieobject from a case, preserving the document itself.
	 *
	 * Only the `zaakinformatieobject` join records are deleted; the
	 * informatieobject record and the Nextcloud file remain intact.
	 *
	 * @param string $caseId The case (zaak) UUID.
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return bool True when at least one join was removed.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable or config missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	public function unlinkInformatieobject(string $caseId, string $infoObjectId): bool;

	/**
	 * Update editable metadata on an informatieobject (titel, beschrijving, type).
	 *
	 * Rejects mutation of a definitief document (caller maps to HTTP 409).
	 *
	 * @param string $infoObjectId The informatieobject UUID.
	 * @param array<string, mixed> $metadata Editable fields.
	 *
	 * @return array<string, mixed> The updated summary.
	 *
	 * @throws \RuntimeException When OpenRegister/config unavailable or document missing.
	 * @throws \DomainException When the document is definitief and therefore immutable.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 */
	public function updateMetadata(string $infoObjectId, array $metadata): array;
}//end interface
