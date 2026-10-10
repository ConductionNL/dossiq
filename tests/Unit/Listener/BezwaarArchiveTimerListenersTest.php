<?php

/**
 * Tests for the two bezwaartermijn timer listeners.
 *
 * The fired listener is asserted to read the trigger FRESH: the objection
 * arrives after the timer is armed, and acting on the copy the timer was
 * armed from would archive a beschikking under objection. The object
 * listener is asserted to leave an unrelated save alone and to act at once on
 * a trigger saved past its archive date.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\BezwaarArchiveTimerFiredListener;
use OCA\Dossiq\Listener\BezwaarArchiveTimerListener;
use OCA\Dossiq\Service\Beschikking\BezwaarArchiveTimer;
use OCA\Dossiq\Service\Beschikking\BezwaarArchiveTrigger;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The fire acts on the fresh trigger; a save syncs only when it moved the timer.
 */
class BezwaarArchiveTimerListenersTest extends TestCase {

	/**
	 * The trigger service.
	 *
	 * @var BezwaarArchiveTrigger&MockObject
	 */
	private BezwaarArchiveTrigger&MockObject $trigger;

	/**
	 * The timer service.
	 *
	 * @var BezwaarArchiveTimer&MockObject
	 */
	private BezwaarArchiveTimer&MockObject $timer;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->trigger = $this->createMock(BezwaarArchiveTrigger::class);
		$this->timer   = $this->createMock(BezwaarArchiveTimer::class);
	}//end setUp()

	/**
	 * The fired listener.
	 *
	 * @return BezwaarArchiveTimerFiredListener
	 */
	private function fired(): BezwaarArchiveTimerFiredListener {
		$account = $this->createMock(BackgroundServiceAccount::class);
		$account->method('runAsWhenNobodyIsSignedIn')->willReturnCallback(static fn (callable $operation): mixed => $operation());

		return new BezwaarArchiveTimerFiredListener(
			trigger: $this->trigger,
			serviceAccount: $account,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end fired()

	/**
	 * The object listener, with the bezwaarTrigger schema configured as id 9.
	 *
	 * @return BezwaarArchiveTimerListener
	 */
	private function saved(): BezwaarArchiveTimerListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => $key === 'bezwaar_trigger_schema' ? '9' : '');

		return new BezwaarArchiveTimerListener(
			settingsService: $settings,
			timer: $this->timer,
			trigger: $this->trigger,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end saved()

	/**
	 * One fire.
	 *
	 * @param string $rungKey The rung key.
	 * @param string $source  The metadata source.
	 *
	 * @return FlowTimerFiredEvent
	 */
	private function fire(string $rungKey = 'slaBreached:0', string $source = BezwaarArchiveTimer::METADATA_SOURCE): FlowTimerFiredEvent {
		$timer = new FlowTimer();
		$timer->setAppId('dossiq');
		$timer->setMetadata(['source' => $source, 'triggerId' => 'trig-1']);

		return new FlowTimerFiredEvent(
			timer: $timer,
			kind: FlowTimerFiredEvent::KIND_RUNG,
			transition: 'escalation:'.$rungKey,
			rungKey: $rungKey,
			recipients: [],
			priority: null,
			message: null
		);
	}//end fire()

	/**
	 * One stored trigger entity.
	 *
	 * @param array<string, mixed> $data   Its fields.
	 * @param string               $schema Its schema id.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $schema = '9'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('trig-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The breach acts on the trigger as read now, not as armed.
	 *
	 * @return void
	 */
	public function testTheBreachActsOnTheFreshTrigger(): void {
		$fresh = ['id' => 'trig-1', 'decisionId' => 'besch-1', 'objectionReceived' => true, 'archiveTriggerActive' => true];
		$this->trigger->expects($this->once())->method('find')->with('trig-1')->willReturn($fresh);
		$this->trigger->expects($this->once())->method('process')->with($fresh);

		$this->fired()->handle(event: $this->fire());
	}//end testTheBreachActsOnTheFreshTrigger()

	/**
	 * Another source, a non-breach rung, and a trigger that is gone do nothing.
	 *
	 * @return void
	 */
	public function testOtherFiresDoNothing(): void {
		$this->trigger->method('find')->willReturn(null);
		$this->trigger->expects($this->never())->method('process');

		$listener = $this->fired();
		$listener->handle(event: $this->fire(source: 'dossiq-advice'));
		$listener->handle(event: $this->fire(rungKey: 'preBreach:3:calendarDays'));
		$listener->handle(event: $this->fire());
	}//end testOtherFiresDoNothing()

	/**
	 * A new trigger is synced; a moved archive date re-syncs.
	 *
	 * @return void
	 */
	public function testANewOrMovedTriggerIsSynced(): void {
		$this->timer->expects($this->exactly(2))->method('sync')->willReturn(BezwaarArchiveTimer::ARMED);
		$this->trigger->expects($this->never())->method('process');
		$listener = $this->saved();

		$listener->handle(event: new ObjectCreatedEvent($this->entity(data: ['archiveDate' => '2026-11-13', 'archiveTriggerActive' => true])));
		$listener->handle(
			event: new ObjectUpdatedEvent(
				$this->entity(data: ['archiveDate' => '2026-11-20', 'archiveTriggerActive' => true]),
				$this->entity(data: ['archiveDate' => '2026-11-13', 'archiveTriggerActive' => true])
			)
		);
	}//end testANewOrMovedTriggerIsSynced()

	/**
	 * A save that moved none of the timed fields, or another schema, is left alone.
	 *
	 * @return void
	 */
	public function testAnUnrelatedSaveIsLeftAlone(): void {
		$this->timer->expects($this->never())->method('sync');
		$listener = $this->saved();

		$listener->handle(
			event: new ObjectUpdatedEvent(
				$this->entity(data: ['archiveDate' => '2026-11-13', 'archiveTriggerActive' => true, 'objectionCaseId' => 'case-9']),
				$this->entity(data: ['archiveDate' => '2026-11-13', 'archiveTriggerActive' => true])
			)
		);
		$listener->handle(event: new ObjectCreatedEvent($this->entity(data: ['archiveDate' => '2026-11-13', 'archiveTriggerActive' => true], schema: '4')));
	}//end testAnUnrelatedSaveIsLeftAlone()

	/**
	 * A trigger saved past its archive date is acted on now.
	 *
	 * @return void
	 */
	public function testADueTriggerIsActedOnNow(): void {
		$this->timer->method('sync')->willReturn(BezwaarArchiveTimer::DUE);
		$this->trigger->expects($this->once())->method('process')
			->with($this->callback(static fn (array $t): bool => $t['id'] === 'trig-1'));

		$this->saved()->handle(event: new ObjectCreatedEvent($this->entity(data: ['archiveDate' => '2026-10-01', 'archiveTriggerActive' => true])));
	}//end testADueTriggerIsActedOnNow()
}//end class
