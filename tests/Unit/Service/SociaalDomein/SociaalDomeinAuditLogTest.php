<?php

/**
 * Unit tests for the ground a lookup runs on, and the log it writes.
 *
 * 🔴 `authorisationGround` HAS BEEN DECLARED AND UNREAD SINCE THE REGISTER WAS
 * WRITTEN, which is the defect gap row 5.18 names in as many words. A field
 * nobody writes is worse than no field: the register says the grounds are
 * recorded, and a subject access request that reads the log finds every entry
 * empty. So these tests assert the field is POPULATED, not that the log has a
 * row in it.
 *
 * 🔴 THE LOG IS THE DELIVERABLE AND NOT A SIDE EFFECT (D-8), so the answer and
 * the entry are one act. The test for that is the one that looks up a person
 * who is NOT known anywhere: a lookup that found nothing is still a lookup that
 * happened, and it is exactly the one a person asking what was looked up about
 * them would otherwise never hear about. A log written only on a hit is a log
 * that hides every fishing expedition.
 *
 * 🔴 THE GROUND IS CHOSEN BEFORE THE ANSWER (D-7). The refusal is asserted with
 * the store untouched, because a ground checked after the read is a ground
 * recorded about data that has already been seen.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\SociaalDomein;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\SociaalDomein\CrossDomainExistence;
use OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The ground, the refusal without one, and what the log carries.
 *
 * @covers \OCA\Dossiq\Service\SociaalDomein\CrossDomainExistence
 *
 * @uses \OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\SettingsService
 */
class SociaalDomeinAuditLogTest extends TestCase {

	/**
	 * The in-memory store.
	 *
	 * @var FakeSociaalDomeinObjects
	 */
	private FakeSociaalDomeinObjects $objects;

	/**
	 * The service under test.
	 *
	 * @var CrossDomainExistence
	 */
	private CrossDomainExistence $existence;

	/**
	 * The person the fixture is about.
	 *
	 * @var string
	 */
	private const BSN = '123456782';

