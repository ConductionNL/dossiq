<?php

/**
 * Unit tests for HouseholdCases (board DqSociaalDomein, "Andere zaken van dit huishouden").
 *
 * 🔴 THE ASSERTION THAT MATTERS IS WHAT AN OTHER-DOMAIN ROW DOES NOT CARRY.
 * The rows of another domain are keyed EXACTLY to exists, domain and contact,
 * against the constant CrossDomainExistence publishes, while the seeded case
 * carries a number, a request and a status that must not travel.
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
use OCA\Dossiq\Service\SociaalDomein\HouseholdCases;
use OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for HouseholdCases.
 */
class HouseholdCasesTest extends TestCase {
	/**
	 * The child's BSN.
	 */
	private const CHILD = '111222333';

	/**
	 * The parent's BSN.
	 */
	private const PARENT = '444555666';

	/**
	 * The objects behind the store.
	 *
	 * @var FakeSociaalDomeinObjects
	 */
	private FakeSociaalDomeinObjects $objects;

	/**
	 * The service under test.
	 *
	 * @var HouseholdCases
	 */
	private HouseholdCases $household;

	/**
	 * A youth case with a plan of two people, a sibling case in the same
	 * category, one in another category, and a Wmo case of the parent.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeSociaalDomeinObjects();
		$this->objects->seed('caseType', ['id' => 'ct-jeugd', 'title' => 'Melding Jeugdwet', 'category' => 'Jeugd']);
		$this->objects->seed('caseType', ['id' => 'ct-permit', 'title' => 'Omgevingsvergunning', 'category' => 'Vergunningen']);
		$this->objects->seed(
			'gezinsplan',
			[
				'id' => 'plan-1',
				'gezinsleden' => [
					['role' => 'jeugdige', 'bsn' => self::CHILD],
					['role' => 'ouder', 'bsn' => self::PARENT],
					['role' => 'ouder', 'bsn' => ''],
				],
			]
		);
		$this->objects->seed('brpPerson', ['id' => 'p-child', 'citizenServiceNumber' => self::CHILD]);
		$this->objects->seed('brpPerson', ['id' => 'p-parent', 'citizenServiceNumber' => self::PARENT]);
		$this->objects->seed('case', ['id' => 'case-own', 'caseType' => 'ct-jeugd', 'gezinsplanId' => 'plan-1', 'initiatorSourceId' => 'p-child']);
		$this->objects->seed(
			'case',
			[
				'id' => 'case-sibling', 'identifier' => '2026-0090', 'title' => 'Melding broer', 'caseType' => 'ct-jeugd',
				'initiatorSourceId' => 'p-parent', 'statusPublicLabel' => 'In behandeling', 'statusRole' => 'in-progress',
			]
		);
		$this->objects->seed('case', ['id' => 'case-hidden', 'identifier' => '2026-0091', 'caseType' => 'ct-jeugd', 'initiatorSourceId' => 'p-parent']);
		$this->objects->seed('case', ['id' => 'case-permit', 'identifier' => '2026-0092', 'caseType' => 'ct-permit', 'initiatorSourceId' => 'p-parent']);
		$this->objects->seed(
			'wmoZaak',
			[
				'id' => 'wmo-1', 'caseNumber' => 'WMO-2025-0412', 'bsn' => self::PARENT, 'status' => 'in-behandeling',
				'handlerId' => 'anouk', 'supportRequest' => 'Huishoudelijke hulp', 'districtTeam' => 'Wijkteam Oost',
			]
		);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$store = new SociaalDomeinStore(settingsService: $settings, logger: new NullLogger());

		$this->household = new HouseholdCases(
			store: $store,
			existence: new CrossDomainExistence(store: $store, logger: new NullLogger()),
		);
	}//end setUp()

	/**
	 * The case's own row.
	 *
	 * @return array<string, mixed>
	 */
	private function ownCase(): array {
		return $this->objects->store['case']['case-own'];
	}//end ownCase()

	/**
	 * The household is the people in the family plan, and nobody without a BSN.
	 *
	 * @return void
	 */
	public function testTheHouseholdIsThePeopleInThePlan(): void {
		self::assertSame(expected: [self::CHILD, self::PARENT], actual: $this->household->householdBsns(case: $this->ownCase()));
	}//end testTheHouseholdIsThePeopleInThePlan()

