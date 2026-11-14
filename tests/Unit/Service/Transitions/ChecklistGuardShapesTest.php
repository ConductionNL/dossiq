<?php

/**
 * ChecklistGuard over malformed engine rows.
 *
 * A non-object task, a checklist that is not a list and an item without a
 * label contribute nothing, while the labelled items beside them still decide.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCA\Dossiq\Service\Transitions\ChecklistGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Transitions\ChecklistGuard
 *
 * @uses \OCA\Dossiq\Service\Transitions\GuardResult
 */
class ChecklistGuardShapesTest extends TestCase {

	/**
	 * The guard over a case whose engine read answers these rows.
	 *
	 * @param array<int, mixed> $rows The engine rows.
	 *
	 * @return ChecklistGuard The guard.
	 */
	private function guard(array $rows): ChecklistGuard {
		$inbox = $this->getMockBuilder(EngineTaskInbox::class)->disableOriginalConstructor()->getMock();
		$inbox->expects($this->once())->method('forCase')->with('case-1', 'jan')->willReturn($rows);
		$inbox->method('lastError')->willReturn('');
		$gateway = $this->getMockBuilder(EngineTaskGateway::class)->disableOriginalConstructor()->getMock();
		$gateway->expects($this->never())->method('find');

		return new ChecklistGuard($inbox, $gateway, new NullLogger(), l10n: new SourceL10n());
	}//end guard()

	/**
	 * Malformed rows and unlabelled items are ignored; the labelled unticked item is missing.
	 *
	 * @return void
	 */
	public function testMalformedRowsAreIgnored(): void {
		$result = $this->guard([
			'not-a-task',
			['checklist' => 'not-a-list'],
			['checklist' => ['scalar-item', ['checked' => false], ['label' => 'Sign', 'checked' => false], ['name' => 'Scan', 'checked' => true]]],
		])->evaluate([], ['id' => 'case-1'], 'jan');

		$this->assertFalse($result->passed);
		$this->assertSame("1 checklist item not ticked: 'Sign'", $result->failureMessage);
		$this->assertSame(['missing' => ['Sign']], $result->details);
	}//end testMalformedRowsAreIgnored()

	/**
	 * Rows carrying no labelled item at all leave nothing unticked, so the guard passes.
	 *
	 * @return void
	 */
	public function testRowsWithoutLabelledItemsPass(): void {
		$result = $this->guard(['x', ['items' => 7], ['items' => [['checked' => false]]]])->evaluate([], ['id' => 'case-1'], 'jan');

		$this->assertTrue($result->passed);
		$this->assertSame([], $result->details);
	}//end testRowsWithoutLabelledItemsPass()
}//end class
