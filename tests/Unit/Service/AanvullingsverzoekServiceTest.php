<?php

/**
 * Asking writes the request, and the act that sends and suspends runs FIRST.
 *
 * REQ-AVR-01. Three things are pinned here.
 *
 * 🔴 THE ORDER OF THE TWO WRITES IS THE REQUIREMENT, NOT AN IMPLEMENTATION
 * DETAIL. `InformationRequestService::ask()` sends the letter and only then
 * suspends the clock. If this class wrote its record first, a failed send would
 * leave a case showing a request the applicant never received, on a term that
 * never stopped — and that half-state reads as complete, which is what makes it
 * worse than the other one. So a send that did not happen writes NOTHING.
 *
 * 🔴 THE HERSTELTERMIJN IS READ BACK OFF THE SUSPENSION, never computed here.
 * The date in the letter is the date the applicant is held to and the date the
 * term was suspended to; computing it twice is how two dates that must agree
 * stop agreeing, and the one a handler is judged on is the clock's.
 *
 * 🔴 A SECOND REQUEST ON A CASE ALREADY WAITING IS REFUSED. Two open requests
 * would make "what are we waiting on" ambiguous and would suspend a term that
 * is already suspended.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\AanvullingsverzoekService;
use OCA\Dossiq\Service\InformationRequestService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AanvullingsverzoekServiceTest extends TestCase {

	/**
	 * The act that sends and suspends as one.
	 *
	 * @var InformationRequestService&MockObject
	 */
	private InformationRequestService $act;

	/**
	 * The OpenRegister seam.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * Wire the service over doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->act = $this->createMock(originalClassName: InformationRequestService::class);
		$this->settings = $this->createMock(originalClassName: SettingsService::class);
	}//end setUp()

	/**
	 * The service under test, with `openFor` answered by the test.
	 *
	 * `openFor()` reaches the store, which these assertions are not about, so
	 * it is the one method doubled. Everything else runs for real, which is
	 * what keeps this file about the ORDER and the refusals rather than about
	 * a query.
	 *
	 * @param array<string, mixed>|null $open The open request, or null.
	 *
	 * @return AanvullingsverzoekService&MockObject The service.
	 */
	private function service(?array $open = null): AanvullingsverzoekService {
		$service = $this->getMockBuilder(className: AanvullingsverzoekService::class)
			->setConstructorArgs(
				[
					'act' => $this->act,
					'settingsService' => $this->settings,
					'logger' => new NullLogger(),
				]
			)
			->onlyMethods(['openFor', 'write', 'markCaseWaiting'])
			->getMock();

		$service->method('openFor')->willReturn($open);
		$service->method('write')->willReturnArgument(0);

		return $service;
	}//end service()

	/**
	 * What the act answers for a send that worked.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function sent(): array {
		return [
			'sent' => true,
			'suspended' => true,
			'instance' => ['id' => 'term-1', 'pauseDeadline' => '2026-10-01', 'status' => 'paused'],
			'record' => ['type' => 'information-requested'],
			'error' => '',
		];
	}//end sent()

	/**
	 * Asking writes the request, naming both items, the reason and the date.
	 *
	 * @return void
	 */
	public function testAskingWritesTheRequestNamingBothItems(): void {
		$this->act->expects($this->once())->method('ask')->willReturn($this->sent());

		$request = $this->service()->ask(
			caseId: 'case-1',
			items: ['Bankafschrift', 'Huurcontract'],
			recipient: 'aanvrager@example.org',
			durationDays: 14,
			userId: 'handler1',
			pauseReason: 'reason-awb-45',
			rationale: 'Zonder bankafschrift kan de aanvraag niet worden beoordeeld',
		);

		self::assertSame(expected: 'case-1', actual: $request['case']);
		self::assertSame(expected: 'open', actual: $request['state']);
		self::assertSame(
			expected: ['Bankafschrift', 'Huurcontract'],
			actual: array_column($request['missingItems'], 'item')
		);
		self::assertSame(
			expected: [false, false],
			actual: array_column($request['missingItems'], 'received'),
			message: 'nothing has arrived at the moment of asking'
		);
		self::assertSame(expected: 'reason-awb-45', actual: $request['pauseReason']);
		self::assertSame(expected: 'handler1', actual: $request['requestedBy']);
		self::assertNotSame(expected: '', actual: (string)$request['requestedAt']);
	}//end testAskingWritesTheRequestNamingBothItems()

	/**
	 * The hersteltermijn is the one the suspension landed on.
	 *
	 * @return void
	 */
	public function testTheHersteltermijnComesOffTheSuspension(): void {
		$this->act->method('ask')->willReturn($this->sent());

		$request = $this->service()->ask(
			caseId: 'case-1',
			items: ['Bankafschrift'],
			recipient: 'aanvrager@example.org',
			durationDays: 14,
			userId: 'handler1',
		);

		self::assertSame(
			expected: '2026-10-01',
			actual: $request['hersteltermijn'],
			message: 'the date in the letter is the date the clock was suspended to'
		);
		self::assertSame(
			expected: 'term-1',
			actual: $request['deadlineInstance'],
			message: 'the record points at the suspension rather than keeping a second clock'
		);
		self::assertSame(expected: 14, actual: $request['pauseDays']);
	}//end testTheHersteltermijnComesOffTheSuspension()

	/**
	 * A send that did not happen writes nothing at all.
	 *
	 * @return void
	 */
	public function testASendThatDidNotHappenWritesNothing(): void {
		$this->act->method('ask')->willReturn(
			[
				'sent' => false,
				'suspended' => false,
				'instance' => [],
				'record' => null,
				'error' => 'the transport is unreachable',
			]
		);

		$service = $this->service();
		$service->expects($this->never())->method('write');
		$service->expects($this->never())->method('markCaseWaiting');

		try {
			$service->ask(
				caseId: 'case-1',
				items: ['Bankafschrift'],
				recipient: 'aanvrager@example.org',
				durationDays: 14,
				userId: 'handler1',
			);
			self::fail(message: 'a request that was not sent must not be recorded as sent');
		} catch (RefusedException $e) {
			self::assertSame(expected: 'aanvullingsverzoek-not-sent', actual: $e->getRule());
		}
	}//end testASendThatDidNotHappenWritesNothing()

	/**
	 * A refusal from the act stops everything, and nothing is written.
	 *
	 * @return void
	 */
	public function testARefusalFromTheActWritesNothing(): void {
		$this->act->method('ask')->willThrowException(
			new RefusedException(
				rule: 'no-running-term',
				sentence: 'This case has no running term to suspend.',
				status: RefusedException::STATUS_REFUSED,
			)
		);

		$service = $this->service();
		$service->expects($this->never())->method('write');

		$this->expectException(exception: RefusedException::class);

		$service->ask(
			caseId: 'case-1',
			items: ['Bankafschrift'],
			recipient: 'aanvrager@example.org',
			durationDays: 14,
			userId: 'handler1',
		);
	}//end testARefusalFromTheActWritesNothing()

	/**
	 * A case already waiting is refused before the act is even asked.
	 *
	 * @return void
	 */
	public function testASecondRequestOnAWaitingCaseIsRefused(): void {
		$this->act->expects($this->never())->method('ask');

		$service = $this->service(open: ['id' => 'avr-1', 'state' => 'open']);
		$service->expects($this->never())->method('write');

		try {
			$service->ask(
				caseId: 'case-1',
				items: ['Bankafschrift'],
				recipient: 'aanvrager@example.org',
				durationDays: 14,
				userId: 'handler1',
			);
			self::fail(message: 'a second open request on one case must be refused');
		} catch (RefusedException $e) {
			self::assertSame(expected: 'aanvullingsverzoek-already-open', actual: $e->getRule());
		}
	}//end testASecondRequestOnAWaitingCaseIsRefused()

	/**
	 * The case is marked waiting, with the moment the request went out.
	 *
	 * @return void
	 */
	public function testTheCaseIsMarkedWaitingFromTheMomentItWasAsked(): void {
		$this->act->method('ask')->willReturn($this->sent());

		$service = $this->service();
		$service->expects($this->once())
			->method('markCaseWaiting')
			->with(
				'case-1',
				$this->callback(callback: static fn (?string $since): bool => ($since !== null && $since !== ''))
			);

		$service->ask(
			caseId: 'case-1',
			items: ['Bankafschrift'],
			recipient: 'aanvrager@example.org',
			durationDays: 14,
			userId: 'handler1',
		);
	}//end testTheCaseIsMarkedWaitingFromTheMomentItWasAsked()

	/**
	 * Blank lines in the asked-for list are dropped rather than stored.
	 *
	 * An empty item can never be marked as received, so a request carrying one
	 * could never be completed and would sit open for ever.
	 *
	 * @return void
	 */
	public function testBlankItemsNeverReachTheRecord(): void {
		$this->act->method('ask')->willReturn($this->sent());

		$request = $this->service()->ask(
			caseId: 'case-1',
			items: ['Bankafschrift', '   ', '', 'Huurcontract'],
			recipient: 'aanvrager@example.org',
			durationDays: 14,
			userId: 'handler1',
		);

		self::assertSame(
			expected: ['Bankafschrift', 'Huurcontract'],
			actual: array_column($request['missingItems'], 'item')
		);
	}//end testBlankItemsNeverReachTheRecord()
}//end class
