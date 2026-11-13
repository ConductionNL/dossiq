<?php

/**
 * Unit tests for ActionResult and ProjectedAction: an action result answers ok
 * with its error and data only when set, and a projected action reads its
 * type from the action it projects.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Actions;

use OCA\Dossiq\Service\Actions\ActionResult;
use OCA\Dossiq\Service\Actions\ProjectedAction;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Actions\ActionResult
 * @covers \OCA\Dossiq\Service\Actions\ProjectedAction
 */
class ActionValuesTest extends TestCase {

	public function testAResultCarriesErrorAndDataOnlyWhenSet(): void {
		$this->assertSame(['ok' => true], (new ActionResult(succeeded: true))->toArray());
		$this->assertSame(['ok' => false, 'error' => 'template_missing'], (new ActionResult(succeeded: false, error: 'template_missing'))->toArray());
		$this->assertSame(
			['ok' => true, 'data' => ['messageId' => 'm-1']],
			(new ActionResult(succeeded: true, data: ['messageId' => 'm-1'], caseChanges: ['status' => 'x']))->toArray()
		);
		$this->assertSame(['ok' => false, 'error' => ''], (new ActionResult(succeeded: false, error: ''))->toArray());
	}//end testAResultCarriesErrorAndDataOnlyWhenSet()

	public function testAProjectedActionReadsItsType(): void {
		$this->assertSame('sendEmail', (new ProjectedAction(action: ['type' => 'sendEmail'], marker: 'm'))->type());
		$this->assertSame('', (new ProjectedAction(action: [], marker: 'm'))->type());
		$this->assertSame([], (new ProjectedAction(action: [], marker: 'm'))->steps);
	}//end testAProjectedActionReadsItsType()
}//end class
