<?php

/**
 * Asking the applicant and suspending the term are one act.
 *
 * The case that carries this file is the failed letter: an unreachable
 * transport must leave the clock RUNNING. A suspended clock with no letter is a
 * case where the citizen was never asked, and that is worse than an unsuspended
 * one. So the pause service is asserted to be untouched, not merely the result
 * flag, because a result flag can say `suspended: false` while the pause was
 * already registered.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\InformationRequestService;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermKind;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * REQ-TERM-067: asking the applicant and suspending the term are one act.
 *
 * @covers \OCA\Dossiq\Service\InformationRequestService
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\TermKind
 */
class RequestInformationSuspendsTest extends TestCase {
	use BindsTermFixtures;

	/**
	 * The store.
	 *
	 * @var TermijnService&MockObject
	 */
	private TermijnService $termService;

	/**
	 * Opschorten and hervatten.
	 *
	 * @var DeadlinePauseService&MockObject
	 */
	private DeadlinePauseService $pause;

	/**
	 * The transport.
	 *
	 * @var TermijnNotificationService&MockObject
	 */
	private TermijnNotificationService $notifications;

	/**
	 * Every event the store was handed.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $events = [];

	/**
	 * Build the collaborators, wired but not yet told how to behave.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->termService = $this->createMock(TermijnService::class);
		$this->pause = $this->createMock(DeadlinePauseService::class);
		$this->notifications = $this->createMock(TermijnNotificationService::class);

		$this->instances = [
			array_merge($this->instanceOf(kind: TermKind::STATUTORY, end: '2026-11-01'), ['id' => 't1']),
		];
		$this->events = [];

		$this->termService->method('instancesForCase')->willReturnCallback(
			fn (string $caseId): array => $this->instances
		);
		$this->termService->method('recordEvent')->willReturnCallback(
			function (
				string $instanceId,
				string $type,
				string $basis,
				string $rationale,
				int $daysImpact,
				?DateTimeImmutable $moment = null,
				string $documentLink = '',
				string $actor = 'system',
				array $items = [],
			): array {
				$event = [
					'deadlineInstance' => $instanceId,
					'type' => $type,
					'basis' => $basis,
					'daysImpact' => $daysImpact,
					'items' => $items,
					'moment' => ($moment ?? new DateTimeImmutable())->format('c'),
				];
				$this->events[] = $event;

				return $event;
			}
		);
	}//end setUp()

	/**
	 * The letter and the pause happen together, and one record carries both.
	 *
	 * @return void
	 */
	public function testTheLetterAndThePauseHappenTogether(): void {
		$this->notifications->expects(self::once())
			->method('sendTermijnNotification')
			->willReturn(['subject' => 's', 'body' => 'b', 'locale' => 'nl']);

		$this->pause->expects(self::once())
			->method('registerPauze')
			->willReturn(['id' => 't1', 'status' => 'paused']);

		$result = $this->service()->ask(
			caseId: 'c1',
			items: ['Bankafschrift', 'Bouwtekening'],
			recipient: 'burger1',
			durationDays: 14,
		);

		self::assertTrue($result['sent']);
		self::assertTrue($result['suspended']);

		self::assertCount(1, $this->events, 'One act, one record.');
		self::assertSame(InformationRequestService::EVENT_REQUESTED, $this->events[0]['type']);
		self::assertSame(['Bankafschrift', 'Bouwtekening'], $this->events[0]['items'], 'what was asked');
		self::assertNotSame('', $this->events[0]['moment'], 'when it was asked');
		self::assertSame(14, $this->events[0]['daysImpact'], 'the suspension it caused');
	}//end testTheLetterAndThePauseHappenTogether()

	/**
	 * A failed letter leaves the clock running, and the pause is never asked.
	 *
	 * @return void
	 */
	public function testAFailedLetterLeavesTheClockRunning(): void {
		$this->notifications->method('sendTermijnNotification')
			->willThrowException(new RuntimeException('the transport is unreachable'));

		$this->pause->expects(self::never())->method('registerPauze');

		$result = $this->service()->ask(
			caseId: 'c1',
			items: ['Bankafschrift'],
			recipient: 'burger1',
			durationDays: 14,
		);

		self::assertFalse($result['sent']);
		self::assertFalse($result['suspended']);
		self::assertStringContainsString('unreachable', $result['error']);
	}//end testAFailedLetterLeavesTheClockRunning()

	/**
	 * A failed letter is recorded on the term, so the case shows what happened.
	 *
	 * @return void
	 */
	public function testAFailedLetterIsVisibleOnTheCase(): void {
		$this->notifications->method('sendTermijnNotification')
			->willThrowException(new RuntimeException('the transport is unreachable'));

		$this->service()->ask(caseId: 'c1', items: ['Bankafschrift'], recipient: 'burger1', durationDays: 14);

		self::assertCount(1, $this->events);
		self::assertSame(InformationRequestService::EVENT_FAILED, $this->events[0]['type']);
		self::assertSame(0, $this->events[0]['daysImpact'], 'Nothing moved, so nothing is recorded as having moved.');
	}//end testAFailedLetterIsVisibleOnTheCase()

	/**
	 * Receiving the aanvulling resumes the term and records what came in.
	 *
	 * @return void
	 */
	public function testReceivingTheAanvullingResumesTheTerm(): void {
		$this->instances = [
			array_merge(
				$this->instanceOf(kind: TermKind::STATUTORY, end: '2026-11-15', status: 'paused'),
				['id' => 't1']
			),
		];

		$this->pause->expects(self::once())
			->method('resumeAfterPauze')
			->willReturn(['id' => 't1', 'status' => 'lopend']);

		$result = $this->service()->receive(
			caseId: 'c1',
			items: ['Bankafschrift'],
			when: new DateTimeImmutable('2026-09-20'),
		);

		self::assertTrue($result['resumed']);
		self::assertSame(InformationRequestService::EVENT_RECEIVED, $this->events[0]['type']);
		self::assertSame(['Bankafschrift'], $this->events[0]['items']);
	}//end testReceivingTheAanvullingResumesTheTerm()

	/**
	 * A request naming nothing is refused before anything is sent.
	 *
	 * @return void
	 */
	public function testARequestNamingNothingIsRefused(): void {
		$this->notifications->expects(self::never())->method('sendTermijnNotification');

		$this->expectException(RefusedException::class);

		$this->service()->ask(caseId: 'c1', items: ['', '   '], recipient: 'burger1', durationDays: 14);
	}//end testARequestNamingNothingIsRefused()

	/**
	 * A case with no running term has nothing to suspend, and says so.
	 *
	 * @return void
	 */
	public function testACaseWithNoRunningTermIsRefused(): void {
		$this->instances = [];

		try {
			$this->service()->ask(caseId: 'c1', items: ['Bankafschrift'], recipient: 'burger1', durationDays: 14);
			self::fail('A case with no running term cannot be suspended.');
		} catch (RefusedException $refusal) {
			self::assertSame('no-running-term-to-suspend', $refusal->getRule());
		}
	}//end testACaseWithNoRunningTermIsRefused()

	/**
	 * The service, built from the collaborators as this test has set them up.
	 *
	 * @return InformationRequestService The service under test.
	 */
	private function service(): InformationRequestService {
		$terms = $this->createMock(CaseTermsService::class);
		$terms->method('endAfter')->willReturn('2026-09-29');

		return new InformationRequestService(
			termService: $this->termService,
			pause: $this->pause,
			notifications: $this->notifications,
			terms: $terms,
			logger: new NullLogger(),
		);
	}//end service()
}//end class
