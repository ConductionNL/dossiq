<?php

/**
 * The receipt and start dates a new case is given, through the real listener.
 *
 * The create form no longer asks when a request came in (Ruben, 10 October
 * 2026). These tests drive `IntakeTermStartListener` with a real
 * `IntakeTermStart` and a real `CaseReceiptDates`, and read what it writes to
 * the store, because the failure that matters is a case that lands without a
 * start date or with an import's receipt overwritten, and only the write shows
 * either.
 *
 * MUTATION-CHECKED 2026-10-10: dropping the "only when empty" guard on
 * `startDate` in `CaseReceiptDates::changesFor()` reddens
 * testASuppliedStartDateIsKept and testAReplayWritesNothing; returning early
 * from the listener when the calendar does not answer (the old behaviour)
 * reddens testTheDatesDoNotWaitForTheCalendar. Restored after.
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
 *
 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Listener\IntakeTermStartListener;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Intake\CaseReceiptDates;
use OCA\Dossiq\Service\Intake\IntakeTermStart;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TenantConfigurationService;
use OCA\Dossiq\Service\TenantContext;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the listener writes onto a new case.
 *
 * @covers \OCA\Dossiq\Listener\IntakeTermStartListener
 * @covers \OCA\Dossiq\Service\Intake\CaseReceiptDates
 * @uses   \OCA\Dossiq\Service\Intake\IntakeTermStart
 *
 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md
 */
class IntakeTermStartListenerTest extends TestCase {

	/**
	 * The id of the Woo type's `receiptDate` question.
	 *
	 * @var string
	 */
	private const RECEIPT_DEFINITION = 'def-receipt';

	/**
	 * Every payload the listener saved.
	 *
	 * @var \ArrayObject<int, array<string, mixed>>|null
	 */
	private ?\ArrayObject $saves = null;

	/**
	 * The saved payloads, as a list.
	 *
	 * @return array<int, array<string, mixed>> The payloads.
	 */
	private function saved(): array {
		return ($this->saves === null) ? [] : $this->saves->getArrayCopy();
	}//end saved()

	/**
	 * A case filed by hand gets its dates from the moment it was made.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md#requirement-dossiq-sets-the-receipt-and-start-dates-when-a-case-is-created-req-term-042
	 */
	public function testACaseFiledByHandGetsItsDatesFromCreation(): void {
		$this->listener()->handle(self::created(['title' => 'Kosten zwembad'], created: '2026-10-06T10:00:00+02:00'));

		self::assertCount(1, $this->saved(), 'One write carries the stamp and the dates.');
		$case = $this->saved()[0];
		self::assertSame('2026-10-06', $case['startDate']);
		self::assertSame('2026-10-06T10:00:00+02:00', $case['receivedAt']);
		self::assertSame('2026-10-06T10:00:00+02:00', $case['termStartsAt']);
		self::assertSame(
			[['propertyDefinition' => self::RECEIPT_DEFINITION, 'name' => 'receiptDate', 'value' => '2026-10-06']],
			$case['properties'],
			'The Woo question is answered by dossiq, in the case\'s own answers.',
		);
	}//end testACaseFiledByHandGetsItsDatesFromCreation()

	/**
	 * An imported receipt date is kept, and the start date and term follow it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md#requirement-dossiq-sets-the-receipt-and-start-dates-when-a-case-is-created-req-term-042
	 */
	public function testAnImportedReceiptIsKeptAndStartsTheTerm(): void {
		$answers = [['propertyDefinition' => self::RECEIPT_DEFINITION, 'name' => 'receiptDate', 'value' => '2026-09-01']];
		$this->listener()->handle(self::created(['properties' => $answers], created: '2026-10-06T10:00:00+02:00'));

		$case = $this->saved()[0];
		self::assertArrayNotHasKey('properties', $case, 'The import\'s answer is not touched, and no second answer is added.');
		self::assertSame('2026-09-01', $case['startDate']);
		self::assertSame('2026-09-01', substr((string)$case['termStartsAt'], 0, 10), 'The term counts from the import\'s receipt, not from the import.');
	}//end testAnImportedReceiptIsKeptAndStartsTheTerm()

