<?php

/**
 * Dossiq portal Woo answer submission
 *
 * What portaliq forwards when a requester answers the organisation's
 * question from Mijn zaken.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

use DateTimeImmutable;

/**
 * One answer a requester sends to an open clarification request.
 *
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
 */
final class WooAnswerSubmission {

	/**
	 * The resident the portal assertion names.
	 *
	 * @var string
	 */
	public string $subjectRef = '';

	/**
	 * The clarification request answered.
	 *
	 * @var string
	 */
	public string $requestId = '';

	/**
	 * The answer, as sent.
	 *
	 * @var string
	 */
	public string $answer = '';

	/**
	 * The moment of answering; now when null.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $now = null;

	/**
	 * The files sent with the answer, each with its name and content.
	 *
	 * @var array<int, array{name: string, content: string}>
	 */
	public array $attachments = [];
}//end class