	/**
	 * A case with no plan has no household rows.
	 *
	 * @return void
	 */
	public function testACaseWithoutAPlanHasNoHousehold(): void {
		self::assertSame(expected: [], actual: $this->household->householdBsns(case: ['id' => 'x', 'gezinsplanId' => '']));
	}//end testACaseWithoutAPlanHasNoHousehold()

	/**
	 * In this domain: the household's cases the reader may open, in full, and
	 * not the case itself, an unreadable one or one of another category.
	 *
	 * @return void
	 */
	public function testShowsTheReadableCasesOfThisDomainInFull(): void {
		$rows = $this->household->sameDomain(
			case: $this->ownCase(),
			bsns: [self::CHILD, self::PARENT],
			canRead: fn (string $id): bool => $id !== 'case-hidden',
		);

		self::assertSame(
			expected: [
				[
					'caseId' => 'case-sibling', 'identifier' => '2026-0090', 'title' => 'Melding broer',
					'caseTypeTitle' => 'Melding Jeugdwet', 'statusLabel' => 'In behandeling', 'statusRole' => 'in-progress', 'access' => 'full',
				],
			],
			actual: $rows
		);
	}//end testShowsTheReadableCasesOfThisDomainInFull()

	/**
	 * 🔴 Another domain answers exists, domain and contact, and NOTHING else.
	 *
	 * @return void
	 */
	public function testAnotherDomainCarriesExistsDomainAndContactOnly(): void {
		$rows = $this->household->otherDomains(
			bsns: [self::CHILD, self::PARENT],
			ownDomain: $this->household->ownDomain(case: $this->ownCase()),
			ground: 'wettelijke-taak',
			requester: 'fatma',
		);

		self::assertCount(expectedCount: 1, haystack: $rows);
		self::assertSame(expected: CrossDomainExistence::ANSWER_FIELDS, actual: array_keys($rows[0]));
		self::assertSame(expected: ['exists' => true, 'domain' => 'Wmo', 'contact' => 'anouk'], actual: $rows[0]);
		$encoded = json_encode($rows);
		foreach (['WMO-2025-0412', 'Huishoudelijke hulp', 'in-behandeling', self::PARENT] as $leak) {
			self::assertStringNotContainsString(needle: $leak, haystack: (string)$encoded);
		}
	}//end testAnotherDomainCarriesExistsDomainAndContactOnly()

	/**
	 * 🔴 Every person looked up is one log entry, on the chosen ground.
	 *
	 * @return void
	 */
	public function testEveryPersonLookedUpIsLogged(): void {
		$this->household->otherDomains(bsns: [self::CHILD, self::PARENT], ownDomain: 'Jeugdwet', ground: 'toestemming', requester: 'fatma');

		$log = $this->objects->rowsOf(CrossDomainExistence::LOG_SCHEMA);
		self::assertCount(expectedCount: 2, haystack: $log);
		self::assertSame(expected: ['toestemming', 'toestemming'], actual: array_column($log, 'authorisationGround'));
	}//end testEveryPersonLookedUpIsLogged()

	/**
	 * 🔴 No ground, no lookup and no log.
	 *
	 * @return void
	 */
	public function testNoGroundIsRefusedBeforeAnyLookup(): void {
		try {
			$this->household->otherDomains(bsns: [self::PARENT], ownDomain: 'Jeugdwet', ground: '', requester: 'fatma');
			self::fail(message: 'A lookup without a ground was answered');
		} catch (RefusedException $e) {
			self::assertSame(expected: 'lookup-needs-a-ground', actual: $e->getRule());
		}

		self::assertSame(expected: [], actual: $this->objects->rowsOf(CrossDomainExistence::LOG_SCHEMA));
	}//end testNoGroundIsRefusedBeforeAnyLookup()

	/**
	 * A youth case's domain is Jeugdwet, which its lookup leaves out.
	 *
	 * @return void
	 */
	public function testTheOwnDomainFollowsTheCaseTypeCategory(): void {
		self::assertSame(expected: 'Jeugdwet', actual: $this->household->ownDomain(case: $this->ownCase()));
		self::assertSame(expected: '', actual: $this->household->domainOf(category: 'Vergunningen'));
	}//end testTheOwnDomainFollowsTheCaseTypeCategory()
}//end class
