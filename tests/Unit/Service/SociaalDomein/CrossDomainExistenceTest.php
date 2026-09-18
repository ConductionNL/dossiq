<?php

/**
 * Unit tests for CrossDomainExistence.
 *
 * 🔴 THE ASSERTION THAT MATTERS IS WHAT IS **NOT** IN THE ANSWER. Purpose
 * limitation between Wmo, Jeugdwet and Participatiewet does not allow a 360
 * view of a household; it allows three facts, that an open case exists, in
 * which domain, and who to call. A test that checked those three were PRESENT
 * would pass on an answer that also carried the request, the status and the
 * case number, which is the leak the row exists to prevent. So the keys are
 * asserted EXACTLY, against the constant the service publishes, and a seeded
 * row carries fields that must not travel so that "exactly" has something to
 * catch.
 *
 * 🔴 THE SECOND TRAP IS THE BSN KEY. `jeugdwetZaak` names the person in
 * `jeugdigeBsn` and the other two in `bsn`. Filtering all three on `bsn` reads
 * as a clean "no Jeugdwet case exists", never as an error, and that is the one
 * wrong answer this lookup must not give: it tells a consulent nobody else is
 * working with a household that somebody is. The Jeugdwet case in the fixture
 * is there to make that failure visible.
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
 * Unit tests for the cross-domain existence projection.
 *
 * @covers \OCA\Dossiq\Service\SociaalDomein\CrossDomainExistence
 *
 * @uses \OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class CrossDomainExistenceTest extends TestCase {

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
	 * One open Jeugdwet case, carrying content that must not travel.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeSociaalDomeinObjects();
		$this->objects->seed(
			'jeugdwetZaak',
			[
				'id' => 'jw-1',
				'caseNumber' => 'JW-2026-0044',
				// THE KEY THAT IS NOT `bsn`.
				'jeugdigeBsn' => self::BSN,
				'status' => 'support-loopt',
				'handlerId' => 'bram',
				'supportRequest' => 'Vader vraagt om begeleiding bij het gedrag van Sem',
				'districtTeam' => 'Wijkteam Noord',
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
	 * 🔴 A Wmo consulent learns the household is known to Jeugdwet, and who to call.
	 *
	 * @return void
	 */
	public function testAConsulentLearnsAHouseholdIsKnownElsewhere(): void {
		$found = $this->existence->lookUp(
			bsn: self::BSN,
			ownDomain: 'Wmo',
			ground: 'wettelijke-taak',
			requester: 'anna',
		);

		self::assertCount(1, $found);
		self::assertTrue($found[0]['exists']);
		self::assertSame('Jeugdwet', $found[0]['domain']);
		self::assertSame('bram', $found[0]['contact']);
	}//end testAConsulentLearnsAHouseholdIsKnownElsewhere()

	/**
	 * 🔴 The answer carries EXACTLY three fields, and no content.
	 *
	 * @return void
	 */
	public function testNothingLeaksThroughTheProjection(): void {
		$found = $this->existence->lookUp(
			bsn: self::BSN,
			ownDomain: 'Wmo',
			ground: 'wettelijke-taak',
			requester: 'anna',
		);

		self::assertSame(
			CrossDomainExistence::ANSWER_FIELDS,
			array_keys($found[0]),
			'the answer is the existence, the domain and the contact, and nothing else'
		);

		// Said the other way round as well, because the assertion above would
		// pass on a projection that renamed a leak into one of those three.
		$encoded = json_encode($found);
		self::assertStringNotContainsString('JW-2026-0044', $encoded, 'no case number');
		self::assertStringNotContainsString('support-loopt', $encoded, 'no status');
		self::assertStringNotContainsString('Vader vraagt', $encoded, 'no content');
	}//end testNothingLeaksThroughTheProjection()

	/**
	 * 🔴 The Jeugdwet case is FOUND, which is what the bsn-key map buys.
	 *
	 * The mirror of it: a lookup that filtered on `bsn` would answer an empty
	 * list here, indistinguishable from a household nobody else is working with.
	 *
	 * @return void
	 */
	public function testTheJeugdwetCaseIsFoundOnItsOwnBsnKey(): void {
		self::assertSame(
			'jeugdigeBsn',
			SociaalDomeinStore::DOMAINS['Jeugdwet']['bsnKey'],
			'Jeugdwet names the person differently from the other two'
		);

		// The control: the same person under the OTHER key is not this case, so
		// the test above cannot pass by the store matching everything.
		$this->objects->seed(
			'jeugdwetZaak',
			['id' => 'jw-2', 'bsn' => self::BSN, 'status' => 'support-loopt', 'handlerId' => 'chris']
		);

		$found = $this->existence->lookUp(
			bsn: self::BSN,
			ownDomain: 'Wmo',
			ground: 'wettelijke-taak',
			requester: 'anna',
		);

		self::assertCount(1, $found);
		self::assertSame('bram', $found[0]['contact'], 'the case matched on jeugdigeBsn, not the decoy');
	}//end testTheJeugdwetCaseIsFoundOnItsOwnBsnKey()

	/**
	 * The caller's own domain is excluded: they can already see their own work.
	 *
	 * @return void
	 */
	public function testTheCallersOwnDomainIsNotAnswered(): void {
		$found = $this->existence->lookUp(
			bsn: self::BSN,
			ownDomain: 'Jeugdwet',
			ground: 'wettelijke-taak',
			requester: 'bram',
		);

		self::assertSame([], $found);
	}//end testTheCallersOwnDomainIsNotAnswered()

	/**
	 * A closed case is not an open case, so it is not answered.
	 *
	 * @return void
	 */
	public function testAClosedCaseIsNotAnswered(): void {
		$this->objects->store['jeugdwetZaak']['jw-1']['status'] = 'closed';

		self::assertSame(
			[],
			$this->existence->lookUp(
				bsn: self::BSN,
				ownDomain: 'Wmo',
				ground: 'wettelijke-taak',
				requester: 'anna',
			)
		);
	}//end testAClosedCaseIsNotAnswered()

	/**
	 * 🔴 A case with no readable status counts as OPEN.
	 *
	 * The alternative answers "nobody else is working with this household"
	 * about a household somebody is working with, which is the one wrong answer
	 * this lookup must not give.
	 *
	 * @return void
	 */
	public function testACaseWithNoStatusCountsAsOpen(): void {
		unset($this->objects->store['jeugdwetZaak']['jw-1']['status']);

		$found = $this->existence->lookUp(
			bsn: self::BSN,
			ownDomain: 'Wmo',
			ground: 'wettelijke-taak',
			requester: 'anna',
		);

		self::assertCount(1, $found);
	}//end testACaseWithNoStatusCountsAsOpen()

	/**
	 * A case with no handler falls back to the team, then to the domain.
	 *
	 * "A case exists and we cannot tell you who to ask" helps nobody
	 * coordinate, which is the whole point of the answer.
	 *
	 * @return void
	 */
	public function testTheContactFallsBackToTheTeam(): void {
		unset($this->objects->store['jeugdwetZaak']['jw-1']['handlerId']);

		$found = $this->existence->lookUp(
			bsn: self::BSN,
			ownDomain: 'Wmo',
			ground: 'wettelijke-taak',
			requester: 'anna',
		);

		self::assertSame('Wijkteam Noord', $found[0]['contact']);
	}//end testTheContactFallsBackToTheTeam()

	/**
	 * A lookup naming no person is refused before it reads anything.
	 *
	 * @return void
	 */
	public function testALookupWithNoPersonIsRefused(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('lookup_needs_a_person');

		$this->existence->lookUp(bsn: '  ', ownDomain: 'Wmo', ground: 'wettelijke-taak', requester: 'anna');
	}//end testALookupWithNoPersonIsRefused()
}//end class
