<?php

/**
 * The services a case's deadline is read and moved through: its terms, pauses and extensions.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Lifecycle
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Lifecycle;

use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;

/**
 * The services a case's deadline is read and moved through: its terms, pauses and extensions.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
final class CaseDeadlineServices {
	/**
	 * Constructor.
	 *
	 * @param TermijnService           $termService      Statutory-term instances
	 * @param DeadlinePauseService     $pauseService     Opschorten / hervatten (Awb 4:5)
	 * @param DeadlineExtensionService $extensionService Verlengen (Awb 4:14)
	 * @param TermijnTimerService|null $timerService     The engine calendar bridge; a statutory term end lands on a day the administered calendar works.
	 */
	public function __construct(
		public readonly TermijnService $termService,
		public readonly DeadlinePauseService $pauseService,
		public readonly DeadlineExtensionService $extensionService,
		public readonly ?TermijnTimerService $timerService = null,
	) {
	}//end __construct()
}//end class
