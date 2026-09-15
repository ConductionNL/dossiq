<?php

/**
 * A transition-declarations double for a template that declares nothing.
 *
 * Every existing `StatusTransitionService` test is about the engine, not about
 * what a transition declares, and on a workflow that declares nothing the
 * engine has to behave exactly as it did before this collaborator existed.
 * That is what this double states: nothing is withheld, no transition carries
 * an explanation, and nobody is refused for having performed an earlier act.
 *
 * The withheld answer is an EMPTY LIST rather than a null, because that is the
 * shape the engine reads as "nothing is in the way". A double that answered
 * null would make every transition available for a reason the production code
 * never has.
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
 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\Dossiq\Service\Transitions\TransitionDeclarations;

/**
 * Builds a TransitionDeclarations double for a workflow that declares nothing.
 *
 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
 */
trait MakesTransitionDeclarations {
	/**
	 * A declarations double that answers the undeclared transition everywhere.
	 *
	 * @return TransitionDeclarations
	 */
	private function undeclaredTransitions(): TransitionDeclarations {
		$declarations = $this->createMock(originalClassName: TransitionDeclarations::class);
		$declarations->method('withheldReasons')->willReturn([]);
		$declarations->method('explanationOf')->willReturn('');
		$declarations->method('fourEyesRefusal')->willReturn(null);

		return $declarations;
	}//end undeclaredTransitions()
}//end trait
