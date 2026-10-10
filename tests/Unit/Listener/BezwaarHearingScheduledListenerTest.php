<?php

/**
 * A hearing seeded by the listener carries its Awb art. 7:2 row on its own trail.
 *
 * Built on the real listener, HearingService and BezwaarAuditTrail. Only the
 * OpenRegister seams are doubled: one in-memory store and a recording audit
 * mapper.
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
 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\BezwaarHearingScheduledListener;
use OCA\Dossiq\Tests\Support\MakesBezwaarAuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\BezwaarHearingScheduledListener
 *
 * @uses \OCA\Dossiq\Service\Bezwaar\HearingService
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail
 * @uses \OCA\Dossiq\Service\Bezwaar\HearingSchedulePlanner
 * @uses \OCA\Dossiq\Service\Bezwaar\HearingMinutesRecorder
 */
class BezwaarHearingScheduledListenerTest extends TestCase {
	use MakesBezwaarAuditTrail;

	/**
	 * The scheduled hearing's entry is a row on the new session's trail (REQ-BAT-001).
	 *
	 * @return void
	 */
	public function testASeededHearingGetsAnAwb72RowOnItsOwnTrail(): void {
		$this->startBezwaarStore();
		$this->store->seed('objectionProceeding', 'bezwaar-1', ['case' => 'case-1']);

		$listener = new BezwaarHearingScheduledListener(
			hearingService: $this->realHearingService(trail: $this->bezwaarAuditTrail(uid: 'handler-1')),
			settingsService: $this->bezwaarSettings(),
			logger: $this->createMock(LoggerInterface::class),
		);
		$listener->handle($this->objectionMovedTo(status: 'Hearing planned'));

		$sessions = $this->store->all('hearingSession');
		$this->assertCount(1, $sessions, 'the listener must seed one hearing');
		$uuid = (string) array_key_first($this->store->rows['hearingSession']);

		$this->assertSame(['dossiq.bezwaar.hearing-scheduled'], $this->trail->actionsOn($uuid));
		$context = $this->rowContext(uuid: $uuid, action: 'dossiq.bezwaar.hearing-scheduled');
		$this->assertSame('awb-art-7:2', $context['tag']);
		$this->assertSame('handler-1', $context['actor']);
		$this->assertSame(['case', 'scheduledDate', 'inspectionDeadline'], array_keys($context['payload']));
		$this->assertSame('case-1', $context['payload']['case']);
		$this->assertArrayNotHasKey('auditTrail', $this->store->row('hearingSession', $uuid), 'the saved session must carry no auditTrail entry');
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
