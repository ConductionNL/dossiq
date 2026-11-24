<?php

/**
 * Dossiq Term Pause Follow Up.
 *
 * The optional collaborators of a suspended term, handed to `TermRungActions` as one value: the request the
 * hersteltermijn belongs to, its reminders, its resume and the case it belongs to. Each may be missing, and the
 * term then fires exactly as it did before that collaborator existed.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use OCA\Dossiq\Kernel\Contracts\AanvullingsverzoekResolutionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseObjectReaderInterface;
use OCA\Dossiq\Kernel\Contracts\DeadlinePauseServiceInterface;
use OCA\Dossiq\Kernel\Contracts\PauseChaseServiceInterface;

/**
 * The optional collaborators of a suspended term.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class TermPauseFollowUp {

	/**
	 * Constructor.
	 *
	 * @param AanvullingsverzoekResolutionServiceInterface|null $aanvullingen The request the hersteltermijn
	 *        belonged to, so the day it runs out is recorded on the request too.
	 * @param PauseChaseServiceInterface|null $chases The reminders on a suspended term: whether one is due.
	 * @param DeadlinePauseServiceInterface|null $pauses Resumes a term whose pause ran out (REQ-TERM-080).
	 * @param CaseObjectReaderInterface|null $cases Reads the case behind the term, for the handler the
	 *        resume is told to and the title it names.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		public readonly ?AanvullingsverzoekResolutionServiceInterface $aanvullingen = null,
		public readonly ?PauseChaseServiceInterface $chases = null,
		public readonly ?DeadlinePauseServiceInterface $pauses = null,
		public readonly ?CaseObjectReaderInterface $cases = null,
	) {
	}//end __construct()
}//end class
