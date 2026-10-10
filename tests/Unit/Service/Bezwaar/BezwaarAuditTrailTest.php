<?php

/**
 * BezwaarAuditTrail writes each Awb entry onto OpenRegister's trail of its record.
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

use OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail;
use OCA\Dossiq\Service\Bezwaar\BezwaarEntryNotWrittenException;
use OCA\Dossiq\Tests\Support\MakesBezwaarAuditTrail;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail
 * @covers \OCA\Dossiq\Service\Bezwaar\BezwaarEntryNotWrittenException
 */
class BezwaarAuditTrailTest extends TestCase {
	use MakesBezwaarAuditTrail;

	protected function setUp(): void {
		parent::setUp();
		$this->startBezwaarStore();
		$this->store->seed('hearingSession', 'session-1', ['case' => 'case-1']);
	}

	/**
	 * One record() call is one tagged row on the record's own trail (REQ-BAT-001).
	 *
	 * @return void
	 */
	public function testRecordWritesOneTaggedRowThroughTheMapper(): void {
		$this->bezwaarAuditTrail()->record(
			register: 'dossiq',
			schema: 'hearingSession',
			objectUuid: 'session-1',
			event: 'hearing-scheduled',
			payload: ['case' => 'case-1'],
			tag: BezwaarAuditTrail::TAG_SCHEDULED,
		);

		$this->assertSame(['dossiq.bezwaar.hearing-scheduled'], $this->trail->actionsOn('session-1'));
		$context = $this->rowContext(uuid: 'session-1', action: 'dossiq.bezwaar.hearing-scheduled');
		$this->assertSame('awb-art-7:2', $context['tag']);
		$this->assertSame(['case' => 'case-1'], $context['payload']);
	}

	/**
	 * The context keeps the entry's key order, and an untagged entry has no tag.
	 *
	 * @return void
	 */
	public function testTheContextKeepsTheEntryKeyOrder(): void {
		$trail = $this->bezwaarAuditTrail();
		$trail->record(register: 'dossiq', schema: 'hearingSession', objectUuid: 'session-1', event: 'hearing-waived', payload: [], tag: BezwaarAuditTrail::TAG_WAIVER);
		$trail->record(register: 'dossiq', schema: 'hearingSession', objectUuid: 'session-1', event: 'panel-member-added', payload: []);

		$this->assertSame(['event', 'tag', 'actor', 'at', 'payload'], array_keys($this->rowContext(uuid: 'session-1', action: 'dossiq.bezwaar.hearing-waived')));
		$this->assertSame(['event', 'actor', 'at', 'payload'], array_keys($this->rowContext(uuid: 'session-1', action: 'dossiq.bezwaar.panel-member-added')));
	}

	/**
	 * The actor is the session's, never the payload's, and `system` without a session.
	 *
	 * @return void
	 */
	public function testTheActorComesFromTheSessionNeverThePayload(): void {
		$this->bezwaarAuditTrail(uid: 'handler-1')->record(
			register: 'dossiq',
			schema: 'hearingSession',
			objectUuid: 'session-1',
			event: 'verslag-recorded',
			payload: ['actor' => 'someone-else'],
			tag: BezwaarAuditTrail::TAG_VERSLAG,
		);
		$this->bezwaarAuditTrail(uid: null)->record(register: 'dossiq', schema: 'hearingSession', objectUuid: 'session-1', event: 'hearing-waived', payload: []);

		$this->assertSame('handler-1', $this->rowContext(uuid: 'session-1', action: 'dossiq.bezwaar.verslag-recorded')['actor']);
		$this->assertSame('system', $this->rowContext(uuid: 'session-1', action: 'dossiq.bezwaar.hearing-waived')['actor']);
	}

	/**
	 * Without OpenRegister, an unknown record or a failing store, record() throws.
	 *
	 * @return void
	 */
	public function testRecordThrowsWhenOpenRegisterIsAbsent(): void {
		$cases = [
			'OpenRegister absent' => [$this->bezwaarAuditTrail(openRegister: false), 'session-1'],
			'unknown record'      => [$this->bezwaarAuditTrail(), 'session-ghost'],
		];
		foreach ($cases as $label => [$trail, $uuid]) {
			try {
				$trail->record(register: 'dossiq', schema: 'hearingSession', objectUuid: $uuid, event: 'hearing-scheduled', payload: []);
				$this->fail($label.': record() must throw');
			} catch (BezwaarEntryNotWrittenException $notWritten) {
				$this->assertInstanceOf(RuntimeException::class, $notWritten);
				$this->assertSame('dossiq.bezwaar.hearing-scheduled', $notWritten->logContext()['action']);
			}
		}

		$this->assertSame([], $this->trail->rows, 'a refused write must leave no row');

		$this->trail->failsAll = true;
		$this->expectException(BezwaarEntryNotWrittenException::class);
		$this->bezwaarAuditTrail()->record(register: 'dossiq', schema: 'hearingSession', objectUuid: 'session-1', event: 'hearing-scheduled', payload: []);
	}
}
