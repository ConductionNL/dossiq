<?php

/**
 * Contract of DsoCaseService
 *
 * The public surface of DsoCaseService that other components call, so they depend
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

use OCP\IUser;

/**
 * Contract of DsoCaseService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DsoCaseServiceInterface {

	/**
	 * Authorise a zaak mutation for the given user.
	 *
	 * Checks whether the user is either the assigned user on the zaak or
	 * a Nextcloud administrator. Throws an exception if not authorised so
	 * that the controller can catch and return a 403 response.
	 *
	 * @param array<string,mixed> $case The zaak object array
	 * @param IUser $user The authenticated user
	 *
	 * @return void
	 *
	 * @throws \Exception When the user is not authorised to mutate the zaak
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T03
	 */
	public function authorizeZaakMutation(array $case, IUser $user): void;

	/**
	 * Create a Dossiq zaak from a DSO intake record.
	 *
	 * The record is either integriq's `dso_verzoek` after mapping (it carries
	 * `mappedCaseTypes`, `mappedTitle`, `submissionDate`, `rawRequest`) or a
	 * legacy vergunningaanvraag (`title`, `indieningsdatum`, `activiteiten`).
	 * The listener hands the record it was given; without one it is read from
	 * the legacy `dso` register.
	 *
	 * The case is written in the shape the case schema accepts: `caseType` is
	 * the uuid of the case type the activity mapping names, `status` is that
	 * type's initial status type, and `dsoStatus` is `submitted`, the DSO-LV
	 * status the DSO term timer (DsoDeadlineTimer) arms on. A record that names no case type that
	 * resolves writes nothing and throws: a case on a guessed type is worse
	 * than a verzoek still waiting in integriq's list.
	 *
	 * One record makes one case. A second event for the same record answers
	 * the case the first one made.
	 *
	 * @param string                    $permitApplicationId The UUID of the intake record
	 * @param array<string, mixed>|null $permitApplication   The record itself, when the caller holds it
	 *
	 * @return array<string,mixed> The created (or already existing) zaak object
	 *
	 * @throws \RuntimeException When the record is missing or names no case type that resolves
	 *
	 * @spec openspec/specs/vth-dso-integration/spec.md
	 */
	public function createZaakFromVergunningaanvraag(string $permitApplicationId, ?array $permitApplication = null): array;

	/**
	 * Transition the DSO status of a DSO zaak.
	 *
	 * `newStatus` is a DSO-LV value (submitted, in_handling, granted, refused,
	 * withdrawn), and it lands on `dsoStatus`, the field the schema declares
	 * for it. The case's own `status` is the uuid of a status type and moves
	 * through StatusTransitionService, never through a DSO value. The write is
	 * a patch of the fields this act owns, so the case's computed fields are
	 * not sent back. The linked vergunningaanvraag is synced and a
	 * VergunningStatusChangedEvent is dispatched for downstream listeners.
	 *
	 * @param string $caseId The UUID of the zaak
	 * @param string $newStatus The target DSO status value
	 * @param string|null $besluitdatum Optional ISO 8601 decision date
	 * @param string|null $notes Optional explanation text
	 * @param string $userId The Nextcloud user UID performing the action
	 *
	 * @return array<string,mixed> The updated zaak object
	 *
	 * @throws \RuntimeException When the zaak cannot be found
	 *
	 * @spec openspec/specs/vth-dso-integration/spec.md
	 */
	public function transitionStatus(
		string $caseId,
		string $newStatus,
		?string $besluitdatum,
		?string $notes,
		string $userId,
	): array;
}//end interface
