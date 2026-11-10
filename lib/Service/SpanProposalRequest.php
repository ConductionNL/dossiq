<?php

/**
 * One request for a redaction proposal on a document
 *
 * The case, the document and who asks, and what the audit entry records
 * about it: the length of the text, the rule spans found and the time the
 * detection took. The state of WOOAnonymisationAssistService::proposeSpans().
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
 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;


/**
 * The state of one proposal request; it never leaves the service that built it.
 *
 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-2
 */
final class SpanProposalRequest {

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
	 * Who asks.
	 *
	 * @var string
	 */
	public string $userId = '';

	/**
	 * The length of the document text, in bytes.
	 *
	 * @var integer
	 */
	public int $textLength = 0;

	/**
	 * How many spans the rules found.
	 *
	 * @var integer
	 */
	public int $ruleSpanCount = 0;

	/**
	 * How long the detection took, in milliseconds.
	 *
	 * @var integer
	 */
	public int $responseTimeMs = 0;
}//end class
