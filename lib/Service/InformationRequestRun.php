<?php

/**
 * Dossiq InformationRequestRun.
 *
 * One request for information while it is being made: the case and its
 * running term, what is asked, of whom, for how long and why, and the moment
 * the request was made.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;

/**
 * The state of one request for information, built and read by
 * InformationRequestService only.
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */
final class InformationRequestRun {
	/**
	 * The case UUID.
	 *
	 * @var string
	 */
	public readonly string $caseId;

	/**
	 * The running term, as it stands before the request.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $instance;

	/**
	 * The running term's id.
	 *
	 * @var string
	 */
	public readonly string $instanceId;

	/**
	 * What is asked for, one cleaned line each.
	 *
	 * @var array<int, string>
	 */
	public array $items = [];

	/**
	 * Who the letter goes to.
	 *
	 * @var string
	 */
	public string $recipient = '';

	/**
	 * How many days the applicant is given, at least one.
	 *
	 * @var integer
	 */
	public int $days = 1;

	/**
	 * Why the case cannot be decided yet, as the handler wrote it.
	 *
	 * @var string
	 */
	public string $rationale = '';

	/**
	 * The declared reason the suspension is registered under.
	 *
	 * @var string
	 */
	public string $pauseReason = '';

	/**
	 * When the request was made; set when the letter is sent.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $moment = null;

	/**
	 * Start a request on a case's running term.
	 *
	 * @param string               $caseId   The case UUID.
	 * @param array<string, mixed> $instance The running term.
	 */
	public function __construct(string $caseId, array $instance) {
		$this->caseId = $caseId;
		$this->instance = $instance;
		$this->instanceId = (string)($instance['id'] ?? '');
	}//end __construct()
}//end class
