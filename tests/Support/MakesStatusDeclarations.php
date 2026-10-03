<?php

/**
 * A status-declarations double that declares nothing.
 *
 * Every existing `StatusTransitionService` test is about the engine, not about
 * what a status declares, and on a case type that declares nothing the engine
 * has to behave exactly as it did before this collaborator existed. That is
 * what this double states: no status is derived, no status waits on anybody in
 * particular, no maximum, and a move that changes no field of the case.
 *
 * `applyStatusChange` answers with the payload it was handed, unchanged, which
 * is what keeps those tests asserting on the engine's own writes rather than
 * on the dwell bookkeeping. The dwell bookkeeping has its own tests, over the
 * real service, in tests/Unit/Service/Status.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
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
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\Dossiq\Service\Status\StatusDeclarations;

/**
 * Builds a StatusDeclarations double for a case type that declares nothing.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
trait MakesStatusDeclarations {
	/**
	 * A declarations double that answers the undeclared case for everything.
	 *
	 * @return StatusDeclarations
	 */
	private function undeclaredStatuses(): StatusDeclarations {
		$declarations = $this->createMock(originalClassName: StatusDeclarations::class);
		$declarations->method('panelFor')->willReturn(
			[
				'waitingOn' => 'us',
				'dwell' => ['days' => 0, 'maximum' => null, 'breached' => false, 'enteredAt' => ''],
				'derivation' => null,
			]
		);
		$declarations->method('isDerivedStatus')->willReturn(false);
		$declarations->method('applyStatusChange')->willReturnArgument(0);
		$declarations->method('retime')->willReturn(null);

		return $declarations;
	}//end undeclaredStatuses()
}//end trait
