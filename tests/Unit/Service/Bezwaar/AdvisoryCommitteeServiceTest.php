<?php

/**
 * AdvisoryCommitteeService records the chair's signature and an independence failure on the request's trail.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\Transitions\GuardFailedException;
use OCA\Dossiq\Tests\Support\MakesBezwaarAuditTrail;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService
 *
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarEntryNotWrittenException
 * @uses \OCA\Dossiq\Service\Transitions\GuardFailedException
 */
class AdvisoryCommitteeServiceTest extends TestCase {
	use MakesBezwaarAuditTrail;

	protected function setUp(): void {
		parent::setUp();
		$this->startBezwaarStore();
	}

	/**
	 * A request in deliberation with its advice content complete.
	 *
	 * @param string $uuid The request.
	 *
	 * @return void
	 */
	private function seedDeliberation(string $uuid): void {
		$this->store->seed('bacAdviceRequest', $uuid, [
			'bezwaar' => 'bezwaar-1',
			'status' => 'in-deliberation',
			'panel' => ['member-1'],
			'conclusion' => 'Bezwaar gegrond',
			'recommendation' => 'herroepen',
		]);
	}

	/**
	 * The chair's signature is recorded before the status moves (REQ-BAT-003).
	 *
	 * @return void
	 */
	public function testASignedAdviceIsRecordedBeforeTheStatusMoves(): void {
		$this->seedDeliberation(uuid: 'req-1');
		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail(uid: 'chair-1'));

		$service->transitionAdviceStatus(requestId: 'req-1', newStatus: 'advice-issued');

		$this->assertSame(['dossiq.bezwaar.advice-signed-by-chair'], $this->trail->actionsOn('req-1'));
		$this->assertSame('chair-1', $this->rowContext(uuid: 'req-1', action: 'dossiq.bezwaar.advice-signed-by-chair')['payload']['chair']);
		$this->assertSame('advice-issued', $this->store->row('bacAdviceRequest', 'req-1')['status']);
		$this->assertArrayNotHasKey('auditTrail', $this->store->row('bacAdviceRequest', 'req-1'));

		// Entry first: when it cannot be written, the status does not move.
		$this->seedDeliberation(uuid: 'req-2');
		$this->trail->failsAll = true;
		try {
			$service->transitionAdviceStatus(requestId: 'req-2', newStatus: 'advice-issued');
			$this->fail('a signature that cannot be recorded must not move the status');
		} catch (RuntimeException $refused) {
			$this->assertSame('in-deliberation', $this->store->row('bacAdviceRequest', 'req-2')['status']);
		}
	}

	/**
	 * A failed status write after the entry leaves a not-applied row (REQ-BAT-003).
	 *
	 * @return void
	 */
	public function testAFailedStatusWriteLeavesANotAppliedRow(): void {
		$this->seedDeliberation(uuid: 'req-1');
		$this->store->refuseSaves = true;

		try {
			$this->realAdvisoryService(trail: $this->bezwaarAuditTrail())->transitionAdviceStatus(requestId: 'req-1', newStatus: 'advice-issued');
			$this->fail('a failed status write must raise');
		} catch (RuntimeException $failed) {
			$this->assertSame('Could not transition advice request', $failed->getMessage());
		}

		$this->assertSame(
			['dossiq.bezwaar.advice-signed-by-chair', 'dossiq.bezwaar.advice-signed-by-chair-not-applied'],
			$this->trail->actionsOn('req-1')
		);
	}

	/**
	 * An independence failure is recorded and refused, also when its entry fails (REQ-BAT-003).
	 *
	 * @return void
	 */
	public function testAFailedIndependenceCheckIsRecordedAndRefused(): void {
		$this->store->seed('bacAdviceRequest', 'req-1', ['bezwaar' => 'bezwaar-1', 'status' => 'assigned', 'panel' => ['member-1']]);
		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail(), conflict: 'member-1');

		try {
			$service->transitionAdviceStatus(requestId: 'req-1', newStatus: 'in-deliberation');
			$this->fail('a conflicted panel must be refused');
		} catch (GuardFailedException $refused) {
			$this->assertSame('member-1', $this->rowContext(uuid: 'req-1', action: 'dossiq.bezwaar.independence-check-failed')['payload']['conflictingMember']);
		}

		$this->trail->failsAll = true;
		try {
			$service->transitionAdviceStatus(requestId: 'req-1', newStatus: 'in-deliberation');
			$this->fail('a conflicted panel must be refused even when its entry fails');
		} catch (GuardFailedException $refused) {
			$this->assertSame('dossiq.bezwaar.independence-check-failed', $this->auditErrors[0][1]['action'] ?? null, 'the unwritten refusal must be logged with its entry');
		}

		$this->assertSame('assigned', $this->store->row('bacAdviceRequest', 'req-1')['status']);
	}
}
