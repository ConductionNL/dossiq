<?php

/**
 * Dossiq unwired requester notice.
 *
 * The answer a controller gives when no sender is wired for a requester
 * notice: the notice was not sent, and the requester was not told.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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
 * @spec exclude shared notice outcome; each using controller carries its own spec
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

/**
 * The not-sent notice outcome when no sender is wired.
 *
 * @spec exclude shared notice outcome; each using controller carries its own spec
 */
final class UnwiredNotice {

	/**
	 * The notice outcome: not sent, no channel, and why.
	 *
	 * @var array<string, string>
	 */
	public const OUTCOME = [
		'noticeStatus' => 'not-sent',
		'noticeChannel' => '',
		'noticeReasonCode' => 'notice-not-wired',
		'noticeReason' => 'No sender is wired, so the requester was not told.',
	];
}//end class
