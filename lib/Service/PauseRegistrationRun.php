<?php

/**
 * Dossiq PauseRegistrationRun.
 *
 * One pause while it is being registered on a TermijnInstance: the instance,
 * the days, the note and the document, the declared reason it resolved to and
 * the moment it was registered.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;

/**
 * The state of one pause registration, built and read by
 * DeadlinePauseService only.
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */
final class PauseRegistrationRun {
	/**
	 * The instance id.
	 *
	 * @var string
	 */
	public readonly string $termInstanceId;

	/**
	 * The instance as it stands before the pause.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $instance;

	/**
	 * The pause days requested.
	 *
	 * @var integer
	 */
	public int $durationDays = 0;

	/**
	 * The note beside the pause.
	 *
	 * @var string
	 */
	public string $rationale = '';

	/**
	 * The document link (e.g. hersteltermijnbrief).
	 *
	 * @var string
	 */
	public string $documentLink = '';

	/**
	 * The declared reason the pause is registered under, or [] for none.
	 *
	 * @var array<string, mixed>
	 */
	public array $reason = [];

	/**
	 * The moment the pause is registered; set once the reason is resolved.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $now;

	/**
	 * The day the pause ends (`Y-m-d`); set when the patch is built.
	 *
	 * @var string
	 */
	public string $pauseEnd = '';

	/**
	 * Start registering a pause on an instance.
	 *
	 * @param string               $termInstanceId The instance id.
	 * @param array<string, mixed> $instance       The instance.
	 */
	public function __construct(string $termInstanceId, array $instance) {
		$this->termInstanceId = $termInstanceId;
		$this->instance = $instance;
	}//end __construct()
}//end class
