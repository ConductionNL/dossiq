<?php

/**
 * Dossiq portal Woo answer run
 *
 * What recording one requester's answer shares between its steps.
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
 * One answer being recorded: where it is read and written, and what was sent.
 *
 * Built by {@see PortalWooAnswer} only; it never leaves it.
 *
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
 */
final class WooAnswerRun {

	/**
	 * The OpenRegister object service.
	 *
	 * @var object
	 */
	public object $objectService;

	/**
	 * The register.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The case schema.
	 *
	 * @var string
	 */
	public string $caseSchema = '';

	/**
	 * The resident, trimmed.
	 *
	 * @var string
	 */
	public string $subjectRef = '';

	/**
	 * The clarification request, trimmed.
	 *
	 * @var string
	 */
	public string $requestId = '';

	/**
	 * The answer, trimmed.
	 *
	 * @var string
	 */
	public string $text = '';

	/**
	 * The moment of answering.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $moment;

	/**
	 * The files sent with the answer.
	 *
	 * @var array<int, array{name: string, content: string}>
	 */
	public array $attachments = [];
}//end class
