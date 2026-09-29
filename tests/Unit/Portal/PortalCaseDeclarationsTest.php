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
 * @spec openspec/changes/portal-citizen-writes-on-the-case/tasks.md
 * @spec openspec/changes/portal-case-list-declarations/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Portal\PortalContributionProvider
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
		$this->assertSame('endDate', $cases['closedField']);
		$this->assertContains('endDate', $cases['fields'], 'portaliq reads the closed marker from the projected row');
		$this->assertSame('caseType', $cases['caseTypeField']);
		$this->assertContains('caseType', $cases['fields']);
		$this->assertSame(
			['register' => 'dossiq', 'schema' => 'caseType', 'labelField' => 'title'],
			$cases['caseTypeSource']
		);
		$this->assertArrayHasKey('title', $this->properties['caseType']);
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
