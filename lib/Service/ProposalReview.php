<?php

/**
 * One review of a pending redaction proposal
 *
 * The case and document the proposal is on, who reviews it, and the proposal
 * as it now stands: the state of WOOAnonymisationAssistService::reviewProposal().
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-07-16-woo-llm-anonymisation/tasks.md#task-2-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;


/**
 * The state of one review; it never leaves the service that built it.
 *
 * @spec openspec/changes/archive/2026-07-16-woo-llm-anonymisation/tasks.md#task-2-2
 */
final class ProposalReview {

	/**
	 * The case.
	 *
	 * @var string
	 */
	public string $caseId = '';

	/**
	 * The document.
	 *
	 * @var string
	 */
	public string $documentRef = '';

	/**
	 * Who reviews.
	 *
	 * @var string
	 */
	public string $reviewerId = '';

	/**
	 * The proposal as it now stands.
	 *
	 * @var array<string, mixed>
	 */
	public array $proposal = [];
}//end class
