<?php

/**
 * BezwaarAuditTrail::recordThenApply() golden tests.
 *
 * Pins the real writer's entry-first rule: the entry is written before the
 * change, a failed entry stops the change and is logged, a failed change gets
 * a `-not-applied` entry and is raised as it was, and a not-applied entry
 * that cannot be written goes to the error log.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail;
use OCA\Dossiq\Service\Bezwaar\BezwaarEntryNotWrittenException;
use OCA\Dossiq\Tests\Support\MakesBezwaarAuditTrail;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarEntryCommand
 * @uses \OCA\Dossiq\Service\Support\ObjectAuditEvents
 */
class BezwaarRecordThenApplyGoldenTest extends TestCase {
	use MakesBezwaarAuditTrail;

	/**
	 * Start the store with one hearing session.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->startBezwaarStore();
		$this->store->seed('hearingSession', 'session-1', ['case' => 'case-1']);
	}//end setUp()

	/**
	 * The entry is written first, then the change, whose answer is returned.
	 *
	 * @return void
	 */
	public function testTheEntryComesFirstAndTheChangesAnswerIsReturned(): void {
		$seenAtApply = null;
		$answer = $this->bezwaarAuditTrail()->recordThenApply(
			'dossiq',
			'hearingSession',
			'session-1',
			'hearing-held',
			['case' => 'case-1'],
			BezwaarAuditTrail::TAG_SCHEDULED,
			function () use (&$seenAtApply): string {
				$seenAtApply = $this->trail->actionsOn('session-1');
				return 'applied';
			}
		);

		$this->assertSame('applied', $answer);
		$this->assertSame(['dossiq.bezwaar.hearing-held'], $seenAtApply);
		$this->assertSame(['dossiq.bezwaar.hearing-held'], $this->trail->actionsOn('session-1'));
		$context = $this->rowContext(uuid: 'session-1', action: 'dossiq.bezwaar.hearing-held');
		$this->assertSame(['case' => 'case-1'], $context['payload']);
		$this->assertSame(BezwaarAuditTrail::TAG_SCHEDULED, $context['tag']);
		$this->assertSame([], $this->auditErrors);
	}//end testTheEntryComesFirstAndTheChangesAnswerIsReturned()

	/**
	 * An entry that cannot be written stops the change, and is logged.
	 *
	 * @return void
	 */
	public function testAFailedEntryStopsTheChange(): void {
		$applied = false;
		try {
			$this->bezwaarAuditTrail()->recordThenApply('dossiq', 'hearingSession', 'session-ghost', 'hearing-held', [], '', function () use (&$applied): void {
				$applied = true;
			});
			$this->fail('A failed entry must be raised.');
		} catch (BezwaarEntryNotWrittenException $notWritten) {
			$this->assertSame('dossiq.bezwaar.hearing-held', $notWritten->logContext()['action']);
		}

		$this->assertFalse($applied);
		$this->assertSame([], $this->trail->rows);
		$this->assertCount(1, $this->auditErrors);
		$this->assertSame('Dossiq bezwaar: entry not written, so the change it records is not made', $this->auditErrors[0][0]);
	}//end testAFailedEntryStopsTheChange()

	/**
	 * A change that fails gets a not-applied entry with its error, and is raised as it was.
	 *
	 * @return void
	 */
	public function testAFailedChangeGetsANotAppliedEntry(): void {
		$failure = new RuntimeException('store down');
		try {
			$this->bezwaarAuditTrail()->recordThenApply('dossiq', 'hearingSession', 'session-1', 'hearing-held', ['case' => 'case-1'], 'awb-art-7:2', static function () use ($failure): void {
				throw $failure;
			});
			$this->fail('The change failure must be raised.');
		} catch (RuntimeException $raised) {
			$this->assertSame($failure, $raised);
		}

		$this->assertSame(['dossiq.bezwaar.hearing-held', 'dossiq.bezwaar.hearing-held-not-applied'], $this->trail->actionsOn('session-1'));
		$context = $this->rowContext(uuid: 'session-1', action: 'dossiq.bezwaar.hearing-held-not-applied');
		$this->assertSame(['case' => 'case-1', 'error' => 'store down'], $context['payload']);
		$this->assertSame('awb-art-7:2', $context['tag']);
		$this->assertSame([], $this->auditErrors);
	}//end testAFailedChangeGetsANotAppliedEntry()

	/**
	 * A not-applied entry that cannot be written is logged, and the change failure still raised.
	 *
	 * @return void
	 */
	public function testAnUnwrittenNotAppliedEntryIsLogged(): void {
		$failure = new RuntimeException('store down');
		try {
			$this->bezwaarAuditTrail()->recordThenApply('dossiq', 'hearingSession', 'session-1', 'hearing-held', [], '', function () use ($failure): void {
				$this->trail->failsAll = true;
				throw $failure;
			});
			$this->fail('The change failure must be raised.');
		} catch (RuntimeException $raised) {
			$this->assertSame($failure, $raised);
		}

		$this->assertSame(['dossiq.bezwaar.hearing-held'], $this->trail->actionsOn('session-1'));
		$this->assertCount(1, $this->auditErrors);
		$this->assertSame('Dossiq bezwaar: entry not written', $this->auditErrors[0][0]);
		$this->assertSame('dossiq.bezwaar.hearing-held-not-applied', $this->auditErrors[0][1]['action']);
	}//end testAnUnwrittenNotAppliedEntryIsLogged()
}//end class
