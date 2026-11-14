<?php

/**
 * Contract of CaseCaptureService
 *
 * The public surface of CaseCaptureService that other components call, so they depend
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
 * Contract of CaseCaptureService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseCaptureServiceInterface {

	/**
	 * Refusal reason: the file offered is not a capture.
	 *
	 * @var string
	 */
	public const REASON_NOT_A_CAPTURE = 'not_a_capture';

	/**
	 * Refusal reason: the capture carries no name or no content.
	 *
	 * @var string
	 */
	public const REASON_EMPTY = 'capture_empty';

	/**
	 * Attach a capture to a case, optionally carried by a task.
	 *
	 * @param string      $caseId   Case UUID.
	 * @param string      $fileName The file the recorder produced.
	 * @param string      $mimeType Its media type.
	 * @param string      $content  Its bytes, base64 encoded.
	 * @param string|null $taskId   The task it was captured for, when there is one.
	 * @param string|null $author   The user that captured it.
	 *
	 * @return array{ok: bool, reason?: string, document?: array<string, mixed>}
	 *         The case document, or a refusal naming its reason.
	 *
	 * @throws RuntimeException When OpenRegister is not configured for documents.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function attachCapture(
		string $caseId,
		string $fileName,
		string $mimeType,
		string $content,
		?string $taskId = null,
		?string $author = null,
	): array;
}//end interface
