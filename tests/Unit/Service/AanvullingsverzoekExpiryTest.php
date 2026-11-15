<?php

/**
 * An unanswered request expires and STAYS, with everything it asked for intact.
 *
 * REQ-AVR-03. The spec excludes this from e2e as time-dependent and names a
 * unit over the timer-fired path; this is that unit, and every assertion
 * carries its own `now` rather than the wall clock, so the file cannot turn red
 * on one particular Tuesday.
 *
 * 🔴 THE DAY NAMED IS THE LAST DAY THE APPLICANT HAS. A request whose
 * hersteltermijn is TODAY has not run out. An off-by-one here refuses an
 * application a day early, and nobody notices until somebody appeals.
 *
 * 🔴 EXPIRY WRITES ONE FIELD. The items, the dates and the reason are the
 * evidence that the applicant was given the chance, which Awb 4:5 requires
 * before an application can be refused for incompleteness. A test that only
 * asserted the state would pass over an implementation that reset the items on
 * the way through, and the file would lose the one thing it exists to show.
 *
 * 🔴 AN UNREADABLE DATE EXPIRES NOTHING. Treating a date nobody can parse as
 * "the term ran out" would destroy exactly the defence the record provides, on
 * the first bad import, in silence.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\AanvullingsverzoekResolutionService;
use OCA\Dossiq\Service\AanvullingsverzoekService;
use OCA\Dossiq\Service\InformationRequestService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AanvullingsverzoekExpiryTest extends TestCase {

	/**
	 * The requests themselves.
	 *
	 * @var AanvullingsverzoekService&MockObject
	 */
	private AanvullingsverzoekService $requests;

	/**
	 * Wire the service over doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->requests = $this->createMock(originalClassName: AanvullingsverzoekService::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return AanvullingsverzoekResolutionService The service.
	 */
	private function service(): AanvullingsverzoekResolutionService {
		return new AanvullingsverzoekResolutionService(
			requests: $this->requests,
			act: $this->createMock(originalClassName: InformationRequestService::class),
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * An open request due on the given day.
	 *
	 * @param string $due   The hersteltermijn.
	 * @param string $state Where it stands.
	 *
	 * @return array<string, mixed> The request.
	 */
	private function request(string $due, string $state = 'open'): array {
		return [
			'id' => 'avr-1',
			'case' => 'case-1',
			'state' => $state,
			'hersteltermijn' => $due,
			'requestedAt' => '2026-09-01T09:00:00+00:00',
			'rationale' => 'Zonder bankafschrift kan de aanvraag niet worden beoordeeld',
			'missingItems' => [
				['item' => 'Bankafschrift', 'received' => false],
				['item' => 'Huurcontract', 'received' => false],
			],
		];
	}//end request()

	/**
	 * The day passes and the request reads expired.
	 *
	 * @return void
	 */
	public function testTheDayPassesAndTheRequestExpires(): void {
		$this->requests->method('openFor')->willReturn($this->request(due: '2026-10-01'));
		$this->requests->method('write')->willReturnArgument(0);

		$expired = $this->service()->expireIfRunOut(
			caseId: 'case-1',
			now: new DateTimeImmutable('2026-10-02')
		);

		self::assertNotNull(actual: $expired);
		self::assertSame(expected: 'expired', actual: $expired['state']);
	}//end testTheDayPassesAndTheRequestExpires()

	/**
	 * The day named is the last day the applicant has.
	 *
	 * @return void
	 */
	public function testTheDayNamedIsStillTheApplicantsDay(): void {
		$service = $this->service();
		$request = $this->request(due: '2026-10-01');

		self::assertFalse(
			condition: $service->hasRunOut(request: $request, now: new DateTimeImmutable('2026-10-01')),
			message: 'a request due today has not run out'
		);
		self::assertFalse(
			condition: $service->hasRunOut(request: $request, now: new DateTimeImmutable('2026-09-30')),
			message: 'a request due tomorrow certainly has not'
		);
		self::assertTrue(
			condition: $service->hasRunOut(request: $request, now: new DateTimeImmutable('2026-10-02'))
		);
	}//end testTheDayNamedIsStillTheApplicantsDay()

	/**
	 * Expiring writes the state and nothing else.
	 *
	 * @return void
	 */
	public function testExpiringRewritesNothingItAskedFor(): void {
		$this->requests->method('openFor')->willReturn($this->request(due: '2026-10-01'));

		$written = null;
		$this->requests->method('write')->willReturnCallback(
			static function (array $request, string $id = '') use (&$written): array {
				$written = $request;
				return $request;
			}
		);

		$expired = $this->service()->expireIfRunOut(
			caseId: 'case-1',
			now: new DateTimeImmutable('2026-10-02')
		);

		self::assertSame(
			expected: ['state' => 'expired'],
			actual: $written,
			message: 'expiring writes ONE field; the ask is the evidence and is not touched'
		);
		self::assertSame(
			expected: ['Bankafschrift', 'Huurcontract'],
			actual: array_column($expired['missingItems'], 'item'),
			message: 'what was asked for is still readable'
		);
		self::assertSame(expected: '2026-10-01', actual: $expired['hersteltermijn']);
		self::assertSame(expected: '2026-09-01T09:00:00+00:00', actual: $expired['requestedAt']);
	}//end testExpiringRewritesNothingItAskedFor()

	/**
	 * A request that was answered is never expired behind the handler's back.
	 *
	 * The timer fires on the pause, not on the request, so a late fire after an
	 * answer is the ordinary case rather than a rare one.
	 *
	 * @return void
	 */
	public function testAnAnsweredRequestIsNotExpiredByALateTimer(): void {
		$service = $this->service();

		self::assertFalse(
			condition: $service->hasRunOut(
				request: $this->request(due: '2026-10-01', state: 'answered'),
				now: new DateTimeImmutable('2026-12-01')
			)
		);
	}//end testAnAnsweredRequestIsNotExpiredByALateTimer()

	/**
	 * A case with nothing open expires nothing, and says so by answering null.
	 *
	 * @return void
	 */
	public function testACaseWithNothingOpenExpiresNothing(): void {
		$this->requests->method('openFor')->willReturn(null);
		$this->requests->expects($this->never())->method('write');

		self::assertNull(
			actual: $this->service()->expireIfRunOut(
				caseId: 'case-1',
				now: new DateTimeImmutable('2026-12-01')
			)
		);
	}//end testACaseWithNothingOpenExpiresNothing()

	/**
	 * A date nobody can read expires nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableDateExpiresNothing(): void {
		$service = $this->service();

		foreach (['', 'binnenkort', '0000-00-00'] as $unreadable) {
			self::assertFalse(
				condition: $service->hasRunOut(
					request: $this->request(due: $unreadable),
					now: new DateTimeImmutable('2026-12-01')
				),
				message: sprintf('a hersteltermijn of "%s" is not evidence the applicant failed to answer', $unreadable)
			);
		}
	}//end testAnUnreadableDateExpiresNothing()

	/**
	 * Expiry clears the waiting flag on the case, because the case is no
	 * longer waiting on anybody.
	 *
	 * @return void
	 */
	public function testExpiryStopsTheCaseCountingAsWaiting(): void {
		$this->requests->method('openFor')->willReturn($this->request(due: '2026-10-01'));
		$this->requests->method('write')->willReturnArgument(0);
		$this->requests->expects($this->once())
			->method('markCaseWaiting')
			->with('case-1', null);

		$this->service()->expireIfRunOut(caseId: 'case-1', now: new DateTimeImmutable('2026-10-02'));
	}//end testExpiryStopsTheCaseCountingAsWaiting()
}//end class
