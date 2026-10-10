<?php

/**
 * Tests for the advice timer fire: the reminder and the expiry.
 *
 * The negative cases matter as much as the positive ones. The engine fires
 * every app's timers through one event, so a listener that acted on a term's
 * rung would send an advisor a reminder for a beslistermijn, or expire an
 * advice request on another app's deadline.
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

use OCA\Dossiq\Listener\AdviceTimerFiredListener;
use OCA\Dossiq\Service\Advice\AdviceTimer;
use OCA\Dossiq\Service\AdviceService;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Routing one fire to the reminder or the expiry, and only dossiq's advice fires.
 */
class AdviceTimerFiredListenerTest extends TestCase {

	/**
	 * The advice service the listener drives.
	 *
	 * @var AdviceService&MockObject
	 */
	private AdviceService&MockObject $advice;

	/**
	 * The service account the fire runs as.
	 *
	 * @var BackgroundServiceAccount&MockObject
	 */
	private BackgroundServiceAccount&MockObject $account;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->advice  = $this->createMock(AdviceService::class);
		$this->account = $this->createMock(BackgroundServiceAccount::class);
		$this->account->method('runAsWhenNobodyIsSignedIn')
			->willReturnCallback(static fn (callable $operation): mixed => $operation());
	}//end setUp()

	/**
	 * The listener under test.
	 *
	 * @return AdviceTimerFiredListener
	 */
	private function listener(): AdviceTimerFiredListener {
		return new AdviceTimerFiredListener(
			adviceService: $this->advice,
			serviceAccount: $this->account,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end listener()

	/**
	 * One rung fire.
	 *
	 * @param string $rungKey The rung key.
	 * @param string $source  The metadata source.
	 * @param string $appId   The owning app.
	 *
	 * @return FlowTimerFiredEvent
	 */
	private function fire(string $rungKey, string $source = AdviceTimer::METADATA_SOURCE, string $appId = 'dossiq'): FlowTimerFiredEvent {
		$timer = new FlowTimer();
		$timer->setUuid('timer-1');
		$timer->setAppId($appId);
		$timer->setMetadata(['source' => $source, 'adviceId' => 'adv-1', 'caseId' => 'case-1']);

		return new FlowTimerFiredEvent(
			timer: $timer,
			kind: FlowTimerFiredEvent::KIND_RUNG,
			transition: 'escalation:'.$rungKey,
			rungKey: $rungKey,
			recipients: [],
			priority: 'normal',
			message: null
		);
	}//end fire()

	/**
	 * The reminder rung reminds the advisor.
	 *
	 * @return void
	 */
	public function testTheReminderRungRemindsTheAdvisor(): void {
		$this->advice->expects($this->once())->method('dispatchReminder')->with('adv-1');
		$this->advice->expects($this->never())->method('expireAdvice');

		$this->listener()->handle(event: $this->fire(rungKey: 'preBreach:4:calendarDays'));
	}//end testTheReminderRungRemindsTheAdvisor()

	/**
	 * The breach rung expires the request.
	 *
	 * @return void
	 */
	public function testTheBreachRungExpiresTheRequest(): void {
		$this->advice->expects($this->once())->method('expireAdvice')->with('adv-1');
		$this->advice->expects($this->never())->method('dispatchReminder');

		$this->listener()->handle(event: $this->fire(rungKey: 'slaBreached:0'));
	}//end testTheBreachRungExpiresTheRequest()

	/**
	 * A term's timer, another app's timer and a foreign event are left alone.
	 *
	 * @return void
	 */
	public function testOtherFiresAreLeftAlone(): void {
		$this->advice->expects($this->never())->method('dispatchReminder');
		$this->advice->expects($this->never())->method('expireAdvice');

		$listener = $this->listener();
		$listener->handle(event: $this->fire(rungKey: 'slaBreached:0', source: 'dossiq-termijn'));
		$listener->handle(event: $this->fire(rungKey: 'slaBreached:0', appId: 'pipelinq'));
		$listener->handle(event: new Event());
	}//end testOtherFiresAreLeftAlone()

	/**
	 * A fire whose timer names no request does nothing.
	 *
	 * @return void
	 */
	public function testAFireWithoutARequestDoesNothing(): void {
		$this->advice->expects($this->never())->method('expireAdvice');

		$timer = new FlowTimer();
		$timer->setAppId('dossiq');
		$timer->setMetadata(['source' => AdviceTimer::METADATA_SOURCE]);
		$this->listener()->handle(
			event: new FlowTimerFiredEvent(
				timer: $timer,
				kind: FlowTimerFiredEvent::KIND_RUNG,
				transition: 'escalation:slaBreached:0',
				rungKey: 'slaBreached:0',
				recipients: [],
				priority: null,
				message: null
			)
		);
	}//end testAFireWithoutARequestDoesNothing()

	/**
	 * Without the service account nothing is written and nothing throws.
	 *
	 * @return void
	 */
	public function testNoServiceAccountWritesNothing(): void {
		$account = $this->createMock(BackgroundServiceAccount::class);
		$account->method('runAsWhenNobodyIsSignedIn')
			->willThrowException(new ServiceAccountUnavailableException('none'));
		$this->advice->expects($this->never())->method('expireAdvice');

		$listener = new AdviceTimerFiredListener(
			adviceService: $this->advice,
			serviceAccount: $account,
			logger: $this->createMock(LoggerInterface::class),
		);
		$listener->handle(event: $this->fire(rungKey: 'slaBreached:0'));
		$this->addToAssertionCount(1);
	}//end testNoServiceAccountWritesNothing()
}//end class
