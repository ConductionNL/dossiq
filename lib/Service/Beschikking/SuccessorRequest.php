<?php

/**
 * Dossiq SuccessorRequest.
 *
 * One request for a successor to a signed beschikking: the beschikking it
 * corrects or withdraws, whether it is a wijzigings- or an
 * intrekkingsbeschikking, what the handler wrote for it and the template it
 * is composed from.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Beschikking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Beschikking;

/**
 * The successor BeschikkingService::issueSuccessor() issues.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
final class SuccessorRequest {

	/**
	 * Constructor.
	 *
	 * @param string               $originalId The beschikking being corrected or withdrawn.
	 * @param string               $kind       `amendment` or `withdrawal`.
	 * @param array<string, mixed> $overrides  Content of the successor: rationale, decision, addressee.
	 * @param string|null          $templateId The template; the original's when null.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $originalId,
		public readonly string $kind,
		public readonly array $overrides,
		public readonly ?string $templateId,
	) {
	}//end __construct()
}//end class
