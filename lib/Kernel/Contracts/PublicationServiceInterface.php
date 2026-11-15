<?php

/**
 * Contract of PublicationService
 *
 * The public surface of PublicationService that other components call, so they depend
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
 * Contract of PublicationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublicationServiceInterface {

	/**
	 * Publish a besluit on a case.
	 *
	 * Idempotent per (caseId, channel): re-publishing on the same channel
	 * updates the publishedAt timestamp rather than appending duplicates.
	 *
	 * NOTE: As of dossiq-delegate-contract-decision, this method publishes the
	 * already-recorded ZGW Besluit (fed by the decidesk Decision outcome via
	 * BesluitMaterialisationService) rather than authoring a new local besluit.
	 * The publication record is appended to the case's publications[] array;
	 * cross-app publication to Open Raadsinformatie / GemeenteBlad is handled
	 * by openconnector wiring (out of scope for the host app build).
	 *
	 * @param string $caseId The case id.
	 * @param array<string, mixed> $payload The publish payload: { channel, publishedAt?, notes? }.
	 *
	 * @return array<string, mixed> The publication record + updated case ref.
	 *
	 * @throws \InvalidArgumentException When the requested publication channel is not supported.
	 * @throws \RuntimeException When OR is unavailable or the case can't be loaded.
	 *
	 * @spec openspec/changes/archive/2026-06-13-besluitvorming-workflow/tasks.md#task-7
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function publish(string $caseId, array $payload): array;
}//end interface
