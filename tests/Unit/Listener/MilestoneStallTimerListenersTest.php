<?php

/**
 * Tests for the two milestone stall timer listeners.
 *
 * A reached milestone is a milestone RECORD save, not a case save, so the
 * object listener is asserted to re-sync the record's case; an unrelated case
 * save is left alone. The fired listener is asserted to pass the armed
 * milestone on, so a case that moved on is not told about the old one.
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

use OCA\Dossiq\Listener\MilestoneStallTimerFiredListener;
use OCA\Dossiq\Listener\MilestoneStallTimerListener;
use OCA\Dossiq\Service\Milestone\MilestoneStallActs;
use OCA\Dossiq\Service\Milestone\MilestoneStallTimer;
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
 * Record saves re-sync their case; the fire names the armed milestone.
 */
class MilestoneStallTimerListenersTest extends TestCase {

	/**
	 * The acts.
	 *
	 * @var MilestoneStallActs&MockObject
	 */
	private MilestoneStallActs&MockObject $acts;

	/**
	 * The timer.
	 *
	 * @var MilestoneStallTimer&MockObject
	 */
	private MilestoneStallTimer&MockObject $timer;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->acts  = $this->createMock(MilestoneStallActs::class);
		$this->timer = $this->createMock(MilestoneStallTimer::class);
	}//end setUp()

	/**
	 * The object listener: case schema 3, milestone record schema 5.
	 *
	 * @return MilestoneStallTimerListener
	 */
	private function saved(): MilestoneStallTimerListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ['case_schema' => '3', 'milestone_record_schema' => '5'][$key] ?? '');

		return new MilestoneStallTimerListener(settingsService: $settings, timer: $this->timer, acts: $this->acts, logger: $this->createMock(LoggerInterface::class));
	}//end saved()

	/**
	 * One entity.
	 *
	 * @param array<string, mixed> $data   Its fields.
	 * @param string               $schema Its schema id.
	 * @param string               $uuid   Its id.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $schema, string $uuid = 'case-1'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A reached milestone re-syncs its case, read fresh.
	 *
	 * @return void
	 */
	public function testARecordSaveResyncsItsCase(): void {
		$case = ['id' => 'case-1', 'status' => 'in_handling'];
		$this->acts->expects($this->once())->method('find')->with('case-1')->willReturn($case);
		$this->timer->expects($this->once())->method('sync')->with($case)->willReturn(MilestoneStallTimer::ARMED);

		$this->saved()->handle(event: new ObjectCreatedEvent($this->entity(data: ['case' => 'case-1', 'reached' => true], schema: '5', uuid: 'rec-1')));
	}//end testARecordSaveResyncsItsCase()

	/**
	 * A case save that moved its status syncs, and a stalled one is told now.
	 *
	 * @return void
	 */
	public function testAStatusMoveSyncsAndAStalledCaseIsTold(): void {
		$this->timer->method('sync')->willReturn(MilestoneStallTimer::DUE);
		$this->acts->expects($this->once())->method('notifyIfStalled');

		$this->saved()->handle(
			event: new ObjectUpdatedEvent(
				$this->entity(data: ['status' => 'st-2', 'startDate' => '2026-09-01'], schema: '3'),
				$this->entity(data: ['status' => 'st-1', 'startDate' => '2026-09-01'], schema: '3')
			)
		);
	}//end testAStatusMoveSyncsAndAStalledCaseIsTold()

	/**
	 * An unrelated case save and another schema are left alone.
	 *
	 * @return void
	 */
	public function testOtherSavesAreLeftAlone(): void {
		$this->timer->expects($this->never())->method('sync');
		$listener = $this->saved();

		$listener->handle(
			event: new ObjectUpdatedEvent(
				$this->entity(data: ['status' => 'st-1', 'title' => 'Nieuw'], schema: '3'),
				$this->entity(data: ['status' => 'st-1', 'title' => 'Oud'], schema: '3')
			)
		);
		$listener->handle(event: new ObjectCreatedEvent($this->entity(data: ['status' => 'st-1'], schema: '9')));
	}//end testOtherSavesAreLeftAlone()

	/**
	 * The breach passes the armed milestone to the acts, on the fresh case.
	 *
	 * @return void
	 */
	public function testTheBreachNamesTheArmedMilestone(): void {
		$case = ['id' => 'case-1'];
		$this->acts->method('find')->with('case-1')->willReturn($case);
		$this->acts->expects($this->once())->method('notifyIfStalled')->with($case, 'besluit');

		$account = $this->createMock(BackgroundServiceAccount::class);
		$account->method('runAsWhenNobodyIsSignedIn')->willReturnCallback(static fn (callable $operation): mixed => $operation());
		$timer = new FlowTimer();
		$timer->setAppId('dossiq');
		$timer->setMetadata(['source' => MilestoneStallTimer::METADATA_SOURCE, 'caseId' => 'case-1', 'milestoneIdentifier' => 'besluit']);

		$listener = new MilestoneStallTimerFiredListener(acts: $this->acts, serviceAccount: $account, logger: $this->createMock(LoggerInterface::class));
		$listener->handle(event: new FlowTimerFiredEvent(timer: $timer, kind: FlowTimerFiredEvent::KIND_RUNG, transition: 'escalation:slaBreached:0', rungKey: 'slaBreached:0', recipients: [], priority: null, message: null));

		// Another source's fire is not ours.
		$timer->setMetadata(['source' => 'dossiq-dso', 'caseId' => 'case-1']);
		$listener->handle(event: new FlowTimerFiredEvent(timer: $timer, kind: FlowTimerFiredEvent::KIND_RUNG, transition: 'escalation:slaBreached:0', rungKey: 'slaBreached:0', recipients: [], priority: null, message: null));
	}//end testTheBreachNamesTheArmedMilestone()
}//end class
