<?php

/**
 * One message to a case's applicant at a declared moment
 *
 * The case, the moment and template, the caller's context, and once known:
 * the recipient, the case id and the term instance it is sent under, and
 * when it was sent. The state ApplicantMessage::send() builds and its steps
 * read.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Notification;

/**
 * The state of one applicant message; it never leaves the service that built it.
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */
final class ApplicantNotice {

	/**
	 * The case row.
	 *
	 * @var array<string, mixed>
	 */
	public array $case = [];

	/**
	 * The declared moment.
	 *
	 * @var string
	 */
	public string $moment = '';

	/**
	 * The template.
	 *
	 * @var string
	 */
	public string $template = '';

	/**
	 * The caller's context for the template.
	 *
	 * @var array<string, mixed>
	 */
	public array $context = [];

	/**
	 * Where the message goes.
	 *
	 * @var string
	 */
	public string $recipient = '';

	/**
	 * The case id.
	 *
	 * @var string
	 */
	public string $caseId = '';

	/**
	 * The term instance the message is sent under, or ''.
	 *
	 * @var string
	 */
	public string $termId = '';

	/**
	 * When it was sent (ISO 8601).
	 *
	 * @var string
	 */
	public string $sentAt = '';
}//end class