	/**
	 * One open Participatiewet case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeSociaalDomeinObjects();
		$this->objects->seed(
			'participatiewetZaak',
			[
				'id' => 'pw-1',
				'bsn' => self::BSN,
				'status' => 'social-assistance-active',
				'handlerId' => 'chris',
			]
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);

		$this->existence = new CrossDomainExistence(
			store: new SociaalDomeinStore(settingsService: $settings, logger: new NullLogger()),
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * 🔴 No ground, no answer, and nothing read.
	 *
	 * @return void
	 */
	public function testALookupWithNoGroundIsRefusedBeforeAnythingIsRead(): void {
		try {
			$this->existence->lookUp(bsn: self::BSN, ownDomain: 'Wmo', ground: '', requester: 'anna');
			self::fail('A lookup with no ground must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('lookup-needs-a-ground', $e->getRule());
			self::assertStringContainsString('the person can ask to see it', $e->getSentence());
		}

		// No log row, because nothing was looked up: a refusal is not an act
		// the subject has to be told about, and recording one would fill the
		// log with lookups that did not happen.
		self::assertSame([], $this->objects->rowsOf('sociaalDomeinAuditLog'));
	}//end testALookupWithNoGroundIsRefusedBeforeAnythingIsRead()

	/**
	 * A ground nobody can be held to is refused too.
	 *
	 * @return void
	 */
	public function testAnUnrecognisedGroundIsRefused(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('lookup_ground_not_recognised');

		$this->existence->lookUp(
			bsn: self::BSN,
			ownDomain: 'Wmo',
			ground: 'onderzoek',
			requester: 'anna',
		);
	}//end testAnUnrecognisedGroundIsRefused()

	/**
	 * 🔴 The ground is written with the answer, and the field is POPULATED.
	 *
	 * @return void
	 */
	public function testTheGroundIsWrittenWithTheAnswer(): void {
		$this->existence->lookUp(
			bsn: self::BSN,
			ownDomain: 'Wmo',
			ground: 'samenwerkingsafspraak',
			requester: 'anna',
		);

		$log = $this->objects->rowsOf('sociaalDomeinAuditLog');
		self::assertCount(1, $log);

		$entry = $log[0];
		self::assertSame(
			'samenwerkingsafspraak',
			$entry['authorisationGround'],
			'the field that was declared and never written now carries the ground'
		);
		self::assertSame(self::BSN, $entry['subjectBsn'], 'the person looked up');
		self::assertSame('anna', $entry['employeeId'], 'the requester');
		self::assertSame(CrossDomainExistence::ACTION, $entry['action']);
		self::assertNotSame('', trim((string)$entry['moment']), 'the moment');
		self::assertStringContainsString('Participatiewet', (string)$entry['returned'], 'what came back');
		self::assertSame(CrossDomainExistence::ANSWER_FIELDS, $entry['geraadpleegdeVelden']);
	}//end testTheGroundIsWrittenWithTheAnswer()

	/**
	 * 🔴 A lookup that found nothing is logged too.
	 *
	 * A log written only on a hit hides every fishing expedition, and the
	 * person who was searched for and not found is the person least likely to
	 * ever hear about it.
	 *
	 * @return void
	 */
	public function testALookupThatFoundNothingIsStillLogged(): void {
		$found = $this->existence->lookUp(
			bsn: '999999990',
			ownDomain: 'Wmo',
			ground: 'vitaal-belang',
			requester: 'anna',
		);

		self::assertSame([], $found);

		$log = $this->objects->rowsOf('sociaalDomeinAuditLog');
		self::assertCount(1, $log);
		self::assertSame('999999990', $log[0]['subjectBsn']);
		self::assertSame('No open case in another domain.', $log[0]['returned']);
	}//end testALookupThatFoundNothingIsStillLogged()

	/**
	 * 🔴 A person can be told what was looked up about them, twice over.
	 *
	 * @return void
	 */
	public function testAPersonCanBeToldWhatWasLookedUpAboutThem(): void {
		$this->existence->lookUp(
			bsn: self::BSN,
			ownDomain: 'Wmo',
			ground: 'wettelijke-taak',
			requester: 'anna',
		);
		$this->existence->lookUp(
			bsn: self::BSN,
			ownDomain: 'Jeugdwet',
			ground: 'toestemming',
			requester: 'bram',
		);

		$lookups = $this->existence->lookupsAbout(bsn: self::BSN);

		self::assertCount(2, $lookups);

		// 🔑 THE SET, NOT THE ORDER. Both lookups land in the same second, so
		// their `moment` is the same string and the sort cannot separate them.
		// Asserting an order here would be asserting insertion order under
		// another name, and it would go red on a machine that happened to
		// cross a second boundary between the two calls. Ordering has its own
		// test below, over rows with moments that differ.
		$grounds = array_column($lookups, 'ground');
		sort($grounds);
		self::assertSame(['toestemming', 'wettelijke-taak'], $grounds, 'both grounds come back');

		$requesters = array_column($lookups, 'requester');
		sort($requesters);
		self::assertSame(['anna', 'bram'], $requesters, 'and who made each lookup');
		self::assertNotSame('', $lookups[0]['groundLabel'], 'the ground reads as a sentence, not as a slug');
		foreach ($lookups as $lookup) {
			self::assertNotSame('', trim((string)$lookup['moment']), 'each carries its date');
		}
	}//end testAPersonCanBeToldWhatWasLookedUpAboutThem()

	/**
	 * The lookups come back newest first, so the most recent reads first.
	 *
	 * Over seeded rows with moments a day apart, because two calls made in one
	 * second carry the same moment and cannot be ordered at all.
	 *
	 * @return void
	 */
	public function testTheLookupsComeBackNewestFirst(): void {
		foreach (['2026-06-01T09:00:00+00:00', '2026-09-01T09:00:00+00:00'] as $moment) {
			$this->objects->seed(
				'sociaalDomeinAuditLog',
				[
					'caseId' => self::BSN,
					'subjectBsn' => self::BSN,
					'action' => CrossDomainExistence::ACTION,
					'moment' => $moment,
					'authorisationGround' => 'wettelijke-taak',
					'employeeId' => 'anna',
					'returned' => 'No open case in another domain.',
				]
			);
		}

		$lookups = $this->existence->lookupsAbout(bsn: self::BSN);

		self::assertSame(
			['2026-09-01T09:00:00+00:00', '2026-06-01T09:00:00+00:00'],
			array_column($lookups, 'moment')
		);
	}//end testTheLookupsComeBackNewestFirst()

	/**
	 * A read of somebody else's log is not returned in this person's answer.
	 *
	 * The control for the test above: without it, `lookupsAbout` returning
	 * EVERY log row would pass it.
	 *
	 * @return void
	 */
	public function testAnotherPersonsLookupsAreNotReturned(): void {
		$this->existence->lookUp(
			bsn: self::BSN,
			ownDomain: 'Wmo',
			ground: 'wettelijke-taak',
			requester: 'anna',
		);
		$this->existence->lookUp(
			bsn: '999999990',
			ownDomain: 'Wmo',
			ground: 'wettelijke-taak',
			requester: 'anna',
		);

		self::assertCount(1, $this->existence->lookupsAbout(bsn: self::BSN));
	}//end testAnotherPersonsLookupsAreNotReturned()

	/**
	 * An ordinary case read in the log is not a cross-domain lookup.
	 *
	 * Reading a case and asking whether one exists anywhere are different acts,
	 * and a subject access request has to be able to tell them apart.
	 *
	 * @return void
	 */
	public function testAnOrdinaryReadIsNotReportedAsALookup(): void {
		$this->objects->seed(
			'sociaalDomeinAuditLog',
			[
				'caseId' => 'case-1',
				'subjectBsn' => self::BSN,
				'action' => 'read',
				'moment' => '2026-06-01T09:00:00+00:00',
				'authorisationGround' => 'roltoewijzing',
			]
		);

		self::assertSame([], $this->existence->lookupsAbout(bsn: self::BSN));
	}//end testAnOrdinaryReadIsNotReportedAsALookup()
}//end class
