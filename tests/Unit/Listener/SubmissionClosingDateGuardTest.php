<?php

/**
 * A submission received after its case's closing date is refused, naming the date
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Kernel\Contracts\CaseDateNormaliserInterface;
use OCA\Dossiq\Kernel\Contracts\CaseObjectReaderInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\SubmissionClosingDateGuard;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\SubmissionClosingDateGuard
 * @uses \OCA\Dossiq\Listener\Support\SchemaMembership
 * @uses \OCA\Dossiq\Service\Support\ReferenceId
 */
class SubmissionClosingDateGuardTest extends TestCase {

	/**
	 * The tender case the bids are submitted to.
	 */
	private const TENDER = 'b84067ac-35ec-55b5-bf17-975206ce32f2';

	/**
	 * Reads the case a submission names.
	 *
	 * @var CaseObjectReaderInterface&MockObject
	 */
	private CaseObjectReaderInterface&MockObject $cases;

	/**
	 * Today, as the administrator's calendar has it.
	 *
	 * @var string
	 */
	private string $today = '2026-11-15';

	/**
	 * Set up the case reader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->cases = $this->createMock(CaseObjectReaderInterface::class);
	}//end setUp()

	/**
	 * The guard, with the bid schema configured and dates read as calendar days.
	 *
	 * @return SubmissionClosingDateGuard
	 */
	private function guard(): SubmissionClosingDateGuard {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'bid_schema' ? 'bid-schema-id' : '')
		);

		$dates = $this->createMock(CaseDateNormaliserInterface::class);
		$dates->method('toCalendarDateOrNull')->willReturnCallback(
			static fn (mixed $value): ?string => (is_string($value) === true && $value !== '' ? substr($value, 0, 10) : null)
		);
		$dates->method('todayAsCalendarDate')->willReturnCallback(fn (): string => $this->today);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new SubmissionClosingDateGuard(
			settingsService: $settings,
			cases: $this->cases,
			dates: $dates,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end guard()

	/**
	 * A bid on the tender, as it will be created.
	 *
	 * @param array<string, mixed> $fields   The bid's fields.
	 * @param string               $schemaId The schema it is saved in.
	 *
	 * @return ObjectEntity
	 */
	private function bid(array $fields, string $schemaId = 'bid-schema-id'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject(array_merge(['tender' => self::TENDER, 'supplier' => 'supplier-1', 'state' => 'received'], $fields));
		$entity->setSchema($schemaId);

		return $entity;
	}//end bid()

	/**
	 * The tender case closes on this day.
	 *
	 * @param array<string, mixed>|null $case The case the reader returns.
	 *
	 * @return void
	 */
	private function tenderIs(?array $case): void {
		$this->cases->method('fetchCaseById')->with(self::TENDER)->willReturn($case);
	}//end tenderIs()

	/**
	 * Create the bid and return the event.
	 *
	 * @param ObjectEntity $bid The bid.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function create(ObjectEntity $bid): ObjectCreatingEvent {
		$event = new ObjectCreatingEvent($bid);
		$this->guard()->handle($event);

		return $event;
	}//end create()

	/**
	 * A bid received the day after the closing date is refused, with the closing date in the message.
	 *
	 * @return void
	 */
	public function testALateBidIsRefusedNamingTheClosingDate(): void {
		$this->tenderIs(['id' => self::TENDER, 'sluiting' => '2026-11-14']);

		$event = $this->create($this->bid(['submittedAt' => '2026-11-15T09:00:00+01:00']));

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('Submissions closed on 2026-11-14. This one came in after the closing date.', $event->getErrors()['message']);
		$this->assertSame(SubmissionClosingDateGuard::REFUSAL_CODE, $event->getErrors()['code']);
		$this->assertSame('2026-11-14', $event->getErrors()['closingDate']);
	}//end testALateBidIsRefusedNamingTheClosingDate()

	/**
	 * A bid received on the closing day itself still counts.
	 *
	 * @return void
	 */
	public function testABidOnTheClosingDayCounts(): void {
		$this->tenderIs(['id' => self::TENDER, 'sluiting' => '2026-11-15']);

		$event = $this->create($this->bid(['submittedAt' => '2026-11-15T23:30:00+01:00']));

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testABidOnTheClosingDayCounts()

	/**
	 * A bid without a moment of receipt is received today, and today is after the closing day.
	 *
	 * @return void
	 */
	public function testABidWithoutAMomentIsReceivedToday(): void {
		$this->today = '2026-11-20';
		$this->tenderIs(['id' => self::TENDER, 'sluiting' => '2026-11-14']);

		$this->assertTrue($this->create($this->bid([]))->isPropagationStopped());
	}//end testABidWithoutAMomentIsReceivedToday()

	/**
	 * The closing day is read from the case's answered properties as well.
	 *
	 * @return void
	 */
	public function testTheClosingDayIsReadFromTheCasesProperties(): void {
		$this->tenderIs(['id' => self::TENDER, 'properties' => [['name' => 'other', 'value' => 'x'], ['name' => 'sluiting', 'value' => '2026-11-01']]]);

		$event = $this->create($this->bid(['submittedAt' => '2026-11-02']));

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('2026-11-01', $event->getErrors()['closingDate']);
	}//end testTheClosingDayIsReadFromTheCasesProperties()

	/**
	 * A tender without a closing date, or a case that cannot be found, refuses nothing.
	 *
	 * @return void
	 */
	public function testNoClosingDateRefusesNothing(): void {
		$this->cases->method('fetchCaseById')->willReturnOnConsecutiveCalls(['id' => self::TENDER, 'title' => 'Open'], null);

		$this->assertFalse($this->create($this->bid(['submittedAt' => '2030-01-01']))->isPropagationStopped());
		$this->assertFalse($this->create($this->bid(['submittedAt' => '2030-01-01']))->isPropagationStopped());
	}//end testNoClosingDateRefusesNothing()

	/**
	 * A record of another schema is not a submission and is never looked up.
	 *
	 * @return void
	 */
	public function testARecordOfAnotherSchemaIsLeftAlone(): void {
		$this->cases->expects($this->never())->method('fetchCaseById');

		$event = $this->create($this->bid(['submittedAt' => '2030-01-01'], 'evaluation-schema-id'));

		$this->assertFalse($event->isPropagationStopped());
	}//end testARecordOfAnotherSchemaIsLeftAlone()

	/**
	 * A bid that names no tender is not checked.
	 *
	 * @return void
	 */
	public function testABidWithoutATenderIsNotChecked(): void {
		$this->cases->expects($this->never())->method('fetchCaseById');

		$event = $this->create($this->bid(['tender' => null, 'submittedAt' => '2030-01-01']));

		$this->assertFalse($event->isPropagationStopped());
	}//end testABidWithoutATenderIsNotChecked()

	/**
	 * An update of a bid, and any other event, is left alone.
	 *
	 * @return void
	 */
	public function testOnlyACreateIsChecked(): void {
		$this->cases->expects($this->never())->method('fetchCaseById');

		$update = $this->createMock(ObjectUpdatingEvent::class);
		$update->expects($this->never())->method('setErrors');
		$this->guard()->handle($update);
		$this->guard()->handle(new Event());
	}//end testOnlyACreateIsChecked()
}//end class
