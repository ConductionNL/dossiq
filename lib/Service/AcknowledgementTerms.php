<?php

/**
 * The terms an acknowledgement reads and the notices it sends through.
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
 * @spec openspec/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The terms an acknowledgement reads and the notices it sends through.
 *
 * @spec openspec/specs/burger-notifications/spec.md
 */
final class AcknowledgementTerms {
	/**
	 * Constructor.
	 *
	 * @param TermijnService             $termService   The statutory term bound to the case, when there is one.
	 * @param TermijnNotificationService $notifications The template renderer and the notification router.
	 */
	public function __construct(
		public readonly TermijnService $termService,
		public readonly TermijnNotificationService $notifications,
	) {
	}//end __construct()
}//end class
