<?php

/**
 * An advice request assigned by the listener carries its panel row on its own trail.
 *
 * Built on the real listener, AdvisoryCommitteeService and BezwaarAuditTrail,
 * with one in-memory store and a recording audit mapper.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
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

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\BezwaarAdviceRequestedListener;
use OCA\Dossiq\Tests\Support\MakesBezwaarAuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\BezwaarAdviceRequestedListener
 *
 * @uses \OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarEntryNotWrittenException
 */
class BezwaarAdviceRequestedListenerTest extends TestCase {
	use MakesBezwaarAuditTrail;

	protected function setUp(): void {
		parent::setUp();
		$this->startBezwaarStore();
		$this->store->seed('bezwaaradviescommissie', 'committee-1', ['active' => true]);
	}

	/**
	 * The auto-assigned request's panel row is on the request's own trail (REQ-BAT-001).
	 *
	 * @return void
	 */
	public function testAnAutoAssignedRequestCarriesItsPanelRowOnItsOwnTrail(): void {
		$this->listener()->handle($this->objectionMovedTo(status: 'Hearing planned'));

		$this->assertCount(1, $this->store->all('bacAdviceRequest'), 'the listener must assign one advice request');
		$uuid = (string) array_key_first($this->store->rows['bacAdviceRequest']);
		$this->assertSame(['dossiq.bezwaar.panel-member-added'], $this->trail->actionsOn($uuid));

		$context = $this->rowContext(uuid: $uuid, action: 'dossiq.bezwaar.panel-member-added');
		$this->assertSame('handler-1', $context['actor']);
		$this->assertSame('committee-1', $context['payload']['commissieId']);
		$this->assertSame('bezwaar-1', $context['payload']['objectionProceeding']);
		$this->assertArrayNotHasKey('auditTrail', $this->store->row('bacAdviceRequest', $uuid));
	}

	/**
	 * An assignment whose entry fails leaves no request behind (REQ-BAT-003).
	 *
	 * @return void
	 */
	public function testAnAssignmentWhoseEntryFailsLeavesNoRequest(): void {
		$this->trail->failsAll = true;

		$this->listener()->handle($this->objectionMovedTo(status: 'Hearing planned'));

		$this->assertSame(1, $this->store->writes, 'the request was saved before its entry was tried');
		$this->assertSame([], $this->store->all('bacAdviceRequest'), 'an unrecorded request must not survive');
	}

	/**
	 * The real listener over the real service.
	 *
	 * @return BezwaarAdviceRequestedListener The listener.
	 */
	private function listener(): BezwaarAdviceRequestedListener {
		return new BezwaarAdviceRequestedListener(
			bacService: $this->realAdvisoryService(trail: $this->bezwaarAuditTrail(uid: 'handler-1')),
			settingsService: $this->bezwaarSettings(),
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * A bezwaar that moved to the given status.
	 *
	 * @param string $status The new status.
	 *
	 * @return ObjectUpdatedEvent The event.
	 */
	private function objectionMovedTo(string $status): ObjectUpdatedEvent {
		$new = $this->createMock(ObjectEntity::class);
		$new->method('jsonSerialize')->willReturn(['id' => 'bezwaar-1', 'status' => $status, '@self' => ['id' => 'bezwaar-1', 'schema' => 'objectionProceeding']]);
		$old = $this->createMock(ObjectEntity::class);
		$old->method('jsonSerialize')->willReturn(['id' => 'bezwaar-1', 'status' => 'Received']);

		return new ObjectUpdatedEvent($new, $old);
	}
}
