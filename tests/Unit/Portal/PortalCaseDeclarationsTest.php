<?php

/**
 * Portal case declarations test
 *
 * dossiq#3152: a resident could not list, amend or withdraw a dossiq case in
 * the portal, because the case collection carried no `kind: cases` and no
 * `type: update` action on `case` carried a `citizenWrite` block. The shapes
 * asserted here are portaliq's, read at portaliq development 4176916:
 * - `lib/Service/PortalCaseListReader.php:98` keeps only `kind: cases`;
 * - `lib/Service/PortalCaseTypeCatalogue.php:247` reads `caseTypeSource`
 *   (`register`, `schema`, `labelField`) only on a `kind: cases` collection;
 * - `lib/Contribution/CitizenWriteConfigNormaliser.php` requires `typeField`,
 *   `typeRegister`, `typeSchema` on a `type: update` action, and defaults
 *   `statusField: status` and `recordField: portalWrites`;
 * - `lib/Contribution/CitizenWriteActionFinder.php:63` takes the FIRST update
 *   action on the case's register and schema that carries `citizenWrite`;
 * - `lib/Service/CitizenWritableSetResolver.php` reads `portalWritable`,
 *   `portalAmendmentWindow`, `portalDocumentWindow` and `portalWithdrawal` on
 *   the case type, in the shapes of portaliq's `portalCaseType` schema.
 * - `lib/Service/OidcClaimMapperService.php` gives a DigiD login audience
 *   `client`, so a provider that serves only `citizen` is never asked.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/portal-contribution/spec.md
 * @spec openspec/changes/portal-case-list-declarations/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Portal\PortalContributionProvider
 * @uses   \OCA\Dossiq\Portal\PortalPages
 * @uses   \OCA\Dossiq\Portal\CitizenManifest
 * @uses   \OCA\Dossiq\Portal\PortalConversation
 */
class PortalCaseDeclarationsTest extends TestCase {
	/**
	 * The provider under test.
	 *
	 * @var PortalContributionProvider
	 */
	private PortalContributionProvider $provider;

	/**
	 * Properties per schema slug, base register plus every fragment.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $properties = [];

	protected function setUp(): void {
		parent::setUp();
		$this->provider = new PortalContributionProvider();

		$settingsDir = __DIR__ . '/../../../lib/Settings';
		$files = array_merge([$settingsDir . '/dossiq_register.json'], glob($settingsDir . '/register.d/*.json'));
		foreach ($files as $file) {
			$decoded = json_decode((string)file_get_contents($file), true);
			foreach (($decoded['components']['schemas'] ?? []) as $slug => $schema) {
				$this->properties[$slug] = array_merge(($this->properties[$slug] ?? []), ($schema['properties'] ?? []));
			}
		}
	}

	/**
	 * A DigiD login is audience `client`; it reads the same case manifest as `citizen`.
	 *
	 * @return void
	 */
	public function testAResidentSignedInWithDigidReadsTheirCases(): void {
		$this->assertContains('client', $this->provider->getAudiences());
		$client = $this->provider->getContribution(['audience' => 'client']);
		$this->assertIsArray($client);
		$this->assertSame($this->provider->getContribution(['audience' => 'citizen']), $client);
	}

	/**
	 * The case collection is listed on "My cases", with its closed marker and its case type source.
	 *
	 * @return void
	 */
	public function testTheCaseCollectionIsACasesCollection(): void {
		$cases = $this->caseCollection();
		$this->assertSame('cases', $cases['kind']);
		$this->assertSame('isFinalStatus', $cases['closedField']);
		$this->assertContains('isFinalStatus', $cases['fields'], 'portaliq reads the closed marker from the projected row');
		$base = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'), true);
		$this->assertSame('boolean', $base['components']['schemas']['case']['properties']['isFinalStatus']['type']);
		$this->assertSame('caseType', $cases['caseTypeField']);
		$this->assertContains('caseType', $cases['fields']);
		$this->assertSame(
			['register' => 'dossiq', 'schema' => 'caseType', 'labelField' => 'title'],
			$cases['caseTypeSource']
		);
		$this->assertArrayHasKey('title', $this->properties['caseType']);
	}

	/**
	 * A case withdrawn from the portal lands on a final status, so "My cases" files it under closed.
	 *
	 * The withdrawal writes the status the case type names and no end date,
	 * so a marker on `endDate` left every withdrawn request under "Lopend".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-case-list-declarations/specs/portal-contribution/spec.md#requirement-the-case-list-says-what-is-a-case-and-when-it-is-closed-req-portal-010
	 */
	public function testAWithdrawnWooRequestIsListedAsClosed(): void {
		$seed = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/81-woo-verzoek.json'),
			true
		);
		$objects = [];
		foreach (($seed['components']['objects'] ?? []) as $object) {
			$objects[(string)($object['id'] ?? '')] = $object;
		}