	/**
	 * A start date the creating path supplied is kept.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md#requirement-dossiq-sets-the-receipt-and-start-dates-when-a-case-is-created-req-term-042
	 */
	public function testASuppliedStartDateIsKept(): void {
		$this->listener(declaresReceipt: false)->handle(
			self::created(['startDate' => '2026-10-05', 'receivedAt' => '2026-10-04T20:00:00+02:00'], created: '2026-10-06T10:00:00+02:00')
		);

		$case = $this->saved()[0];
		self::assertArrayNotHasKey('startDate', $case, 'The creating path said Monday; dossiq does not move it to the Sunday it can see.');
		self::assertSame('2026-10-04T20:00:00+02:00', $case['receivedAt']);
		self::assertArrayNotHasKey('properties', $case, 'A type without the question gets no answer invented for it.');
	}//end testASuppliedStartDateIsKept()

	/**
	 * A replayed create event writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md#requirement-dossiq-sets-the-receipt-and-start-dates-when-a-case-is-created-req-term-042
	 */
	public function testAReplayWritesNothing(): void {
		$this->listener()->handle(
			self::created(
				[
					'startDate' => '2026-10-06',
					'termStartsAt' => '2026-10-06T10:00:00+02:00',
					'properties' => [['propertyDefinition' => self::RECEIPT_DEFINITION, 'name' => 'receiptDate', 'value' => '2026-10-06']],
				],
				created: '2026-10-06T10:00:00+02:00'
			)
		);

		self::assertSame([], $this->saved());
	}//end testAReplayWritesNothing()

	/**
	 * The dates are written when the calendar does not answer; the stamp is not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md#requirement-dossiq-sets-the-receipt-and-start-dates-when-a-case-is-created-req-term-042
	 */
	public function testTheDatesDoNotWaitForTheCalendar(): void {
		$this->listener(calendarAnswers: false)->handle(self::created([], created: '2026-10-06T10:00:00+02:00'));

		self::assertCount(1, $this->saved());
		$case = $this->saved()[0];
		self::assertSame('2026-10-06', $case['startDate']);
		self::assertArrayNotHasKey('termStartsAt', $case, 'A half stamp is a claim; the calendar did not answer.');
		self::assertArrayNotHasKey('receivedOutsideWorkingHours', $case);
	}//end testTheDatesDoNotWaitForTheCalendar()

	/**
	 * Something that is not a case is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md#requirement-dossiq-sets-the-receipt-and-start-dates-when-a-case-is-created-req-term-042
	 */
	public function testANonCaseIsLeftAlone(): void {
		$this->listener(slug: 'task')->handle(self::created([], created: '2026-10-06T10:00:00+02:00'));

		self::assertSame([], $this->saved());
	}//end testANonCaseIsLeftAlone()

	/**
	 * A case made at 01:52 in Amsterdam starts that day, not the UTC day before.
	 *
	 * Found live on 11 October 2026: a case created at 01:52 CEST was stored
	 * with startDate 2026-10-10. OpenRegister's materialised `startDate`
	 * calculation runs on the creating event and formats its own `now` in UTC;
	 * the listener only ran afterwards and kept what the case carried. The
	 * listener now sets the day before the case is stored, in the administered
	 * zone, through the real CaseDateNormaliser.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md#requirement-dossiq-sets-the-receipt-and-start-dates-when-a-case-is-created-req-term-042
	 */
	public function testACaseMadeJustAfterMidnightInAmsterdamStartsThatDay(): void {
		$event = self::creating(['title' => 'Kosten zwembad'], created: '2026-10-10T23:52:00+00:00');
		$this->listener(realZone: true)->handle($event);

		$case = (array)$event->getObject()->getObject();
		self::assertSame('2026-10-11', $case['startDate'] ?? null, 'The receipt day is the calendar day in Amsterdam.');
		self::assertSame([], $this->saved(), 'Set before the case is stored, so there is no second write.');
	}//end testACaseMadeJustAfterMidnightInAmsterdamStartsThatDay()

