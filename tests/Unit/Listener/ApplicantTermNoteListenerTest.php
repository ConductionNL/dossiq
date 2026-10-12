<?php

/**
 * ApplicantTermNoteListener unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-applicant-reads-the-decision-date-and-the-state-of-the-term-req-wds-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\AppInfo\Registrar\CaseTypeListenerRegistrar;
use OCA\Dossiq\Listener\ApplicantTermNoteListener;
use OCA\Dossiq\Listener\CaseDeadlineFollowsTermListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\ApplicantTermNote;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\Dossiq\Service\Termijn\TermInstanceStore;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A case save carries what the applicant is told about the term, through the
 * two real listeners in the order they are registered.
 *
 * @covers \OCA\Dossiq\Listener\ApplicantTermNoteListener
 * @covers \OCA\Dossiq\AppInfo\Registrar\CaseTypeListenerRegistrar
 * @uses   \OCA\Dossiq\Service\Termijn\ApplicantTermNote
 * @uses   \OCA\Dossiq\Listener\CaseDeadlineFollowsTermListener
 * @uses   \OCA\Dossiq\Service\Termijn\CaseDeadlineMirror
 * @uses   \OCA\Dossiq\Service\Termijn\TermInstanceStore
 * @uses   \OCA\Dossiq\Service\TermKind
 * @uses   \OCA\Dossiq\Service\Transitions\CaseTypeReader
 * @uses   \OCA\Dossiq\Service\Archival\ReadsConfiguredRows
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 */
class ApplicantTermNoteListenerTest extends TestCase {

	/**
	 * The store: term instances and case types.
	 *
	 * @var FakeTermijnStore
	 */
	private FakeTermijnStore $objects;

	/**
	 * The statutory term's deadline listener, which runs first.
	 *
	 * @var CaseDeadlineFollowsTermListener
	 */
	private CaseDeadlineFollowsTermListener $deadline;

	/**
	 * The listener under test.
	 *
	 * @var ApplicantTermNoteListener
	 */
	private ApplicantTermNoteListener $listener;

	/**
	 * Wire both listeners over one store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case-schema-id',
				'case_type_schema' => 'caseType',
				'termijn_instance_schema' => 'deadlineInstance',
				default => '',
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$this->deadline = new CaseDeadlineFollowsTermListener(
			settingsService: $settings,
			mirror: new CaseDeadlineMirror(
				settingsService: $settings,
				store: new TermInstanceStore(settingsService: $settings, logger: $logger),
				logger: $logger,
			),
			logger: $logger,
		);
		$this->listener = new ApplicantTermNoteListener(
			settingsService: $settings,
			note: new ApplicantTermNote(),
			caseTypes: new CaseTypeReader($settings),
			logger: $logger,
		);

		$this->objects->seed('caseType', ['id' => 'ct-1', 'title' => 'Verzoek om informatie', 'extensionAllowed' => true, 'extensionPeriod' => 'P14D']);
	}//end setUp()

	/**
	 * Give the case a statutory term that ends on 3 November 2026.
	 *
	 * @return void
	 */
	private function seedTerm(): void {
		$this->objects->seed(
			'deadlineInstance',
			['id' => 't1', 'case' => 'case-1', 'status' => 'verlengd', 'endDateCurrent' => '2026-11-03', 'startDate' => '2026-09-22T10:00:00+02:00']
		);
	}//end seedTerm()

	/**
	 * A case about to be updated, after both listeners ran in their registered order.
	 *
	 * @param array<string, mixed> $payload  Its fields.
	 * @param string               $schemaId Its schema.
	 *
	 * @return ObjectUpdatingEvent The event.
	 */
	private function update(array $payload, string $schemaId = 'case-schema-id'): ObjectUpdatingEvent {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		$entity->setSchema($schemaId);
		$entity->setUuid('case-1');

		$event = new ObjectUpdatingEvent($entity, null);
		$this->deadline->handle($event);
		$this->listener->handle($event);

		return $event;
	}//end update()

	/**
	 * A paused term says why and gives no date (scenario 1).
	 *
	 * @return void
	 */
	public function testAnOpenRequestWritesTheStandingSentenceAndClearsTheDate(): void {
		$this->seedTerm();
		$modified = $this->update([
			'caseType' => 'ct-1',
			'deadline' => '2026-10-20',
			'legalDecisionDate' => '2026-10-20',
			'termNote' => '',
			'waitingOnApplicant' => true,
			'waitingOnApplicantSince' => '2026-10-06T09:12:00+02:00',
		])->getModifiedData();

		self::assertSame(
			'De termijn staat stil sinds 6 oktober 2026. Hij loopt weer zodra wij uw antwoord hebben.',
			$modified['termNote']
		);
		self::assertArrayHasKey('legalDecisionDate', $modified);
		self::assertNull($modified['legalDecisionDate']);
		self::assertSame('2026-11-03', $modified['deadline'], 'The deadline listener\'s own write survives.');
	}//end testAnOpenRequestWritesTheStandingSentenceAndClearsTheDate()

