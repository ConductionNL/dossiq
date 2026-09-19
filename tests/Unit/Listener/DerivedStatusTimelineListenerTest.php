<?php

/**
 * A derived status move on the case timeline.
 *
 * The derivation runs before the case is written and the entry is recorded
 * after, so the pair only works if the staged move survives between them and
 * is taken exactly once. These tests hold onto that: a staged move that landed
 * is recorded, a move nobody staged is not, and a staged move the save did not
 * land is not either.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\DerivedStatusTimelineListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Status\DerivedStatusJournal;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the post-persist derived-status timeline writer.
 *
 * @covers \OCA\Dossiq\Listener\DerivedStatusTimelineListener
 *
 * @uses \OCA\Dossiq\Service\Status\DerivedStatusJournal
 * @uses \OCA\Dossiq\Service\Timeline\TimelineKinds
 * @uses \OCA\Dossiq\Service\Transitions\StatusTypeLookup
 * @uses \OCA\Dossiq\Service\CaseTypeResolver
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 */
class DerivedStatusTimelineListenerTest extends TestCase {

	/**
	 * The journal the two halves share.
	 *
	 * @var DerivedStatusJournal
	 */
	private DerivedStatusJournal $journal;

	/**
	 * The mocked timeline seam.
	 *
	 * @var CaseTimeline|MockObject
	 */
	private CaseTimeline $timeline;

	/**
	 * What the seam was last handed.
	 *
	 * @var array<string, mixed>
	 */
	private array $seen = [];

	/**
	 * How many entries the seam was asked to write.
	 *
	 * @var int
	 */
	private int $writes = 0;

	/**
	 * The listener under test.
	 *
	 * @var DerivedStatusTimelineListener
	 */
	private DerivedStatusTimelineListener $listener;

