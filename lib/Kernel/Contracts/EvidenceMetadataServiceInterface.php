<?php

/**
 * Contract of EvidenceMetadataService
 *
 * The public surface of EvidenceMetadataService that other components call, so they depend
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
 * Contract of EvidenceMetadataService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EvidenceMetadataServiceInterface {

	/**
	 * Build a normalized fieldEvidence payload for an offline capture.
	 *
	 * Applies sane defaults: a voice_memo starts with transcriptionStatus
	 * "pending"; all other types are "not_applicable". The sensitivity level
	 * defaults to "internal" unless explicitly overridden.
	 *
	 * @param string $inspectionRef The owning inspection.
	 * @param string $type One of photo/voice_memo/document/sketch.
	 * @param array<string, mixed> $extra Additional fields (localBlobRef, tags, etc.).
	 * @param array{lat?: float, lon?: float}|null $caseAddress Fallback for sensorless GPS.
	 * @param array{lat?: float, lon?: float, accuracy?: float}|null $gpsReading The sensor reading.
	 *
	 * @return array<string, mixed> The fieldEvidence payload.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mobiel-inspectie-offline/tasks.md#task-8
	 */
	public function buildEvidencePayload(
		string $inspectionRef,
		string $type,
		array $extra = [],
		?array $caseAddress = null,
		?array $gpsReading = null,
	): array;
}//end interface