	/**
	 * A Woo case is stored with its receiptDate answer.
	 *
	 * Found live on 11 October 2026: no Woo case had a `receiptDate` answer,
	 * from the UI or the API. The answer was computed, but the second write
	 * that carried it sent the whole case back and the store refused it. Set
	 * before the case is stored, it is part of the create.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md#requirement-dossiq-sets-the-receipt-and-start-dates-when-a-case-is-created-req-term-042
	 */
	public function testAWooCaseIsStoredWithItsReceiptAnswer(): void {
		$answers = [['propertyDefinition' => 'def-subject', 'name' => 'onderwerp', 'value' => 'Kermis']];
		$event = self::creating(['properties' => $answers], created: '2026-10-11T06:26:58+00:00');
		$this->listener(realZone: true)->handle($event);

		$case = (array)$event->getObject()->getObject();
		self::assertSame(
			array_merge($answers, [['propertyDefinition' => self::RECEIPT_DEFINITION, 'name' => 'receiptDate', 'value' => '2026-10-11']]),
			$case['properties'] ?? null,
			'The question is answered next to the answers the case came with.',
		);
		self::assertSame(
			strtotime('2026-10-11T06:26:58+00:00'),
			strtotime((string)($case['receivedAt'] ?? '')),
			'The stamp is set in the same step, at the moment the case was made.',
		);
		self::assertSame([], $this->saved());
	}//end testAWooCaseIsStoredWithItsReceiptAnswer()

	/**
	 * The created-event fallback sends only the fields it adds.
	 *
	 * The store refuses a write that changes a materialised readOnly field
	 * ("Cannot modify readOnly properties: assignedGroupPublicName,
	 * deadline"). The event payload carries those fields as they were before
	 * other listeners moved them, so sending the case back failed every time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md#requirement-dossiq-sets-the-receipt-and-start-dates-when-a-case-is-created-req-term-042
	 */
	public function testTheCreatedFallbackSendsOnlyTheFieldsItAdds(): void {
		$this->listener()->handle(
			self::created(
				['startDate' => '2026-10-06', 'deadline' => '2026-11-03', 'assignedGroupPublicName' => null],
				created: '2026-10-06T10:00:00+02:00'
			)
		);

		self::assertCount(1, $this->saved());
		$written = $this->saved()[0];
		self::assertArrayNotHasKey('WHOLE_OBJECT_SAVE', $written, 'The case is not sent back whole.');
		self::assertSame(
			['properties', 'receivedAt', 'receivedOutsideWorkingHours', 'termStartsAt'],
			self::sortedKeys($written),
			'Only the stamp and the receipt answer are written; deadline and the other readOnly fields are not.',
		);
	}//end testTheCreatedFallbackSendsOnlyTheFieldsItAdds()

	/**
	 * The keys of an array, sorted.
	 *
	 * @param array<string, mixed> $values The array.
	 *
	 * @return array<int, string> The keys.
	 */
	private static function sortedKeys(array $values): array {
		$keys = array_keys($values);
		sort($keys);

		return $keys;
	}//end sortedKeys()