	/**
	 * Build the listener over a journal and a capturing seam.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->seen = [];
		$this->writes = 0;
		$this->journal = new DerivedStatusJournal();

		$this->timeline = $this->createMock(CaseTimeline::class);
		$this->timeline->method('record')->willReturnCallback(
			function (
				string $caseId,
				string $kind,
				string $message,
				array $fields = [],
				string $visibility = 'internal',
				array $relatedCaseIds = [],
			): string {
				$this->seen = compact('caseId', 'kind', 'message', 'fields', 'visibility');
				$this->writes++;

				return 'entry-1';
			}
		);

		$objectService = new class {
			/**
			 * Read one statusType.
			 *
			 * @param string $id       The object id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array<string, mixed> The row.
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): array {
				return ([
					'st-open' => ['id' => 'st-open', 'name' => 'In behandeling'],
					'st-compleet' => ['id' => 'st-compleet', 'name' => 'Compleet'],
					'st-besloten' => [
						'id' => 'st-besloten',
						'name' => 'Besloten',
						'publicLabel' => 'Besluit genomen',
					],
				][$id] ?? []);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ([
				'register' => 'dossiq',
				'status_type_schema' => 'statusType',
				'case_type_schema' => 'caseType',
			][$key] ?? '')
		);

		$this->listener = new DerivedStatusTimelineListener(
			$this->journal,
			$this->timeline,
			new StatusTypeLookup($settings, new CaseTypeResolver(new CaseTypeStore($settings))),
			new NullLogger(),
		);
	}//end setUp()

	/**
	 * A derived move the save landed is recorded once the write is through.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testALandedDerivationReachesTheTimeline(): void {
		$this->journal->stage(caseId: 'case-1', fromStatus: 'st-open', toStatus: 'st-compleet');

		$this->listener->handle($this->saved(caseId: 'case-1', status: 'st-compleet'));

		self::assertSame(1, $this->writes);
		self::assertSame('case-1', $this->seen['caseId']);
		self::assertSame('statuswijziging', $this->seen['kind']);
		self::assertSame('st-open', $this->seen['fields']['from']);
		self::assertSame('st-compleet', $this->seen['fields']['to']);
		self::assertSame('Afgeleide status', $this->seen['fields']['label']);
		self::assertSame('internal', $this->seen['visibility']);
		self::assertStringContainsString('Compleet', $this->seen['message']);
	}//end testALandedDerivationReachesTheTimeline()

	/**
	 * A derived move carries no actor and no explanation, because nobody made
	 * it and nobody explained it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testADerivedMoveNamesNoActorAndInventsNoReason(): void {
		$this->journal->stage(caseId: 'case-1', fromStatus: 'st-open', toStatus: 'st-compleet');

		$this->listener->handle($this->saved(caseId: 'case-1', status: 'st-compleet'));

		self::assertSame('', $this->seen['fields']['actor']);
		self::assertSame('', $this->seen['fields']['explanation']);
		self::assertSame('', $this->seen['fields']['statusRecordId']);
	}//end testADerivedMoveNamesNoActorAndInventsNoReason()

	/**
	 * A derived move into an announced status is announced, in its public words.
	 *
	 * The reason it was derived does not travel with it: "omdat de zaak
	 * daaraan voldoet" is the workflow explaining itself to a handler, and the
	 * applicant is not the audience for that sentence.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function testADerivedMoveIntoAnAnnouncedStatusIsPublic(): void {
		$this->journal->stage(caseId: 'case-1', fromStatus: 'st-open', toStatus: 'st-besloten');

		$this->listener->handle($this->saved(caseId: 'case-1', status: 'st-besloten'));

		self::assertSame(1, $this->writes);
		self::assertSame('public', $this->seen['visibility']);
		self::assertSame('Status: Besluit genomen', $this->seen['message']);
	}//end testADerivedMoveIntoAnAnnouncedStatusIsPublic()

	/**
	 * A move nobody staged writes nothing.
	 *
	 * This is what stops the timeline saying twice what happened once: the
	 * guarded transition, the free-form move, the ending acts and the reopen
	 * all write their own entry through `CaseStatusStore`, and all four also
	 * fire this event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testAMoveNobodyDerivedIsNotRecordedTwice(): void {
		$this->listener->handle($this->saved(caseId: 'case-1', status: 'st-compleet'));

		self::assertSame(0, $this->writes);
	}//end testAMoveNobodyDerivedIsNotRecordedTwice()

	/**
	 * A staged move the save did not land writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testADerivationThatDidNotLandIsNotRecorded(): void {
		$this->journal->stage(caseId: 'case-1', fromStatus: 'st-open', toStatus: 'st-compleet');

		$this->listener->handle($this->saved(caseId: 'case-1', status: 'st-open'));

		self::assertSame(0, $this->writes);
	}//end testADerivationThatDidNotLandIsNotRecorded()

	/**
	 * A staged move is recorded once, not on every later save of the case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testAStagedMoveIsRecordedOnlyOnce(): void {
		$this->journal->stage(caseId: 'case-1', fromStatus: 'st-open', toStatus: 'st-compleet');

		$this->listener->handle($this->saved(caseId: 'case-1', status: 'st-compleet'));
		$this->listener->handle($this->saved(caseId: 'case-1', status: 'st-compleet'));

		self::assertSame(1, $this->writes);
	}//end testAStagedMoveIsRecordedOnlyOnce()

	/**
	 * A derivation onto the status the case already carries is not a move.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testADerivationThatChangesNothingStagesNothing(): void {
		$this->journal->stage(caseId: 'case-1', fromStatus: 'st-compleet', toStatus: 'st-compleet');

		$this->listener->handle($this->saved(caseId: 'case-1', status: 'st-compleet'));

		self::assertSame(0, $this->writes);
	}//end testADerivationThatChangesNothingStagesNothing()

	/**
	 * A saved-case event over one payload.
	 *
	 * @param string $caseId The case id.
	 * @param string $status The status it landed on.
	 *
	 * @return ObjectUpdatedEvent The event as OpenRegister dispatches it.
	 */
	private function saved(string $caseId, string $status): ObjectUpdatedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(['id' => $caseId, 'status' => $status]);

		return new ObjectUpdatedEvent($entity);
	}//end saved()
}//end class
