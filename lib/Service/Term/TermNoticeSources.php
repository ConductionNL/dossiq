<?php

/**
 * What a term notice is made from: the term instances, the notice sender and the letters.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Term
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
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Term;

use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\Termijn\TermLetters;
use OCA\Dossiq\Service\Termijn\TermNoticeSender;

/**
 * What a term notice is made from: the term instances, the notice sender and the letters.
 *
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
 */
final class TermNoticeSources {
	/**
	 * Constructor.
	 *
	 * @param TermijnService   $termService Termijn service.
	 * @param TermNoticeSender $sender      Mails a notice once, after integriq allows it.
	 * @param TermLetters      $letters     The wording of every term notification; defaulted, as it
	 *                                      has no collaborators of its own.
	 */
	public function __construct(
		public readonly TermijnService $termService,
		public readonly TermNoticeSender $sender,
		public readonly TermLetters $letters = new TermLetters(),
	) {
	}//end __construct()
}//end class
