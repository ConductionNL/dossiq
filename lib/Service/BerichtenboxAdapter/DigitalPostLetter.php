<?php

/**
 * Dossiq DigitalPostLetter.
 *
 * One letter on its way to integriq's digital post: who it is for, what it
 * says, and the seam and source it travels over once they are known.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\BerichtenboxAdapter
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
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\BerichtenboxAdapter;

/**
 * The letter one send carries, built and read by IntegriqAdapter only.
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */
final class DigitalPostLetter {
	/**
	 * The recipient's BSN.
	 *
	 * @var string
	 */
	public readonly string $recipient;

	/**
	 * The letter's subject.
	 *
	 * @var string
	 */
	public readonly string $subject;

	/**
	 * The letter's plain text body.
	 *
	 * @var string
	 */
	public string $body = '';

	/**
	 * Attachment content, base64, or null.
	 *
	 * @var string|null
	 */
	public ?string $attachment = null;

	/**
	 * What the letter is; integriq decides on it.
	 *
	 * @var string
	 */
	public string $category = 'case-update';

	/**
	 * The case, so a case opt-out can match.
	 *
	 * @var string
	 */
	public string $caseRef = '';

	/**
	 * Integriq's send event class, once resolved.
	 *
	 * @var string
	 */
	public string $eventClass = '';

	/**
	 * The digital post source the letter goes over, once read.
	 *
	 * @var string
	 */
	public string $sourceId = '';

	/**
	 * Start a letter to one recipient.
	 *
	 * @param string $recipient The recipient's BSN.
	 * @param string $subject   The letter's subject.
	 */
	public function __construct(string $recipient, string $subject) {
		$this->recipient = $recipient;
		$this->subject = $subject;
	}//end __construct()
}//end class
