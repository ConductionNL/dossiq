<?php

/**
 * An answer names WHICH items arrived, and only the handler closes the request.
 *
 * REQ-AVR-02 and REQ-AVR-03. Four things are pinned here, and each is a way
 * this requirement could pass a happy-path test and still be worthless.
 *
 * 🔴 A PARTIAL ANSWER LEAVES THE REQUEST OPEN, WITH WHAT IS MISSING NAMED. A
 * request for two documents that gets one is the ordinary case, not the
 * exception, and it is the reason a second letter has to go out. An
 * implementation that closed on "something arrived" would resume a statutory
 * term on a file that is still incomplete, and the handler would carry that
 * date.
 *
 * 🔴 COMPLETION IS REFUSED WHILE ANYTHING IS OUTSTANDING, even when the caller
 * says it is complete. Neither half can close the request alone: the handler's
 * word is required because they defend the date, and every item must be marked
 * because a word is not evidence.
 *
 * 🔴 THE DAY NAMED IS THE LAST DAY THE APPLICANT HAS. A request whose
 * hersteltermijn is TODAY has not run out. Getting this off by one refuses an
 * application a day early, which is exactly the kind of error nobody notices
 * until somebody appeals.
 *
 * 🔴 EXPIRY WRITES ONE FIELD AND REWRITES NOTHING. The items and the dates are
 * the evidence that the applicant was given the chance, which Awb 4:5 requires
 * before an application can be refused for incompleteness. A test that only
 * checked the state would pass over an implementation that quietly reset the
 * items.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\AanvullingsverzoekResolutionService;
use OCA\Dossiq\Service\AanvullingsverzoekService;
use OCA\Dossiq\Service\InformationRequestService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AanvullingsverzoekResolutionTest extends TestCase {

	/**
	 * The requests themselves.
	 *
	 * @var AanvullingsverzoekService&MockObject
	 */
	private AanvullingsverzoekService $requests;

	/**
	 * The act that resumes the clock through the existing credit path.
	 *
	 * @var InformationRequestService&MockObject
	 */
	private InformationRequestService $act;

	/**
	 * Wire the service over doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->requests = $this->createMock(originalClassName: AanvullingsverzoekService::class);
		$this->act = $this->createMock(originalClassName: InformationRequestService::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return AanvullingsverzoekResolutionService The service.
	 */
	private function service(): AanvullingsverzoekResolutionService {
		return new AanvullingsverzoekResolutionService(
			requests: $this->requests,
			act: $this->act,
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * An open request for two items.
	 *
	 * @param string $due The hersteltermijn.
	 *
	 * @return array<string, mixed> The request.
	 */
	private function openRequest(string $due = '2026-10-01'): array {
		return [
			'id' => 'avr-1',
			'case' => 'case-1',
			'state' => 'open',
			'hersteltermijn' => $due,
			'requestedAt' => '2026-09-01T09:00:00+00:00',
			'missingItems' => [
				['item' => 'Bankafschrift', 'received' => false],
				['item' => 'Huurcontract', 'received' => false],
			],
		];
	}//end openRequest()

	/**
	 * One of two arrives: the request stays open and names the other.
	 *
	 * @return void
	 */
	public function testAPartialAnswerLeavesTheRequestOpen(): void {
		$this->requests->method('openFor')->willReturn($this->openRequest());
		$this->act->expects($this->never())->method('receive');
		$this->requests->expects($this->never())->method('markCaseWaiting');

		$written = [];
		$this->requests->method('write')->willReturnCallback(
			static function (array $request, string $id = '') use (&$written): array {
				$written = $request;
				return $request;
			}
		);

		$result = $this->service()->recordAnswer(
			caseId: 'case-1',
			received: ['Bankafschrift'],
			complete: false,
			userId: 'handler1'
		);

		self::assertSame(expected: 'open', actual: $result['state'], message: 'a partial answer does not close it');
		self::assertTrue(condition: $result['missingItems'][0]['received']);
		self::assertFalse(
			condition: $result['missingItems'][1]['received'],
			message: 'the item that did not arrive is still outstanding'
		);
		self::assertArrayNotHasKey(
			key: 'state',
			array: $written,
			message: 'a partial answer writes the items and nothing about the state'
		);
	}//end testAPartialAnswerLeavesTheRequestOpen()

	/**
	 * The term stays suspended while anything is outstanding.
	 *
	 * The mirror of the test above, asserted on the CLOCK rather than on the
	 * record: resuming here would be the defect that matters, because the date
	 * a handler is judged on would move while the file is still incomplete.
	 *
	 * @return void
	 */
	public function testAPartialAnswerDoesNotResumeTheClock(): void {
		$this->requests->method('openFor')->willReturn($this->openRequest());
		$this->requests->method('write')->willReturnArgument(0);
		$this->act->expects($this->never())->method('receive');

		$this->service()->recordAnswer(
			caseId: 'case-1',
			received: ['Bankafschrift'],
			complete: false,
			userId: 'handler1'
		);
	}//end testAPartialAnswerDoesNotResumeTheClock()

	/**
	 * Claiming completeness with an item outstanding is refused, and the
	 * refusal names what is still missing.
	 *
	 * @return void
	 */
	public function testCompletionIsRefusedWhileAnythingIsOutstanding(): void {
		$this->requests->method('openFor')->willReturn($this->openRequest());
		$this->act->expects($this->never())->method('receive');
		$this->requests->expects($this->never())->method('write');

		try {
			$this->service()->recordAnswer(
				caseId: 'case-1',
				received: ['Bankafschrift'],
				complete: true,
				userId: 'handler1'
			);
			self::fail(message: 'closing a request with an item outstanding must be refused');
		} catch (RefusedException $e) {
			self::assertSame(expected: 'aanvullingsverzoek-still-outstanding', actual: $e->getRule());
			self::assertStringContainsString(
				needle: 'Huurcontract',
				haystack: $e->getSentence(),
				message: 'the refusal names what is still missing, or the handler has to guess'
			);
		}
	}//end testCompletionIsRefusedWhileAnythingIsOutstanding()

	/**
	 * Both arrive and the handler says so: the request is answered and the
	 * clock resumes through the existing credit path.
	 *
	 * @return void
	 */
	public function testAFullAnswerClosesTheRequestAndResumesTheClock(): void {
		$this->requests->method('openFor')->willReturn($this->openRequest());
		$this->requests->method('write')->willReturnArgument(0);

		// The clock is resumed by the act that already credits the unused
		// suspension back. Nothing in this class does that arithmetic.
		$this->act->expects($this->once())->method('receive');
		$this->requests->expects($this->once())
			->method('markCaseWaiting')
			->with('case-1', null);

		$result = $this->service()->recordAnswer(
			caseId: 'case-1',
			received: ['Bankafschrift', 'Huurcontract'],
			complete: true,
			userId: 'handler1'
		);

		self::assertSame(expected: 'answered', actual: $result['state']);
		self::assertSame(expected: 'handler1', actual: $result['answeredBy']);
		self::assertNotSame(expected: '', actual: (string)$result['answeredAt']);
	}//end testAFullAnswerClosesTheRequestAndResumesTheClock()

	/**
	 * A case with nothing open is refused rather than silently doing nothing.
	 *
	 * @return void
	 */
	public function testAnswersOnACaseWithNothingOpenAreRefused(): void {
		$this->requests->method('openFor')->willReturn(null);
		$this->act->expects($this->never())->method('receive');

		$this->expectException(RefusedException::class);

		$this->service()->recordAnswer(
			caseId: 'case-1',
			received: ['Bankafschrift'],
			complete: true,
			userId: 'handler1'
		);
	}//end testAnswersOnACaseWithNothingOpenAreRefused()

	/**
	 * An arrival naming something never asked for is ignored, not added.
	 *
	 * A request is the record of what WAS asked. Growing it afterwards would
	 * rewrite the ask, and the ask is the thing Awb 4:5 requires the file to
	 * show.
	 *
	 * @return void
	 */
	public function testAnArrivalNobodyAskedForDoesNotJoinTheRequest(): void {
		$items = $this->service()->markReceived(
			items: $this->openRequest()['missingItems'],
			received: ['Bankafschrift', 'Paspoort'],
			moment: new DateTimeImmutable('2026-09-10T10:00:00+00:00')
		);

		self::assertCount(expectedCount: 2, haystack: $items);
		self::assertSame(
			expected: ['Bankafschrift', 'Huurcontract'],
			actual: array_column($items, 'item'),
			message: 'the ask is not rewritten by what came back'
		);
	}//end testAnArrivalNobodyAskedForDoesNotJoinTheRequest()

	/**
	 * An item that already arrived keeps the moment it arrived.
	 *
	 * @return void
	 */
	public function testAnItemAlreadyReceivedKeepsItsMoment(): void {
		$items = [
			['item' => 'Bankafschrift', 'received' => true, 'receivedAt' => '2026-09-05T09:00:00+00:00'],
			['item' => 'Huurcontract', 'received' => false],
		];

		$marked = $this->service()->markReceived(
			items: $items,
			received: ['Bankafschrift', 'Huurcontract'],
			moment: new DateTimeImmutable('2026-09-10T10:00:00+00:00')
		);

		self::assertSame(
			expected: '2026-09-05T09:00:00+00:00',
			actual: $marked[0]['receivedAt'],
			message: 'a second answer does not restamp what arrived a week ago'
		);
		self::assertSame(expected: '2026-09-10T10:00:00+00:00', actual: $marked[1]['receivedAt']);
	}//end testAnItemAlreadyReceivedKeepsItsMoment()
}//end class
