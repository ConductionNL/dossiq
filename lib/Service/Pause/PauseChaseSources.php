<?php

/**
 * What a pause chase reads and sends through: the pause reasons, the chase schedule and the notifications.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Pause
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
 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Pause;

use OCA\Dossiq\Service\TermijnNotificationService;

/**
 * What a pause chase reads and sends through: the pause reasons, the chase schedule and the notifications.
 *
 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
 */
final class PauseChaseSources {
	/**
	 * Constructor.
	 *
	 * @param PauseReasonReader          $reasons       What the case type declared.
	 * @param ChaseSchedule              $schedule      When the next reminder is due.
	 * @param TermijnNotificationService $notifications The one route a citizen letter leaves by.
	 */
	public function __construct(
		public readonly PauseReasonReader $reasons,
		public readonly ChaseSchedule $schedule,
		public readonly TermijnNotificationService $notifications,
	) {
	}//end __construct()
}//end class
