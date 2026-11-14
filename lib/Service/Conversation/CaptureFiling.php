<?php

/**
 * Dossiq CaptureFiling.
 *
 * One capture while CaseCaptureService files it: the file and what it is,
 * the case and task it belongs to, and the store and moment it is filed in.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Conversation
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Conversation;

/**
 * The state of one capture filing, built and read by CaseCaptureService only.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class CaptureFiling {
	/**
	 * Case UUID.
	 *
	 * @var string
	 */
	public readonly string $caseId;

	/**
	 * The file the recorder produced.
	 *
	 * @var string
	 */
	public readonly string $fileName;

	/**
	 * Its media type.
	 *
	 * @var string
	 */
	public string $mimeType = '';

	/**
	 * Its bytes, base64 encoded.
	 *
	 * @var string
	 */
	public string $content = '';

	/**
	 * The task it was captured for, when there is one.
	 *
	 * @var string|null
	 */
	public ?string $taskId = null;

	/**
	 * The user that captured it.
	 *
	 * @var string|null
	 */
	public ?string $author = null;

	/**
	 * The OpenRegister seam and the two schemas it is filed through.
	 *
	 * @var array{objectService: object, register: int|string, documentSchema: int|string, caseDocumentSchema: int|string}|null
	 */
	public ?array $store = null;

	/**
	 * The moment it is filed, as ATOM.
	 *
	 * @var string
	 */
	public string $now = '';

	/**
	 * Start filing one file on one case.
	 *
	 * @param string $caseId   Case UUID.
	 * @param string $fileName The file the recorder produced.
	 */
	public function __construct(string $caseId, string $fileName) {
		$this->caseId = $caseId;
		$this->fileName = $fileName;
	}//end __construct()
}//end class
