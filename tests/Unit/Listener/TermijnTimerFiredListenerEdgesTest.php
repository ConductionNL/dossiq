<?php

/**
 * The timer-fire paths of the term listener that the store-backed tests do not reach.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Kernel\Contracts\AanvullingsverzoekResolutionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseObjectReaderInterface;
use OCA\Dossiq\Kernel\Contracts\DeadlineEscalationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DeadlinePauseServiceInterface;
use OCA\Dossiq\Kernel\Contracts\PauseChaseServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnTimerServiceInterface;
use OCA\Dossiq\Listener\TermijnTimerFiredListener;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Service\Flow\Timer\EscalationNotice;
use OCA\OpenRegister\Service\Flow\Timer\TimerFiring;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Edge paths of TermijnTimerFiredListener.
 *
 * @covers \OCA\Dossiq\Listener\TermijnTimerFiredListener
 * @uses   \OCA\Dossiq\Listener\Support\TimerFire
 */
class TermijnTimerFiredListenerEdgesTest extends TestCase {

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
		$this->terms = $this->createMock(TermijnServiceInterface::class);
		$this->escalation = $this->createMock(DeadlineEscalationServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * An expiry fire is not a rung, and nothing is read for it.
	 *
	 * @return void
	 */
	public function testAnExpiryFireIsIgnored(): void {
		$this->terms->expects(self::never())->method('getTermijnInstance');

		$this->listener()->handle(self::fire(rungKey: 'slaBreached:0', kind: FlowTimerFiredEvent::KIND_EXPIRY));
	}//end testAnExpiryFireIsIgnored()

	/**
	 * A fire for an instance that no longer exists is logged and stops.
	 *
	 * @return void
	 */
	public function testAFireForAnUnknownInstanceIsLogged(): void {
		$this->terms->method('getTermijnInstance')->willReturn(null);
		$this->escalation->expects(self::never())->method('notifyThreshold');
		$this->logger->expects(self::once())->method('warning')->with(
			'Dossiq termijn: timer fired for an unknown instance',
			['instance' => 'ti-1', 'rung' => 'preBreach:7'],
		);

		$this->listener()->handle(self::fire(rungKey: 'preBreach:7'));
	}//end testAFireForAnUnknownInstanceIsLogged()

	/**
	 * A reminder rung on a hersteltermijn goes to the chase service, not the pause expiry.
	 *
	 * @return void
	 */
	public function testAReminderRungOnAHersteltermijnChases(): void {
		$instance = ['id' => 'ti-1', 'case' => 'case-1', 'status' => 'paused'];
		$this->terms->method('getTermijnInstance')->willReturn($instance);
		$this->terms->expects(self::never())->method('recordEvent');

		$chases = $this->createMock(PauseChaseServiceInterface::class);
		$chases->expects(self::once())->method('chaseIfDue')->with($instance);

		$this->listener(chases: $chases)->handle(
			self::fire(rungKey: 'preBreach:3', metaKind: TermijnTimerServiceInterface::KIND_HERSTELTERMIJN)
		);
	}//end testAReminderRungOnAHersteltermijnChases()

	/**
	 * A rung key the listener cannot read notifies nobody.
	 *
	 * @return void
	 */
	public function testAnUnreadableRungKeyNotifiesNobody(): void {
		$this->terms->method('getTermijnInstance')->willReturn(['id' => 'ti-1', 'status' => 'lopend']);
		$this->terms->expects(self::never())->method('updateTermijnInstance');
		$this->escalation->expects(self::never())->method('notifyThreshold');

		$listener = $this->listener();
		$listener->handle(self::fire(rungKey: 'preBreach:soon'));
		$listener->handle(self::fire(rungKey: 'escalated:2'));
	}//end testAnUnreadableRungKeyNotifiesNobody()

	/**
	 * A pause that ran out whose request cannot be expired is logged, and the term still resumes.
	 *
	 * @return void
	 */
	public function testAFailingRequestExpiryIsLoggedAndTheTermStillResumes(): void {
		$this->terms->method('getTermijnInstance')->willReturn(['id' => 'ti-1', 'case' => 'case-1', 'status' => 'paused']);
		$this->terms->expects(self::once())->method('recordEvent')->with('ti-1', 'pause-expired', 'AWB 4:5', 'Pauzetermijn verlopen zonder aanvulling', 0);

		$requests = $this->createMock(AanvullingsverzoekResolutionServiceInterface::class);
		$requests->method('expireIfRunOut')->with('case-1')->willThrowException(new RuntimeException('locked'));

		$cases = $this->createMock(CaseObjectReaderInterface::class);
		$cases->method('fetchCaseById')->with('case-1')->willReturn(['title' => '', 'identifier' => 'Z-2026-1', 'assignee' => ' handler-1 ']);

		$pauses = $this->createMock(DeadlinePauseServiceInterface::class);
		$pauses->expects(self::once())->method('resumeAfterExpiry')
			->with('ti-1', ['notify' => 'handler-1', 'caseTitle' => 'Z-2026-1'])
			->willReturn([]);

		$this->logger->expects(self::once())->method('warning')->with(
			'Dossiq: the aanvullingsverzoek behind an expired pause could not be marked expired',
			['case' => 'case-1', 'instance' => 'ti-1', 'error' => 'locked'],
		);

		$this->listener(aanvullingen: $requests, pauses: $pauses, cases: $cases)->handle(
			self::fire(rungKey: 'slaBreached:0', metaKind: TermijnTimerServiceInterface::KIND_HERSTELTERMIJN)
		);
	}//end testAFailingRequestExpiryIsLoggedAndTheTermStillResumes()

	/**
	 * The listener.
	 *
	 * @param AanvullingsverzoekResolutionServiceInterface|null $aanvullingen The request resolver.
	 * @param PauseChaseServiceInterface|null                   $chases       The chase service.
	 * @param DeadlinePauseServiceInterface|null                $pauses       The pause service.
	 * @param CaseObjectReaderInterface|null                    $cases        The case reader.
	 *
	 * @return TermijnTimerFiredListener The listener.
	 */
	private function listener(
		?AanvullingsverzoekResolutionServiceInterface $aanvullingen = null,
		?PauseChaseServiceInterface $chases = null,
		?DeadlinePauseServiceInterface $pauses = null,
		?CaseObjectReaderInterface $cases = null,
	): TermijnTimerFiredListener {
		$account = $this->createMock(BackgroundServiceAccount::class);
		$account->method('runAsWhenNobodyIsSignedIn')->willReturnCallback(static fn (callable $operation): mixed => $operation());

		return new TermijnTimerFiredListener(
			termService: $this->terms,
			escalationService: $this->escalation,
			logger: $this->logger,
			serviceAccount: $account,
			aanvullingen: $aanvullingen,
			chases: $chases,
			pauses: $pauses,
			cases: $cases,
		);
	}//end listener()

	/**
	 * A dossiq term timer fire.
	 *
	 * @param string $rungKey  The rung key.
	 * @param string $kind     The fire kind.
	 * @param string $metaKind The term kind in the timer metadata.
	 *
	 * @return FlowTimerFiredEvent The event.
	 */
	private static function fire(string $rungKey, string $kind = FlowTimerFiredEvent::KIND_RUNG, string $metaKind = 'beslistermijn'): FlowTimerFiredEvent {
		$timer = new FlowTimer();
		$timer->setUuid('timer-1');
		$timer->setAppId('dossiq');
		$timer->setMetadata([
			'source' => TermijnTimerServiceInterface::METADATA_SOURCE,
			'kind' => $metaKind,
			'termijnInstanceId' => 'ti-1',
		]);

		return new FlowTimerFiredEvent(
			timer: $timer,
			firing: new TimerFiring(kind: $kind, transition: 'escalation:' . $rungKey, rungKey: $rungKey),
			notice: new EscalationNotice(recipients: [], priority: 'medium'),
		);
	}//end fire()
}//end class