	/**
	 * An extension is told with its reason, and the date is the deadline the term decides (scenario 2).
	 *
	 * @return void
	 */
	public function testAnExtensionWritesItsReasonAndTheTermsDeadline(): void {
		$this->seedTerm();
		$modified = $this->update([
			'caseType' => 'ct-1',
			// The payload still carries the calculated date; the term decides.
			'deadline' => '2026-10-20',
			'extensionCount' => 1,
			'activity' => (string)json_encode([['type' => 'extend', 'reason' => 'Wij moeten derden om hun mening vragen.', 'newEndDate' => '2026-11-03']]),
		])->getModifiedData();

		self::assertSame(
			'Wij hebben de termijn met twee weken verlengd. De reden: Wij moeten derden om hun mening vragen.',
			$modified['termNote']
		);
		self::assertSame('2026-11-03', $modified['legalDecisionDate']);
	}//end testAnExtensionWritesItsReasonAndTheTermsDeadline()

	/**
	 * The request closes: the sentence goes and the date comes back.
	 *
	 * @return void
	 */
	public function testAClosedRequestClearsTheSentenceAndBringsTheDateBack(): void {
		$this->seedTerm();
		$modified = $this->update([
			'caseType' => 'ct-1',
			'deadline' => '2026-11-03',
			'statutoryDeadline' => '2026-11-03',
			'termNote' => 'De termijn staat stil sinds 6 oktober 2026. Hij loopt weer zodra wij uw antwoord hebben.',
			'legalDecisionDate' => null,
			'waitingOnApplicant' => false,
			'waitingOnApplicantSince' => null,
		])->getModifiedData();

		self::assertSame('', $modified['termNote']);
		self::assertSame('2026-11-03', $modified['legalDecisionDate']);
	}//end testAClosedRequestClearsTheSentenceAndBringsTheDateBack()

	/**
	 * A save that changes nothing about the term writes neither field.
	 *
	 * @return void
	 */
	public function testAnUnchangedNoteIsNotWrittenAgain(): void {
		$this->seedTerm();
		$modified = $this->update([
			'caseType' => 'ct-1',
			'title' => 'Nieuwe titel',
			'deadline' => '2026-11-03',
			'legalDecisionDate' => '2026-11-03',
			'termNote' => '',
		])->getModifiedData();

		self::assertArrayNotHasKey('termNote', $modified);
		self::assertArrayNotHasKey('legalDecisionDate', $modified);
	}//end testAnUnchangedNoteIsNotWrittenAgain()

	/**
	 * A case without a statutory term tells the date the save carries.
	 *
	 * @return void
	 */
	public function testACaseWithoutAStatutoryTermTellsTheDeadlineItCarries(): void {
		$modified = $this->update(['caseType' => 'ct-1', 'deadline' => '2026-12-01'])->getModifiedData();

		self::assertSame(['legalDecisionDate' => '2026-12-01'], $modified);
	}//end testACaseWithoutAStatutoryTermTellsTheDeadlineItCarries()

	/**
	 * Another schema is left alone, and a create is not handled.
	 *
	 * @return void
	 */
	public function testAnotherSchemaAndACreateAreLeftAlone(): void {
		$paused = ['waitingOnApplicant' => true, 'waitingOnApplicantSince' => '2026-10-06'];

		self::assertSame([], $this->update($paused, 'other-schema')->getModifiedData());

		$entity = new ObjectEntity();
		$entity->setObject($paused);
		$entity->setSchema('case-schema-id');
		$entity->setUuid('case-1');
		$creating = new ObjectCreatingEvent($entity);
		$this->listener->handle($creating);
		self::assertSame([], $creating->getModifiedData());
	}//end testAnotherSchemaAndACreateAreLeftAlone()

	/**
	 * The listener is registered on the update, below the deadline it reads.
	 *
	 * @return void
	 */
	public function testItIsRegisteredBelowTheTermDeadline(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$registered): void {
				$registered[] = [$event, $listener, $priority];
			}
		);

		(new CaseTypeListenerRegistrar())->register($context);

		self::assertContains([ObjectUpdatingEvent::class, ApplicantTermNoteListener::class, -120], $registered);
		self::assertLessThan(
			CaseTypeListenerRegistrar::TERM_DEADLINE_PRIORITY,
			CaseTypeListenerRegistrar::TERM_NOTE_PRIORITY
		);
	}//end testItIsRegisteredBelowTheTermDeadline()
}//end class
