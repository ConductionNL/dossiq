<?php

/**
 * AnonymisedCaseExport: identifying fields removed or generalised per case type.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-case-list-can-be-exported-anonymised-req-sdc-anon-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Export;

use OCA\Dossiq\Kernel\Contracts\TenantAuditTrailServiceInterface;
use OCA\Dossiq\Service\Export\AnonymisedCaseExport;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * No BSN and no name leave, an address leaves as its postcode area and a date as its year,
 * and every export is in the processing log.
 *
 * @covers \OCA\Dossiq\Service\Export\AnonymisedCaseExport
 * @uses   \OCA\Dossiq\Service\Custody\DossierRegister
 */
class AnonymisedCaseExportTest extends TestCase {

	/**
	 * The processing log.
	 *
	 * @var TenantAuditTrailServiceInterface&MockObject
	 */
	private $audit;

	/**
	 * The export over an in-memory register.
	 *
	 * @param array<string, array<string, mixed>> $objects Objects per "schema/id".
	 *
	 * @return AnonymisedCaseExport
	 */
	private function exporter(array $objects): AnonymisedCaseExport {
		$store = new class($objects) {
			/**
			 * @param array<string, array<string, mixed>> $objects Objects per "schema/id".
			 */
			public function __construct(private array $objects) {
			}

			/**
			 * @param string     $id       The id.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id, int|string $register='', int|string $schema=''): array {
				return ($this->objects[$schema . '/' . $id] ?? throw new DoesNotExistException('not found'));
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default=''): string => (['register' => 'dossiq', 'case_schema' => 'case', 'case_type_schema' => 'caseType'][$key] ?? $default)
		);
		$this->audit = $this->createMock(TenantAuditTrailServiceInterface::class);

		return new AnonymisedCaseExport(settingsService: $settings, auditTrail: $this->audit);
	}//end exporter()

	/**
	 * A Participatiewet case of the given number.
	 *
	 * @param int $n The number.
	 *
	 * @return array<string, mixed>
	 */
	private function bijstandCase(int $n): array {
		return [
			'id' => 'c-' . $n,
			'caseType' => 'ct-bijstand',
			'title' => 'Aanvraag levensonderhoud',
			'subjectBsn' => '99999' . sprintf('%04d', $n),
			'requester' => 'Inwoner ' . $n,
			'birthDate' => '1987-06-' . sprintf('%02d', (($n % 28) + 1)),
			'address' => ['street' => 'Markt ' . $n, 'postcode' => '2611 GW', 'city' => 'Delft'],
			'status' => 'in-behandeling',
			'@self' => ['owner' => 'pieter', 'organisation' => 'gemeente'],
		];
	}//end bijstandCase()

	/**
	 * Twenty Participatiewet cases leave with no BSN, no name and birth years only.
	 *
	 * @return void
	 */
	public function testTheAnonymisedExportHoldsNoBsn(): void {
		$objects = ['caseType/ct-bijstand' => ['id' => 'ct-bijstand', 'identifyingFields' => ['subjectBsn', 'requester', 'birthDate', 'address', 'notThere']]];
		$ids = [];
		for ($n = 1; $n <= 20; $n++) {
			$objects['case/c-' . $n] = $this->bijstandCase($n);
			$ids[] = 'c-' . $n;
		}

		$exporter = $this->exporter($objects);
		$this->audit->expects($this->once())->method('emit')->with(
			[
				'action' => 'case_list_exported_anonymised',
				'actor' => 'pieter',
				'resource' => 'cases',
				'bio' => ['count' => 20, 'caseTypes' => ['ct-bijstand']],
			]
		)->willReturn([]);

		$rows = $exporter->export(caseIds: $ids, userId: 'pieter');

		$this->assertCount(20, $rows);
		$this->assertSame(
			['id' => 'c-3', 'caseType' => 'ct-bijstand', 'title' => 'Aanvraag levensonderhoud', 'birthDate' => '1987', 'address' => '2611', 'status' => 'in-behandeling'],
			$rows[2]
		);
		$text = json_encode($rows);
		$this->assertStringNotContainsString('99999', $text);
		$this->assertStringNotContainsString('Inwoner', $text);
		$this->assertStringNotContainsString('Markt', $text);
		$this->assertStringNotContainsString('pieter', $text);
	}//end testTheAnonymisedExportHoldsNoBsn()

	/**
	 * Each case is anonymised by its own case type; a missing case is left out; duplicates once.
	 *
	 * @return void
	 */
	public function testEachCaseTypeAnonymisesItsOwnFields(): void {
		$exporter = $this->exporter(
			[
				'caseType/ct-a' => ['identifyingFields' => ['requester', ' ', 7]],
				'caseType/ct-b' => [],
				'case/c-1' => ['id' => 'c-1', 'caseType' => 'ct-a', 'requester' => 'Jan', 'subjectBsn' => '123'],
				'case/c-2' => ['id' => 'c-2', 'caseType' => 'ct-b', 'requester' => 'Piet'],
			]
		);

		$rows = $exporter->export(caseIds: [' c-1 ', 'c-2', 'c-1', 'gone', '', null], userId: 'pieter');

		$this->assertSame([['id' => 'c-1', 'caseType' => 'ct-a', 'subjectBsn' => '123'], ['id' => 'c-2', 'caseType' => 'ct-b', 'requester' => 'Piet']], $rows);
	}//end testEachCaseTypeAnonymisesItsOwnFields()

	/**
	 * A value is a postcode area if it holds a postcode, a year if it is a date, and otherwise gone.
	 *
	 * @return void
	 */
	public function testGeneralising(): void {
		$row = $this->exporter([])->anonymise(
			row: [
				'home' => 'Markt 1, 2611GW Delft',
				'born' => '2001-02-03T00:00:00+01:00',
				'post' => ['postalCode' => '1012 AB'],
				'other' => ['street' => 'Dam 1'],
				'number' => 42,
				'name' => 'Anouk',
				'kept' => 'Anouk',
			],
			identifyingFields: ['home', 'born', 'post', 'other', 'number', 'name']
		);

		$this->assertSame(['home' => '2611', 'born' => '2001', 'post' => '1012', 'kept' => 'Anouk'], $row);
	}//end testGeneralising()

	/**
	 * An unreadable case type refuses the whole export and nothing reaches the log.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseTypeRefusesTheExport(): void {
		$exporter = $this->exporter(['case/c-1' => ['id' => 'c-1', 'caseType' => 'ct-gone'], 'case/c-2' => ['id' => 'c-2']]);
		$this->audit->expects($this->never())->method('emit');

		foreach ([['c-1'], ['c-2']] as $ids) {
			try {
				$exporter->export(caseIds: $ids, userId: 'pieter');
				$this->fail('exported without the identifying fields');
			} catch (RuntimeException $e) {
				$this->assertSame('The case type of a listed case could not be read, so nothing was exported', $e->getMessage());
			}
		}
	}//end testAnUnreadableCaseTypeRefusesTheExport()

	/**
	 * Nothing listed, or too much, is refused.
	 *
	 * @return void
	 */
	public function testTheListMustHoldOneToAThousandCases(): void {
		$exporter = $this->exporter([]);

		try {
			$exporter->export(caseIds: ['', ' '], userId: 'pieter');
			$this->fail('exported nothing');
		} catch (RuntimeException $e) {
			$this->assertSame('No cases to export', $e->getMessage());
		}

		$this->expectExceptionMessage('At most 1000 cases can be exported at once');
		$exporter->export(caseIds: array_map(static fn (int $n): string => 'c-' . $n, range(1, 1001)), userId: 'pieter');
	}//end testTheListMustHoldOneToAThousandCases()
}//end class
