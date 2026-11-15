<?php

/**
 * Contract of CaseFileCollector
 *
 * The public surface of CaseFileCollector that other components call, so they depend
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
 * Contract of CaseFileCollector.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseFileCollectorInterface {

	/**
	 * Store the bytes in the case folder and write the provenance on the record the file gets.
	 *
	 * The node listener normally projects the record while the file is
	 * written; when it has not (the write ran as another user, or no listener
	 * ran), the file in the case folder is projected here, through the same
	 * service.
	 *
	 * @param string               $caseId     The case uuid.
	 * @param string               $fileName   The file name.
	 * @param string               $content    The bytes.
	 * @param array<string, mixed> $provenance Where it was found.
	 * @param string               $mimeType   The MIME type the source gave, '' when none.
	 *
	 * @return string The document record uuid, '' when the file could not be written or no record resulted.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/document-provenance/spec.md#requirement-a-collected-document-carries-its-provenance-for-any-case-type-req-dpv-001
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-collected-mail-keeps-its-header-fields-req-wrr-003
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
	 */
	public function collect(string $caseId, string $fileName, string $content, array $provenance, string $mimeType = ''): string;
}//end interface
