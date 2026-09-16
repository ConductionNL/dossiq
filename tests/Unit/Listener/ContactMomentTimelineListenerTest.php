<?php

/**
 * A logged contact on the case timeline, and which side of the counter it sits on.
 *
 * The three tests that used to live in `ContactMomentServiceTest` moved here
 * with the writer itself, because the service is no longer the only surface
 * that logs a contact: the Communication tab's Log contact action saves
 * straight to OpenRegister. The event is the seam both surfaces cross, so the
 * event is where the entry is now written and where it is now tested.
 *
 * The visibility tests are the point of the file. A contact is written for the
 * handler, and an absent flag is an unticked box rather than an unknown
 * answer, so a contact that says nothing about visibility must come out
 * internal. Nothing else in this repository can see that default: the flag is
 * a boolean on a record, and a wrong default reads exactly like a right one
 * until an applicant is looking at a note that was never meant for them.
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
 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\ContactMomentTimelineListener;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the post-persist contactmoment timeline writer.
 *
 * @covers \OCA\Dossiq\Listener\ContactMomentTimelineListener
 *
 * @uses \OCA\Dossiq\Service\Timeline\TimelineKinds
 */
class ContactMomentTimelineListenerTest extends TestCase {

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
	 * @var integer
	 */
	private int $writes = 0;

	/**
	 * The listener under test.
	 *
	 * @var ContactMomentTimelineListener
	 */
	private ContactMomentTimelineListener $listener;

	/**
	 * Build the listener over a capturing seam and a slug resolver that answers
	 * whatever the payload already spells.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->seen = [];
		$this->writes = 0;

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
				$this->seen = compact('caseId', 'kind', 'message', 'fields', 'visibility', 'relatedCaseIds');
				$this->writes++;

				return 'entry-1';
			}
		);

		$resolver = $this->createMock(ObjectSchemaSlugResolver::class);
		$resolver->method('resolveFromPayload')->willReturnCallback(
			static fn (array $payload): string => (string)($payload['@self']['schema'] ?? '')
		);

		$this->listener = new ContactMomentTimelineListener($this->timeline, $resolver);
	}//end setUp()

	/**
	 * A logged contact puts a line on the case's timeline, carrying the
	 * channel, the direction and its own record id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function testALoggedContactReachesTheTimeline(): void {
		$this->listener->handle($this->created([
			'notificationChannel' => 'phone',
			'direction' => 'inbound',
			'summary' => 'Asked about the hearing date',
			'case' => 'case-uuid-1',
		]));

		self::assertSame(1, $this->writes);
		self::assertSame('case-uuid-1', $this->seen['caseId']);
		self::assertSame('contactmoment', $this->seen['kind']);
		self::assertSame('Asked about the hearing date', $this->seen['message']);
		self::assertSame('phone', $this->seen['fields']['channel']);
		self::assertSame('inbound', $this->seen['fields']['direction']);
		self::assertSame('cm-1', $this->seen['fields']['contactmomentId']);
	}//end testALoggedContactReachesTheTimeline()

	/**
	 * A contact that says nothing about visibility stays inside.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function testAContactIsInternalUnlessTheHandlerSaidOtherwise(): void {
		$this->listener->handle($this->created([
			'notificationChannel' => 'phone',
			'case' => 'case-uuid-1',
		]));

		self::assertSame('internal', $this->seen['visibility']);
	}//end testAContactIsInternalUnlessTheHandlerSaidOtherwise()

	/**
	 * A ticked box, and only a ticked box, makes the entry public.
	 *
	 * The falsy values are in the same test on purpose. OpenRegister stores a
	 * boolean, but a form that posts `"false"` or `0` would otherwise flip the
	 * default the whole change rests on, and the flip would be silent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function testOnlyATickedBoxMakesTheEntryPublic(): void {
		$this->listener->handle($this->created([
			'notificationChannel' => 'phone',
			'case' => 'case-uuid-1',
			'visibleToApplicant' => true,
		]));

		self::assertSame('public', $this->seen['visibility']);

		foreach ([false, 0, '', 'false', null] as $falsy) {
			$this->listener->handle($this->created([
				'notificationChannel' => 'phone',
				'case' => 'case-uuid-1',
				'visibleToApplicant' => $falsy,
			]));

			self::assertSame(
				'internal',
				$this->seen['visibility'],
				'a contactmoment carrying ' . var_export($falsy, true) . ' is not visible to the applicant'
			);
		}
	}//end testOnlyATickedBoxMakesTheEntryPublic()

	/**
	 * A contact about several cases is written onto each of them, through the
	 * related-cases list the KCC voorblad already keeps.
	 *
	 * The case itself is dropped from that list rather than passed twice: both
	 * surfaces seed `relatedCases` with the case, and the entry is already
	 * being written on it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function testAContactAboutSeveralCasesReachesEachTimeline(): void {
		$this->listener->handle($this->created([
			'notificationChannel' => 'phone',
			'case' => 'case-uuid-1',
			'relatedCases' => ['case-uuid-1', 'case-uuid-2'],
		]));

		self::assertSame(['case-uuid-2'], $this->seen['relatedCaseIds']);
	}//end testAContactAboutSeveralCasesReachesEachTimeline()

	/**
	 * A contact that names no case has no timeline to reach, and the seam is
	 * not called at all rather than called with an empty case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function testAContactWithoutACaseWritesNoEntry(): void {
		$this->listener->handle($this->created([
			'notificationChannel' => 'phone',
		]));

		self::assertSame(0, $this->writes);
	}//end testAContactWithoutACaseWritesNoEntry()

	/**
	 * Every other schema's creates travel past untouched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function testAnotherSchemaWritesNoEntry(): void {
		$this->listener->handle($this->created(
			['case' => 'case-uuid-1', 'summary' => 'not a contact'],
			schema: 'beschikking'
		));

		self::assertSame(0, $this->writes);
	}//end testAnotherSchemaWritesNoEntry()

	/**
	 * An update is not a new contact, so it writes no second entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function testAnUpdateWritesNoEntry(): void {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn([
			'@self' => ['id' => 'cm-1', 'schema' => 'contactmoment'],
			'case' => 'case-uuid-1',
		]);

		$this->listener->handle(new ObjectUpdatedEvent($entity));

		self::assertSame(0, $this->writes);
	}//end testAnUpdateWritesNoEntry()

	/**
	 * A created contactmoment, as OpenRegister hands it to a listener.
	 *
	 * @param array<string, mixed> $record The stored fields.
	 * @param string               $schema The schema slug the resolver answers.
	 *
	 * @return ObjectCreatedEvent The event.
	 */
	private function created(array $record, string $schema = 'contactmoment'): ObjectCreatedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(
			array_merge(['@self' => ['id' => 'cm-1', 'schema' => $schema]], $record)
		);

		return new ObjectCreatedEvent($entity);
	}//end created()
}//end class
