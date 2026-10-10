<?php

/**
 * Tests for the two DSO term timer listeners.
 *
 * Cases are saved for every reason, so the object listener is asserted to
 * leave a case alone unless the save moved its DSO status or deadline, and to
 * leave a case that never was a DSO case alone entirely. The fired listener
 * is asserted to pick the band by the rung's message and to act on the case
 * as read now.
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

use OCA\Dossiq\Listener\DsoDeadlineTimerFiredListener;
use OCA\Dossiq\Listener\DsoDeadlineTimerListener;
use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Service\Dso\DsoDeadlineActs;
use OCA\Dossiq\Service\Dso\DsoDeadlineTimer;
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
 * Band by message, breach marks overdue, only DSO saves sync.
 */
class DsoDeadlineTimerListenersTest extends TestCase {

	/**
	 * The acts.
	 *
	 * @var DsoDeadlineActs&MockObject
	 */
	private DsoDeadlineActs&MockObject $acts;

	/**
	 * The timer.
	 *
	 * @var DsoDeadlineTimer&MockObject
	 */
	private DsoDeadlineTimer&MockObject $timer;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->acts  = $this->createMock(DsoDeadlineActs::class);
		$this->timer = $this->createMock(DsoDeadlineTimer::class);
	}//end setUp()

	/**
	 * The fired listener.
	 *
	 * @return DsoDeadlineTimerFiredListener
	 */
	private function fired(): DsoDeadlineTimerFiredListener {
		$account = $this->createMock(BackgroundServiceAccount::class);
		$account->method('runAsWhenNobodyIsSignedIn')->willReturnCallback(static fn (callable $operation): mixed => $operation());

		return new DsoDeadlineTimerFiredListener(acts: $this->acts, serviceAccount: $account, logger: $this->createMock(LoggerInterface::class));
	}//end fired()

	/**
	 * The object listener, with the case schema configured as id 3.
	 *
	 * @return DsoDeadlineTimerListener
	 */
	private function saved(): DsoDeadlineTimerListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => $key === 'case_schema' ? '3' : '');

		return new DsoDeadlineTimerListener(settingsService: $settings, timer: $this->timer, acts: $this->acts, logger: $this->createMock(LoggerInterface::class));
	}//end saved()

	/**
	 * One fire.
	 *
	 * @param string      $rungKey The rung key.
	 * @param string|null $message The rung message.
	 *
	 * @return FlowTimerFiredEvent
	 */
	private function fire(string $rungKey, ?string $message): FlowTimerFiredEvent {
		$timer = new FlowTimer();
		$timer->setAppId('dossiq');
		$timer->setMetadata(['source' => DsoDeadlineTimer::METADATA_SOURCE, 'caseId' => 'case-1']);

		return new FlowTimerFiredEvent(timer: $timer, kind: FlowTimerFiredEvent::KIND_RUNG, transition: 'escalation:'.$rungKey, rungKey: $rungKey, recipients: [], priority: null, message: $message);
	}//end fire()

	/**
	 * One case entity.
	 *
	 * @param array<string, mixed> $data   Its fields.
	 * @param string               $schema Its schema id.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $schema = '3'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('case-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The two bands notify with their own subject; the breach marks overdue.
	 *
	 * @return void
	 */
	public function testEachRungDoesItsAct(): void {
		$case = ['id' => 'case-1', 'dsoStatus' => 'in_handling', 'assignee' => 'pjansen'];
		$this->acts->method('find')->with('case-1')->willReturn($case);
		$subjects = [];
		$this->acts->method('notify')->willReturnCallback(
			static function (array $c, string $subject) use (&$subjects): string {
				$subjects[] = $subject;
				return DsoDeadlineActs::NOTIFIED;
			}
		);
		$this->acts->expects($this->once())->method('markOverdue')->with($case);

		$listener = $this->fired();
		$listener->handle(event: $this->fire(rungKey: 'preBreach:14:businessDays', message: DsoDeadlineTimer::MESSAGE_WARNING));
		$listener->handle(event: $this->fire(rungKey: 'preBreach:5:businessDays', message: DsoDeadlineTimer::MESSAGE_CRITICAL));
		$listener->handle(event: $this->fire(rungKey: 'slaBreached:0', message: 'dso-termijn-overschreden'));

		$this->assertSame(expected: [Notifier::SUBJECT_DSO_DEADLINE_WARNING, Notifier::SUBJECT_DSO_DEADLINE_CRITICAL], actual: $subjects);
	}//end testEachRungDoesItsAct()

	/**
	 * A case that is gone does nothing.
	 *
	 * @return void
	 */
	public function testACaseThatIsGoneDoesNothing(): void {
		$this->acts->method('find')->willReturn(null);
		$this->acts->expects($this->never())->method('markOverdue');

		$this->fired()->handle(event: $this->fire(rungKey: 'slaBreached:0', message: null));
	}//end testACaseThatIsGoneDoesNothing()

	/**
	 * A moved deadline or DSO status syncs; a case past its deadline is marked now.
	 *
	 * @return void
	 */
	public function testAMovedDeadlineSyncsAndADueOneIsMarked(): void {
		$this->timer->expects($this->exactly(2))->method('sync')->willReturnOnConsecutiveCalls(DsoDeadlineTimer::ARMED, DsoDeadlineTimer::DUE);
		$this->acts->expects($this->once())->method('markOverdue');
		$listener = $this->saved();

		$listener->handle(event: new ObjectCreatedEvent($this->entity(data: ['dsoStatus' => 'submitted', 'deadlineDate' => '2026-12-31'])));
		$listener->handle(
			event: new ObjectUpdatedEvent(
				$this->entity(data: ['dsoStatus' => 'in_handling', 'deadlineDate' => '2026-10-01']),
				$this->entity(data: ['dsoStatus' => 'in_handling', 'deadlineDate' => '2026-12-31'])
			)
		);
	}//end testAMovedDeadlineSyncsAndADueOneIsMarked()

	/**
	 * An unrelated save, a non-DSO case and another schema are left alone.
	 *
	 * @return void
	 */
	public function testOtherSavesAreLeftAlone(): void {
		$this->timer->expects($this->never())->method('sync');
		$listener = $this->saved();

		$listener->handle(
			event: new ObjectUpdatedEvent(
				$this->entity(data: ['dsoStatus' => 'in_handling', 'deadlineDate' => '2026-12-31', 'title' => 'Nieuw']),
				$this->entity(data: ['dsoStatus' => 'in_handling', 'deadlineDate' => '2026-12-31', 'title' => 'Oud'])
			)
		);
		$listener->handle(event: new ObjectCreatedEvent($this->entity(data: ['title' => 'Melding', 'deadlineDate' => '2026-12-31'])));
		$listener->handle(event: new ObjectCreatedEvent($this->entity(data: ['dsoStatus' => 'submitted', 'deadlineDate' => '2026-12-31'], schema: '8')));
	}//end testOtherSavesAreLeftAlone()
}//end class