	/**
	 * The real listener over a store double.
	 *
	 * The calendar answers the moment it is asked about, as a calendar does
	 * for a moment inside the working week, or nothing at all.
	 *
	 * @param bool   $declaresReceipt Whether the case type asks the `receiptDate` question.
	 * @param bool   $calendarAnswers Whether the working calendar answers.
	 * @param string $slug            The schema slug the created object resolves to.
	 * @param bool   $realZone        Use the real CaseDateNormaliser, which falls back to Europe/Amsterdam.
	 *
	 * @return IntakeTermStartListener The listener.
	 */
	private function listener(bool $declaresReceipt = true, bool $calendarAnswers = true, string $slug = 'case', bool $realZone = false): IntakeTermStartListener {
		$calendar = $this->createMock(WorkingDayRoll::class);
		$calendar->method('firstWorkingMomentAtOrAfter')->willReturnCallback(
			static fn (DateTimeImmutable $moment): ?DateTimeImmutable => ($calendarAnswers === true) ? $moment : null
		);
		$intake = new IntakeTermStart(calendar: $calendar);

		$caseTypes = $this->createMock(CaseTypeStore::class);
		$caseTypes->method('referenceId')->willReturnCallback(static fn (mixed $value): string => trim((string)$value));
		$caseTypes->method('rowId')->willReturnCallback(static fn (array $row): string => (string)($row['id'] ?? ''));
		$caseTypes->method('rowsOfType')->willReturnCallback(
			static function (string $schemaKey, string $caseTypeId) use ($declaresReceipt): array {
				if ($schemaKey !== 'property_definition_schema' || $caseTypeId !== 'ct-woo' || $declaresReceipt === false) {
					return [['id' => 'def-subject', 'name' => 'onderwerp']];
				}

				return [['id' => 'def-subject', 'name' => 'onderwerp'], ['id' => self::RECEIPT_DEFINITION, 'name' => 'receiptDate']];
			}
		);

		// The administered zone is Amsterdam; the fixtures already carry +02:00.
		$dates = $this->createMock(CaseDateNormaliser::class);
		$dates->method('formatCalendarDate')->willReturnCallback(
			static fn (DateTimeInterface $moment): string => DateTimeImmutable::createFromInterface($moment)
				->setTimezone(new \DateTimeZone('Europe/Amsterdam'))->format('Y-m-d')
		);
		if ($realZone === true) {
			// No tenant bound and no engine calendar: the real class answers
			// its administered fallback, Europe/Amsterdam.
			$tenant = $this->createMock(TenantContext::class);
			$tenant->method('isBound')->willReturn(false);
			$dates = new CaseDateNormaliser(
				tenantContext: $tenant,
				tenantConfiguration: $this->createMock(TenantConfigurationService::class),
				settingsService: $this->createMock(SettingsService::class),
				logger: $this->createMock(LoggerInterface::class),
				time: $this->createMock(ITimeFactory::class),
			);
		}

		$recorder = new \ArrayObject();
		$this->saves = $recorder;
		$objectService = new class($recorder) {
			/**
			 * Constructor.
			 *
			 * @param \ArrayObject<int, array<string, mixed>> $saves Where each save lands.
			 */
			public function __construct(private \ArrayObject $saves) {
			}//end __construct()

			/**
			 * Record a partial write: only the fields it was given.
			 *
			 * @param string               $objectId The object uuid.
			 * @param array<string, mixed> $data     The fields.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed> The fields.
			 */
			public function patchObject(string $objectId, array $data, string $register, string $schema): array {
				$this->saves->append($data);
				return $data;
			}//end patchObject()

			/**
			 * A whole-object save is what the store refused; record it as such.
			 *
			 * @param array<string, mixed> $object   The payload.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string               $uuid     The object uuid.
			 *
			 * @return array<string, mixed> The payload.
			 */
			public function saveObject(array $object, string $register, string $schema, string $uuid): array {
				$this->saves->append(['WHOLE_OBJECT_SAVE' => $object]);
				return $object;
			}//end saveObject()
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? ''
		);

		$slugs = $this->createMock(ObjectSchemaSlugResolver::class);
		$slugs->method('resolveFromPayload')->willReturn($slug);

		return new IntakeTermStartListener(
			intake: $intake,
			slugResolver: $slugs,
			settingsService: $settings,
			logger: $this->createMock(LoggerInterface::class),
			receiptDates: new CaseReceiptDates(intake: $intake, caseTypes: $caseTypes, dates: $dates),
		);
	}//end listener()

	/**
	 * A Woo case about to be stored, carrying these fields.
	 *
	 * @param array<string, mixed> $fields  The case fields.
	 * @param string               $created The platform's creation moment.
	 *
	 * @return ObjectCreatingEvent The event.
	 */
	private static function creating(array $fields, string $created): ObjectCreatingEvent {
		return new ObjectCreatingEvent(self::created($fields, created: $created)->getObject());
	}//end creating()

	/**
	 * A created Woo case carrying these fields.
	 *
	 * @param array<string, mixed> $fields  The case fields.
	 * @param string               $created The platform's creation moment.
	 *
	 * @return ObjectCreatedEvent The event.
	 */
	private static function created(array $fields, string $created): ObjectCreatedEvent {
		// The stub entity carries no creation moment; the real one serialises
		// it as `@self.created`, which is the arrival fallback read here.
		$entity = new class($created) extends ObjectEntity {
			/**
			 * Constructor.
			 *
			 * @param string $createdAt The creation moment.
			 */
			public function __construct(private string $createdAt) {
			}//end __construct()

			/**
			 * The stub's serialisation, plus `@self.created`.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				$data = parent::jsonSerialize();
				$data['@self']['created'] = $this->createdAt;

				return $data;
			}//end jsonSerialize()
		};
		$entity->setUuid('case-1');
		$entity->setSchema('case');
		$entity->setObject(array_merge(['caseType' => 'ct-woo'], $fields));

		return new ObjectCreatedEvent($entity);
	}//end created()
}//end class