		$caseType = $objects['3c0f5a00-0000-4000-a000-00000000a001'];
		$target = (string)$caseType['portalWithdrawal']['targetStatus'];
		$this->assertTrue($objects[$target]['isFinal'], 'the status a withdrawal lands on is final');

		$cases = $this->caseCollection();
		$withdrawn = ['status' => $target, 'withdrawnAt' => '2026-10-02T20:00:24+00:00', 'endDate' => null, 'isFinalStatus' => true];
		$running = ['status' => '3c0f5a00-0000-4000-a000-00000000b001', 'endDate' => null, 'isFinalStatus' => false];
		$this->assertTrue($this->listedAsClosed(row: $withdrawn, field: (string)$cases['closedField']));
		$this->assertFalse($this->listedAsClosed(row: $running, field: (string)$cases['closedField']));
	}

	/**
	 * Portaliq's reading of a closed marker (CaseRowMarker::isClosed): any value but null, '', [] or false.
	 *
	 * @param array<string, mixed> $row   The case row.
	 * @param string               $field The declared closed field.
	 *
	 * @return bool
	 */
	private function listedAsClosed(array $row, string $field): bool {
		$value = ($row[$field] ?? null);
		return $value !== null && $value !== '' && $value !== [] && $value !== false;
	}

	/**
	 * Exactly one update action on `case` carries `citizenWrite`, and portaliq's normaliser keeps it.
	 *
	 * @return void
	 */
	public function testOneUpdateActionOpensTheCaseForTheResident(): void {
		$updates = array_values(
			array_filter(
				$this->provider->getContribution(['audience' => 'client'])['actions'],
				static fn (array $action): bool => ($action['type'] ?? '') === 'update'
					&& ($action['schema'] ?? '') === 'case'
					&& isset($action['citizenWrite']) === true
			)
		);
		$this->assertCount(1, $updates);
		$action = $updates[0];

		$this->assertSame('amendCase', $action['id']);
		$this->assertSame('dossiq', $action['register']);
		$this->assertSame('portalSubject', $action['scopeField']);
		// The ceiling: whatever a case type opens, portaliq narrows it to this
		// list, and nothing a handler decides is on it.
		$this->assertSame(['description'], $action['fields']);

		$write = $action['citizenWrite'];
		foreach (['typeField', 'typeRegister', 'typeSchema'] as $required) {
			$this->assertIsString($write[$required]);
			$this->assertNotSame('', $write[$required]);
		}

		$this->assertSame(['typeField' => 'caseType', 'typeRegister' => 'dossiq', 'typeSchema' => 'caseType'], $write);
		foreach ($action['fields'] as $field) {
			$this->assertArrayHasKey($field, $this->properties['case']);
		}
	}

	/**
	 * The case records the resident's writes, under portaliq's default field names.
	 *
	 * @return void
	 */
	public function testTheCaseCarriesTheRecordOfPortalWrites(): void {
		$case = $this->properties['case'];
		$this->assertSame('array', $case['portalWrites']['type']);
		$this->assertSame('array', $case['portalDocuments']['type']);
		// Portaliq appends to both on every UPDATE of the case, and
		// OpenRegister refuses an update that changes a readOnly property
		// (openregister ValidateObject::validateReadOnlyConstraints). A
		// readOnly here would make every amendment and withdrawal fail.
		$this->assertArrayNotHasKey('readOnly', $case['portalWrites']);
		$this->assertArrayNotHasKey('readOnly', $case['portalDocuments']);

		// A withdrawal also stamps these two on the case
		// (portaliq CitizenCaseController::applyWithdrawal), the time in
		// DATE_ATOM, which is a valid date-time.
		$this->assertSame('date-time', $case['withdrawnAt']['format']);
		$this->assertSame('string', $case['withdrawalReason']['type']);
	}

	/**
	 * The case type declares what is open, in portaliq's `portalCaseType` shapes.
	 *
	 * @return void
	 */
	public function testTheCaseTypeDeclaresTheWindowsInPortaliqsShape(): void {
		$type = $this->properties['caseType'];

		$this->assertSame('array', $type['portalWritable']['type']);
		$this->assertSame(
			['field', 'audiences', 'openStatuses', 'closedReason'],
			array_keys($type['portalWritable']['items']['properties'])
		);
		foreach (['portalAmendmentWindow', 'portalDocumentWindow'] as $window) {
			$this->assertSame('object', $type[$window]['type']);
			$this->assertSame(['openStatuses', 'closedReason'], array_keys($type[$window]['properties']));
		}

		$this->assertSame(
			['openStatuses', 'closedReason', 'targetStatus', 'confirmText'],
			array_keys($type['portalWithdrawal']['properties'])
		);
	}

	/**
	 * The resident's case collection.
	 *
	 * @return array<string, mixed>
	 */
	/**
	 * A company signing in with eHerkenning gets audience `supplier` from
	 * portaliq's preset, and reads its cases beside the procurement pages
	 * (design D1). The inspector reads no cases.
	 *
	 * @return void
	 */
	public function testACompanyOnEherkenningReadsItsCasesBesideProcurement(): void {
		$supplier = $this->provider->getContribution(['audience' => 'supplier']);
		$this->assertIsArray($supplier);
		$ids = array_column($supplier['collections'], 'id');
		$this->assertContains('mijnZaken', $ids);
		foreach (['tenders', 'contracts', 'invoices', 'messages'] as $procurement) {
			$this->assertContains($procurement, $ids, 'the procurement pages stay');
		}

		$cases = array_values(array_filter($supplier['collections'], static fn (array $c): bool => $c['id'] === 'mijnZaken'))[0];
		$this->assertSame($this->caseCollection(), $cases, 'every audience reads the same case declaration');
		$this->assertSame('portalSubject', $cases['scopeField'], 'a company reads only the cases filed under its own subject');

		$inspector = $this->provider->getContribution(['audience' => 'inspector']);
		$this->assertNotContains('mijnZaken', array_column($inspector['collections'], 'id'));
	}//end testACompanyOnEherkenningReadsItsCasesBesideProcurement()

	/**
	 * The branch a company filed under and the ways in a case type admits are
	 * register properties (design D3, D4).
	 *
	 * @return void
	 */
	public function testTheBranchAndTheWaysInAreDeclaredOnTheRegister(): void {
		$branch = $this->properties['case']['portalBranch'];
		$this->assertSame('string', $branch['type']);
		$this->assertSame('^[0-9]{12}$', $branch['pattern'], 'a vestigingsnummer is twelve digits');
		$this->assertFalse($branch['visible'], 'not on the staff forms');

		$kinds = $this->properties['caseType']['portalIdentityKind'];
		$this->assertSame('array', $kinds['type']);
		$this->assertSame(['account', 'reference'], $kinds['items']['enum']);
		foreach ([$branch, $kinds] as $property) {
			$this->assertNotSame('', (string)($property['title'] ?? ''));
			$this->assertNotSame('', (string)($property['description'] ?? ''));
		}
	}//end testTheBranchAndTheWaysInAreDeclaredOnTheRegister()

	/**
	 * 🔴 No seeded case type admits a case number and an address as a way in:
	 * portaliq does not yet check the address against the case, so admitting
	 * it would let anyone who knows a case number read that case (design D4).
	 *
	 * @return void
	 */
	public function testNoSeededCaseTypeAdmitsTheReferenceWayIn(): void {
		$settingsDir = __DIR__ . '/../../../lib/Settings';
		$files = array_merge([$settingsDir . '/dossiq_register.json'], glob($settingsDir . '/register.d/*.json'));
		$seeded = 0;
		foreach ($files as $file) {
			$decoded = json_decode((string)file_get_contents($file), true);
			foreach (($decoded['components']['objects'] ?? []) as $object) {
				if (array_key_exists('portalIdentityKind', (array)$object) === true) {
					$seeded++;
					$this->assertNotContains('reference', (array)$object['portalIdentityKind'], basename($file));
				}
			}
		}

		$this->assertGreaterThanOrEqual(0, $seeded);
	}//end testNoSeededCaseTypeAdmitsTheReferenceWayIn()

	/**
	 * The case list names the field holding the branch and the one holding
	 * the case number, and projects both: portaliq drops a branch field the
	 * row does not carry (design D3, D4).
	 *
	 * @return void
	 */
	public function testTheCaseListNamesItsBranchAndCaseNumberFields(): void {
		$cases = $this->caseCollection();
		$this->assertSame('portalBranch', $cases['branchField']);
		$this->assertContains('portalBranch', $cases['fields']);
		$this->assertSame('identifier', $cases['referenceField']);
		$this->assertContains('identifier', $cases['fields']);
		$this->assertNotContains(
			'portalBranch',
			$this->provider->citizenCaseFields(),
			'the acknowledgement does not quote a branch number back'
		);
	}//end testTheCaseListNamesItsBranchAndCaseNumberFields()

	/**
	 * A resident may propose a corrected title or description, and nothing
	 * the organisation decides (portal-change-proposals-on-the-case D1). The
	 * case list offers the action, which is where portaliq's site looks.
	 *
	 * @return void
	 */
	public function testAResidentMayProposeOnlyWhatTheySupplied(): void {
		$client = $this->provider->getContribution(['audience' => 'client']);
		$proposals = array_values(array_filter($client['actions'], static fn (array $a): bool => ($a['type'] ?? '') === 'propose-change'));
		$this->assertCount(1, $proposals);
		$action = $proposals[0];
		$this->assertSame('proposeCaseChange', $action['id']);
		$this->assertSame(['register' => 'dossiq', 'schema' => 'case', 'scopeField' => 'portalSubject'], array_intersect_key($action, array_flip(['register', 'schema', 'scopeField'])));
		$this->assertSame(['title', 'description'], $action['proposable']);
		foreach ($action['proposable'] as $field) {
			$this->assertArrayHasKey($field, $this->properties['case']);
		}

		foreach (['status', 'result', 'deadline', 'assignee', 'caseType'] as $organisations) {
			$this->assertNotContains($organisations, $action['proposable']);
		}

		$this->assertSame(['proposeCaseChange'], $this->caseCollection()['rowActions']);
	}//end testAResidentMayProposeOnlyWhatTheySupplied()

	/**
	 * The case declares portaliq's proposal queue as a linked type, so the
	 * handler's panel mounts on the case (D2).
	 *
	 * @return void
	 */
	public function testTheCaseDeclaresTheProposalQueue(): void {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/77-portaliq-change-proposals.json'),
			true
		);
		$this->assertSame(
			['portaliq-change-proposal-queue'],
			$fragment['components']['schemas']['case']['configuration']['linkedTypes']
		);
	}//end testTheCaseDeclaresTheProposalQueue()

	/**
	 * A company on a municipal portal reads the resident's pages, worded for
	 * a company, and no procurement page (site-business-and-authorisation D1).
	 *
	 * @return void
	 */
	public function testACompanyOnAMunicipalPortalReadsTheResidentPagesWordedForACompany(): void {
		$this->assertContains('business', $this->provider->getAudiences());
		$business = $this->provider->getContribution(['audience' => 'business']);
		$client = $this->provider->getContribution(['audience' => 'client']);
		$this->assertSame(array_column($client['collections'], 'id'), array_column($business['collections'], 'id'));
		$this->assertSame(array_column($client['actions'], 'id'), array_column($business['actions'], 'id'));
		$this->assertNotContains('tenders', array_column($business['collections'], 'id'));
		$this->assertSame('Uw bedrijf', $business['label']);

		$overview = array_values(array_filter($business['pages'], static fn (array $p): bool => $p['id'] === 'overzicht'))[0];
		$cases = array_values(array_filter($overview['blocks'], static fn (array $b): bool => $b['type'] === 'cases'))[0];
		$this->assertSame('Lopende zaken van uw bedrijf', $cases['label']);

		$residentOverview = array_values(array_filter($client['pages'], static fn (array $p): bool => $p['id'] === 'overzicht'))[0];
		$residentCases = array_values(array_filter($residentOverview['blocks'], static fn (array $b): bool => $b['type'] === 'cases'))[0];
		$this->assertSame('Lopende zaken', $residentCases['label'], 'the resident keeps their own words');

		$this->assertContains('tenders', array_column($this->provider->getContribution(['audience' => 'supplier'])['collections'], 'id'));
	}//end testACompanyOnAMunicipalPortalReadsTheResidentPagesWordedForACompany()

	/**
	 * The case list names the party field portaliq's mandate filter reads,
	 * projects it, and keeps it out of the acknowledgement (D2).
	 *
	 * @return void
	 */
	public function testTheCaseListNamesThePartyAMandateReaches(): void {
		$cases = $this->caseCollection();
		$this->assertSame('portalParty', $cases['mandateField']);
		$this->assertContains('portalParty', $cases['fields']);
		$this->assertNotContains('portalParty', $this->provider->citizenCaseFields());
		$party = $this->properties['case']['portalParty'];
		$this->assertSame('^(subject:.+|kvk:[0-9]{8})$', $party['pattern']);
		$this->assertFalse($party['visible']);
	}//end testTheCaseListNamesThePartyAMandateReaches()

	private function caseCollection(): array {
		$contribution = $this->provider->getContribution(['audience' => 'client']);
		$this->assertIsArray($contribution);
		foreach ($contribution['collections'] as $collection) {
			if ($collection['id'] === 'mijnZaken') {
				return $collection;
			}
		}

		$this->fail('mijnZaken is not declared');
	}
}
