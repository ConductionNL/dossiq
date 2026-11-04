<?php

/**
 * Where a case's terms are read from: its term instances, the case type's term declarations and the engine timers.
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
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Term;

use OCA\Dossiq\Service\TermDeclarationReader;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;

/**
 * Where a case's terms are read from: its term instances, the case type's term declarations and the engine timers.
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */
final class CaseTermSources {
	/**
	 * Constructor.
	 *
	 * @param TermijnService        $termService  The one writer of a term instance.
	 * @param TermDeclarationReader $declarations What the case type and its phases declare.
	 * @param TermijnTimerService   $timers       The engine calendar bridge.
	 */
	public function __construct(
		public readonly TermijnService $termService,
		public readonly TermDeclarationReader $declarations,
		public readonly TermijnTimerService $timers,
	) {
	}//end __construct()
}//end class
