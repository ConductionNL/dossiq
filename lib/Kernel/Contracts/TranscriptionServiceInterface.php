<?php

/**
 * Contract of TranscriptionService
 *
 * The public surface of TranscriptionService that other components call, so they depend
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
 * Contract of TranscriptionService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TranscriptionServiceInterface {

	public const STATUS_FALLBACK = 'manual';

	/**
	 * Queue a FieldEvidence voice-memo for transcription.
	 *
	 * Idempotent: re-queueing a record already in queued/running/done state
	 * is a no-op and returns the existing record.
	 *
	 * @param array<string, mixed> $evidence The FieldEvidence record (must be a voice_memo).
	 *
	 * @return array<string, mixed> The updated record with transcriptionStatus.
	 *
	 * @throws \InvalidArgumentException When the record is not a voice memo.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mobiel-inspectie-offline/tasks.md#Task-9
	 */
	public function queue(array $evidence): array;
}//end interface
