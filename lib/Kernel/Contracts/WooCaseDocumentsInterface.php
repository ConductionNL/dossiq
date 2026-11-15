<?php

/**
 * Contract of WooCaseDocuments
 *
 * The public surface of WooCaseDocuments that other components call, so they depend
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
 * Contract of WooCaseDocuments.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WooCaseDocumentsInterface {

	/**
	 * The ids of every document on a case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<int, string> Informatieobject ids first, then legacy document ids.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
	 */
	public function idsFor(string $caseId): array;

	/**
	 * One document, with its file content base64-encoded in `content`, or null.
	 *
	 * @param string $documentId An informatieobject id, or a legacy document id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
	 */
	public function load(string $documentId): ?array;

	/**
	 * One document's row, without reading its file, or null.
	 *
	 * @param string $documentId An informatieobject id, or a legacy document id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-review-depth-is-set-per-document-type-on-the-case-type-and-recorded-req-wrt-004
	 */
	public function meta(string $documentId): ?array;
}//end interface
