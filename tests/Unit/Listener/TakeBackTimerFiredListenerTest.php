<?php

/**
 * Tests for TakeBackTimerFiredListener: a breached window hands the case back.
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
 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\TakeBackTimerFiredListener;
use OCA\Dossiq\Service\Routing\CaseRouter;
use OCA\Dossiq\Service\Routing\TakeBackWindow;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which fires take a case back.
 *
 * @spec openspec/specs/role-based-step-routing/spec.md#requirement-work-not-taken-up-returns-to-the-pool-req-rtp-03
 */
class TakeBackTimerFiredListenerTest extends TestCase {

	/**
	 * The router.
	 *
	 * @var CaseRouter&MockObject
	 */
	private CaseRouter&MockObject $router;

	/**
	 * The service account.
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
		$this->router  = $this->createMock(CaseRouter::class);
		$this->account = $this->createMock(BackgroundServiceAccount::class);
	}//end setUp()

	/**
	 * The listener.
	 *
	 * @return TakeBackTimerFiredListener
	 */
	private function listener(): TakeBackTimerFiredListener {
		return new TakeBackTimerFiredListener(router: $this->router, serviceAccount: $this->account, logger: $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * One fire of a timer with this metadata.
	 *
	 * @param array<string, mixed> $metadata The timer metadata.
	 * @param string               $rungKey  The rung.
	 * @param string               $appId    The app.
	 *
	 * @return FlowTimerFiredEvent
	 */
	private function fire(array $metadata, string $rungKey = 'slaBreached:0', string $appId = 'dossiq'): FlowTimerFiredEvent {
		$timer = new FlowTimer();
		$timer->setAppId($appId);
		$timer->setMetadata($metadata);

		return new FlowTimerFiredEvent(timer: $timer, kind: FlowTimerFiredEvent::KIND_RUNG, transition: 'escalation:'.$rungKey, rungKey: $rungKey, recipients: [], priority: null, message: null);
	}//end fire()

	/**
	 * The breach takes the case back for the holder and the routing it was armed for, as the service account.
	 *
	 * @return void
	 */
	public function testTheBreachTakesTheCaseBack(): void {
		$this->account->expects($this->once())->method('runAsWhenNobodyIsSignedIn')->willReturnCallback(static fn (callable $operation): mixed => $operation());
		$this->router->expects($this->once())->method('takeBack')->with('case-1', 'aad', '2026-10-12T09:00:00+02:00')->willReturn(CaseRouter::TAKEN_BACK);

		$this->listener()->handle(
			event: $this->fire(metadata: ['source' => TakeBackWindow::METADATA_SOURCE, 'caseId' => 'case-1', 'routedTo' => 'aad', 'routedAt' => '2026-10-12T09:00:00+02:00'])
		);
	}//end testTheBreachTakesTheCaseBack()

	/**
	 * Fires that are not a take-back breach are not ours.
	 *
	 * @return void
	 */
	public function testOtherFiresAreLeftAlone(): void {
		$this->router->expects($this->never())->method('takeBack');
		$listener = $this->listener();
		$ours     = ['source' => TakeBackWindow::METADATA_SOURCE, 'caseId' => 'case-1', 'routedTo' => 'aad'];

		$listener->handle(event: new Event());
		$listener->handle(event: $this->fire(metadata: ['source' => 'dossiq-milestone', 'caseId' => 'case-1', 'routedTo' => 'aad']));
		$listener->handle(event: $this->fire(metadata: $ours, appId: 'pipelinq'));
		$listener->handle(event: $this->fire(metadata: $ours, rungKey: 'preBreach:0'));
		$listener->handle(event: $this->fire(metadata: ['source' => TakeBackWindow::METADATA_SOURCE, 'caseId' => 'case-1']));
	}//end testOtherFiresAreLeftAlone()

	/**
	 * No service account: nothing written, nothing thrown.
	 *
	 * @return void
	 */
	public function testNoServiceAccountWritesNothing(): void {
		$this->account->method('runAsWhenNobodyIsSignedIn')->willThrowException(new ServiceAccountUnavailableException('no account'));
		$this->router->expects($this->never())->method('takeBack');

		$this->listener()->handle(
			event: $this->fire(metadata: ['source' => TakeBackWindow::METADATA_SOURCE, 'caseId' => 'case-1', 'routedTo' => 'aad', 'routedAt' => ''])
		);
		$this->addToAssertionCount(1);
	}//end testNoServiceAccountWritesNothing()
}//end class
