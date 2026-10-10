<?php

/**
 * DeadlineCaseCreatedListener: the term counts from receipt (REQ-OTE-02).
 *
 * The calendar is the one seam doubled here, because which moment it names is
 * the organisation's answer, not the listener's. What is asserted is that the
 * listener hands THAT moment to the term, and the arrival moment when no
 * calendar answers, never the moment the case row was written.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use DateTimeImmutable;
use OCA\Dossiq\Exception\NoTermijnDefinitieException;
use OCA\Dossiq\Listener\DeadlineCaseCreatedListener;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\Intake\IntakeTermStart;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use OCA\Dossiq\Service\TermijnService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\DeadlineCaseCreatedListener
 * @uses   \OCA\Dossiq\Service\Intake\IntakeTermStart
 */
class DeadlineCaseCreatedListenerTest extends TestCase {

	/**
	 * The start the term service was handed, per call.
	 *
	 * @var array<int, DateTimeImmutable|null>
	 */
	private array $starts = [];

	/**
	 * The start the other clocks were handed.
	 *
	 * @var array<int, DateTimeImmutable|null>
	 */
	private array $otherStarts = [];

	/**
	 * A listener over a calendar that answers `$answer`.
	 *
	 * @param DateTimeImmutable|null $answer What the calendar names as the first working moment.
	 * @param bool                   $definition Whether the case type has a term definition.
	 * @param LoggerInterface|null   $logger The logger.
	 *
	 * @return DeadlineCaseCreatedListener The listener.
	 */
	private function listener(
		?DateTimeImmutable $answer,
		bool $definition = true,
		?LoggerInterface $logger = null,
	): DeadlineCaseCreatedListener {
		$terms = $this->createMock(TermijnService::class);
		$terms->method('createTermijnInstance')->willReturnCallback(
			function (string $caseId, string $caseType, ?DateTimeImmutable $start = null) use ($definition): array {
				$this->starts[] = $start;
				if ($definition === false) {
					throw new NoTermijnDefinitieException(message: 'none');
				}

				return ['id' => 't1'];
			}
		);

		$caseTerms = $this->createMock(CaseTermsService::class);
		$caseTerms->method('bindForCase')->willReturnCallback(
			function (string $caseId, string $caseTypeId, ?DateTimeImmutable $start = null): array {
				$this->otherStarts[] = $start;
				return [];
			}
		);

		$schemaSlugs = $this->createMock(ObjectSchemaSlugResolver::class);
		$schemaSlugs->method('resolveFromPayload')->willReturn('case');
		$caseTypeSlugs = $this->createMock(CaseTypeSlugResolver::class);
		$caseTypeSlugs->method('toSlug')->willReturn('omgevingsvergunning-regulier');

		$calendar = $this->createMock(WorkingDayRoll::class);
		$calendar->method('firstWorkingMomentAtOrAfter')->willReturn($answer);

		$logger = ($logger ?? $this->createMock(LoggerInterface::class));

		return new DeadlineCaseCreatedListener(
			termService: $terms,
			slugResolver: $schemaSlugs,
			caseTypeSlugs: $caseTypeSlugs,
			logger: $logger,
			caseTerms: $caseTerms,
			resolution: null,
			intake: new IntakeTermStart(calendar: $calendar),
		);
	}//end listener()

	/**
	 * A created case carrying these fields.
	 *
	 * @param array<string, mixed> $fields The case fields.
	 *
	 * @return ObjectCreatedEvent The event.
	 */
	private static function created(array $fields): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		$entity->setUuid('case-1');
		$entity->setSchema('case');
		$entity->setObject(array_merge(['caseType' => 'ct-uuid'], $fields));

		return new ObjectCreatedEvent($entity);
	}//end created()

	/**
	 * A request received on Sunday evening, registered on Tuesday, starts on Monday.
	 *
	 * @return void
	 */
	public function testARequestReceivedOnSundayStartsOnMonday(): void {
		$monday = new DateTimeImmutable('2026-10-05T00:00:00+02:00');
		$this->listener(answer: $monday)->handle(
			self::created(['receivedAt' => '2026-10-04T20:00:00+02:00', 'registrationDate' => '2026-10-06'])
		);

		self::assertCount(1, $this->starts);
		self::assertSame('2026-10-05T00:00:00+02:00', $this->starts[0]?->format('c'));
		self::assertSame(
			'2026-10-05T00:00:00+02:00',
			$this->otherStarts[0]?->format('c'),
			'The planned end and the internal target start on the same day as the statutory term.'
		);
	}//end testARequestReceivedOnSundayStartsOnMonday()

	/**
	 * A stamp already on the case is used as it stands.
	 *
	 * @return void
	 */
	public function testAStampAlreadyOnTheCaseIsUsed(): void {
		$this->listener(answer: new DateTimeImmutable('2030-01-01'))->handle(
			self::created(['termStartsAt' => '2026-09-14T08:30:00+02:00'])
		);

		self::assertSame('2026-09-14T08:30:00+02:00', $this->starts[0]?->format('c'));
	}//end testAStampAlreadyOnTheCaseIsUsed()

	/**
	 * Without a calendar, a back-dated receipt still counts from the receipt.
	 *
	 * @return void
	 */
	public function testWithoutACalendarTheReceiptItselfStartsTheTerm(): void {
		$this->listener(answer: null)->handle(
			self::created(['receivedAt' => '2026-09-01T10:00:00+02:00'])
		);

		self::assertSame('2026-09-01T10:00:00+02:00', $this->starts[0]?->format('c'));
	}//end testWithoutACalendarTheReceiptItselfStartsTheTerm()

	/**
	 * A case type without a definition still creates the case and warns (REQ-TERM-001).
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutADefinitionWarnsAndBindsTheOtherClocks(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::atLeastOnce())->method('warning');

		$this->listener(answer: new DateTimeImmutable('2026-10-05T00:00:00+02:00'), definition: false, logger: $logger)
			->handle(self::created(['receivedAt' => '2026-10-04T20:00:00+02:00']));

		self::assertCount(1, $this->otherStarts, 'The other clocks still bind.');
	}//end testACaseTypeWithoutADefinitionWarnsAndBindsTheOtherClocks()
}//end class
