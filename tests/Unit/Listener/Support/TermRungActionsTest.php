<?php

/**
 * What one rung of a known term does, on its own.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener\Support
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Kernel\Contracts\CaseObjectReaderInterface;
use OCA\Dossiq\Kernel\Contracts\DeadlineEscalationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DeadlinePauseServiceInterface;
use OCA\Dossiq\Kernel\Contracts\PauseChaseServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnTimerServiceInterface;
use OCA\Dossiq\Listener\Support\TermRungActions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * TermRungActions: the breach, the threshold, the reminder and the expired pause.
 *
 * @covers \OCA\Dossiq\Listener\Support\TermRungActions
 * @uses   \OCA\Dossiq\Listener\TermijnRungFire
 */
class TermRungActionsTest extends TestCase {

	/**
	 * The term service.
	 *
	 * @var TermijnServiceInterface&MockObject
	 */
	private TermijnServiceInterface&MockObject $terms;

	/**
	 * The escalation service.
	 *
	 * @var DeadlineEscalationServiceInterface&MockObject
	 */
	private DeadlineEscalationServiceInterface&MockObject $escalation;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->terms = $this->createMock(originalClassName: TermijnServiceInterface::class);
		$this->escalation = $this->createMock(originalClassName: DeadlineEscalationServiceInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * The breach rung of a running beslistermijn flips it to exceeded and notifies on the fresh row.
	 *
	 * @return void
	 */
	public function testTheBreachRungFlipsTheTermAndNotifiesOnTheFreshRow(): void {
		$fresh = ['id' => 'ti-1', 'status' => 'exceeded', 'notificatiesVerstuurd' => [7]];
		$this->terms->expects(self::once())->method('updateTermijnInstance')->with('ti-1', ['status' => 'exceeded']);
		$this->terms->expects(self::once())->method('recordEvent')
			->with('ti-1', 'exceeded', 'AWB 4:13', 'Termijn overschreden zonder beschikking', 0);
		$this->terms->method('getTermijnInstance')->with('ti-1')->willReturn($fresh);
		$this->escalation->expects(self::once())->method('notifyThreshold')->with($fresh, 0);

		$this->actions()->route(
			rungKey: 'slaBreached:0',
			instance: ['id' => 'ti-1', 'status' => 'lopend'],
			instanceId: 'ti-1',
			metadata: []
		);
	}//end testTheBreachRungFlipsTheTermAndNotifiesOnTheFreshRow()

	/**
	 * A pre-breach rung notifies its bucket; a finished term and an unreadable key notify nobody.
	 *
	 * @return void
	 */
	public function testAPreBreachRungNotifiesItsBucketOnly(): void {
		$instance = ['id' => 'ti-1', 'status' => 'lopend'];
		$this->terms->expects(self::never())->method('updateTermijnInstance');
		$this->terms->method('getTermijnInstance')->willReturn(null);
		$this->escalation->expects(self::once())->method('notifyThreshold')->with($instance, 7);

		$actions = $this->actions();
		$actions->route(rungKey: 'preBreach:7:calendarDays', instance: $instance, instanceId: 'ti-1', metadata: []);
		$actions->route(
			rungKey: 'preBreach:7',
			instance: ['id' => 'ti-2', 'status' => 'completed'],
			instanceId: 'ti-2',
			metadata: []
		);
		$actions->route(rungKey: 'escalated:2', instance: $instance, instanceId: 'ti-1', metadata: []);
	}//end testAPreBreachRungNotifiesItsBucketOnly()

	/**
	 * On a hersteltermijn a reminder rung chases, and the run-out rung of a term no longer paused does nothing.
	 *
	 * @return void
	 */
	public function testAHersteltermijnReminderChasesAndAResumedTermIsLeftAlone(): void {
		$paused = ['id' => 'ti-1', 'case' => 'case-1', 'status' => 'paused'];
		$chases = $this->createMock(originalClassName: PauseChaseServiceInterface::class);
		$chases->expects(self::once())->method('chaseIfDue')->with($paused);
		$pauses = $this->createMock(originalClassName: DeadlinePauseServiceInterface::class);
		$pauses->expects(self::never())->method('resumeAfterExpiry');
		$this->terms->expects(self::never())->method('recordEvent');
		$this->escalation->expects(self::never())->method('notifyThreshold');

		$actions = $this->actions(chases: $chases, pauses: $pauses);
		$metadata = ['kind' => TermijnTimerServiceInterface::KIND_HERSTELTERMIJN];
		$actions->route(rungKey: 'preBreach:3', instance: $paused, instanceId: 'ti-1', metadata: $metadata);
		$actions->route(
			rungKey: 'slaBreached:0',
			instance: ['id' => 'ti-1', 'case' => 'case-1', 'status' => 'lopend'],
			instanceId: 'ti-1',
			metadata: $metadata
		);
	}//end testAHersteltermijnReminderChasesAndAResumedTermIsLeftAlone()

	/**
	 * An expired pause is recorded and resumed with the handler told; a failing resume is logged.
	 *
	 * @return void
	 */
	public function testAnExpiredPauseIsRecordedAndResumedAndAFailingResumeIsLogged(): void {
		$this->terms->expects(self::exactly(count: 2))->method('recordEvent')
			->with('ti-1', 'pause-expired', 'AWB 4:5', 'Pauzetermijn verlopen zonder aanvulling', 0);

		$cases = $this->createMock(originalClassName: CaseObjectReaderInterface::class);
		$cases->method('fetchCaseById')->with('case-1')->willReturn(['title' => ' Dakkapel ', 'assignee' => 'handler-1']);

		$pauses = $this->createMock(originalClassName: DeadlinePauseServiceInterface::class);
		$pauses->expects(self::exactly(count: 2))->method('resumeAfterExpiry')
			->with('ti-1', ['notify' => 'handler-1', 'caseTitle' => 'Dakkapel'])
			->willReturnOnConsecutiveCalls([], self::throwException(exception: new RuntimeException('busy')));

		$this->logger->expects(self::once())->method('warning')->with(
			'Dossiq termijn: a term whose pause ran out could not be resumed',
			['case' => 'case-1', 'instance' => 'ti-1', 'error' => 'busy'],
		);

		$actions = $this->actions(pauses: $pauses, cases: $cases);
		$paused = ['id' => 'ti-1', 'case' => 'case-1', 'status' => 'paused'];
		$metadata = ['kind' => TermijnTimerServiceInterface::KIND_HERSTELTERMIJN];
		$actions->route(rungKey: 'slaBreached:0', instance: $paused, instanceId: 'ti-1', metadata: $metadata);
		$actions->route(rungKey: 'slaBreached:0', instance: $paused, instanceId: 'ti-1', metadata: $metadata);
	}//end testAnExpiredPauseIsRecordedAndResumedAndAFailingResumeIsLogged()

	/**
	 * The actions.
	 *
	 * @param PauseChaseServiceInterface|null    $chases The chase service.
	 * @param DeadlinePauseServiceInterface|null $pauses The pause service.
	 * @param CaseObjectReaderInterface|null     $cases  The case reader.
	 *
	 * @return TermRungActions The actions.
	 */
	private function actions(
		?PauseChaseServiceInterface $chases = null,
		?DeadlinePauseServiceInterface $pauses = null,
		?CaseObjectReaderInterface $cases = null,
	): TermRungActions {
		return new TermRungActions(
			termService: $this->terms,
			escalationService: $this->escalation,
			logger: $this->logger,
			chases: $chases,
			pauses: $pauses,
			cases: $cases,
		);
	}//end actions()
}//end class
