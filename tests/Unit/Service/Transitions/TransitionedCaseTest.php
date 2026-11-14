<?php

/**
 * TransitionedCase carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Transitions\TransitionedCase;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\TransitionedCase
 */
class TransitionedCaseTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$case = ['key' => 1];
		$transitionContext = ['key' => 2];

		$value = new TransitionedCase(
			case: $case,
			transitionContext: $transitionContext,
		);

		self::assertSame(expected: $case, actual: $value->case);
		self::assertSame(expected: $transitionContext, actual: $value->transitionContext);
	}//end testEveryPropertyReadsBack()
}//end class
