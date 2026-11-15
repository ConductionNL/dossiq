<?php

/**
 * Dossiq BeschikkingSendRun.
 *
 * One beschikking while it is being sent: which one and by whom, and, as the
 * send goes, the stored beschikking, the status it was in, the term the case
 * type declares, what the transport answered, the day it was announced and
 * the objection term that day starts.
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
 * @spec openspec/changes/archive/2026-06-13-beschikking-generatie/tasks.md#T09
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Beschikking;

/**
 * The state of one send, built by BeschikkingService::verzend() and read by
 * BeschikkingService and BeschikkingSendRecorder only.
 *
 * @spec openspec/changes/archive/2026-06-13-beschikking-generatie/tasks.md#T09
 */
final class BeschikkingSendRun {

	/**
	 * The stored beschikking, as it was before the send.
	 *
	 * @var array<string, mixed>
	 */
	public array $decision = [];

	/**
	 * The status the beschikking was in.
	 *
	 * @var string
	 */
	public string $current = '';

	/**
	 * The objection term in days the case type declares, 0 when it declares none.
	 *
	 * @var integer
	 */
	public int $declaredDays = 0;

	/**
	 * What the transport answered.
	 *
	 * @var array<string, mixed>
	 */
	public array $dispatch = [];

	/**
	 * The day the beschikking was announced (Y-m-d).
	 *
	 * @var string
	 */
	public string $announcedOn = '';

	/**
	 * The objection term that day starts: endDate and herinnering.
	 *
	 * @var array<string, mixed>
	 */
	public array $term = [];

	/**
	 * Constructor.
	 *
	 * @param string $decisionId The beschikking UUID.
	 * @param string $actor      The dispatching user's UID.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $decisionId,
		public readonly string $actor,
	) {
	}//end __construct()
}//end class
