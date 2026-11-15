<?php

/**
 * The one derivation of a closed case's archival future.
 *
 * WHAT THESE TESTS ARE FOR. The rule they cover used to exist only on
 * `ZrcController`, so it ran when a case was closed over the ZGW API and not
 * when the same case was closed at the desk. Nothing failed: the desk-closed
 * case simply carried no nomination and no action date, and no reader could
 * tell that from a case an archivist had deliberately left blank. There is one
 * implementation now, and these tests pin its behaviour so a future edit to
 * either caller cannot quietly reintroduce a second one.
 *
 * The `afgehandeld` case is the one that was actually broken. The old switch
 * matched `handled`, an English value that nothing in this codebase produces;
 * every ZGW resultaattype carrying the standard Dutch `afgehandeld` fell
 * through to the default branch and derived no date at all.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Archival
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Archival;

use OCA\Dossiq\Service\Archival\ArchivalBaseDateResolver;
use OCA\Dossiq\Service\Archival\ArchivalNominationDeriver;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * In-memory ObjectService fake, keyed by schema slug.
 *
 * Mirrors only what the deriver reaches for: `find()` with named arguments and
 * `searchObjectsBySlug()`. Filter keys beginning with an underscore are
 * OpenRegister pagination controls, not object fields, so they never take part
 * in the match.
 */
class FakeObjectService {

	/**
	 * Stored objects, keyed by schema then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * Find one object by id.
	 *
	 * @param string $id The object id.
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null The row, or null when absent.
	 */
	public function find(string $id, string $register = '', string $schema = ''): ?array {
		return ($this->store[$schema][$id] ?? null);
	}//end find()

	/**
	 * Search objects by equality filters.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $filters Equality filters plus pagination keys.
	 *
	 * @return array<int, array<string, mixed>> The matching rows.
	 */
	public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
		return array_values(
			array_filter(
				array_values($this->store[$schema] ?? []),
				static function (array $row) use ($filters): bool {
					foreach ($filters as $key => $value) {
						if (str_starts_with($key, '_') === true) {
							continue;
						}

						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end searchObjectsBySlug()
}//end class

/**
 * One rule, two callers, no drift.
 *
 * @covers \OCA\Dossiq\Service\Archival\ArchivalNominationDeriver
 *
 * @uses \OCA\Dossiq\Service\Archival\ArchivalBaseDateResolver
 * @uses \OCA\Dossiq\Service\Archival\ReadsConfiguredRows
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class ArchivalNominationDeriverTest extends TestCase {

	/**
	 * The in-memory object store.
	 *
	 * @var FakeObjectService
	 */
	private FakeObjectService $objects;

	/**
	 * The class under test.
	 *
	 * @var ArchivalNominationDeriver
	 */
	private ArchivalNominationDeriver $deriver;

	/**
	 * Wire the deriver onto an in-memory register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeObjectService();

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'case_schema' => 'case',
					'result_schema' => 'result',
					'result_type_schema' => 'resultType',
					'case_property_schema' => 'caseProperty',
					'decision_schema' => 'decision',
					default => '',
				};
			}
		);

		$this->deriver = new ArchivalNominationDeriver(
			settingsService: $settings,
			baseDates: new ArchivalBaseDateResolver(settingsService: $settings),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Seed a resultType row.
	 *
	 * @param string $id The row id.
	 * @param array<string, mixed> $fields The row's fields.
	 *
	 * @return void
	 */
	private function seedResultType(string $id, array $fields): void {
		$this->objects->store['resultType'][$id] = (['id' => $id] + $fields);
	}//end seedResultType()

	/**
	 * The ordinary case: afgehandeld plus a five-year term.
	 *
	 * @return void
	 */
	public function testAfgehandeldAddsTheArchivalPeriodToTheEndDate(): void {
		$this->seedResultType(
			id: 'rt-1',
			fields: [
				'archivalAction' => 'vernietigen',
				'archivalPeriod' => 'P5Y',
				'sourceDateArchiveProcedure' => '{"afleidingswijze":"afgehandeld"}',
			]
		);

		$derived = $this->deriver->derive(
			case: ['id' => 'case-1'],
			resultTypeId: 'rt-1',
			endDate: '2026-09-08',
		);

		$this->assertSame('vernietigen', $derived['archiveNomination']);
		$this->assertSame('2031-09-08', $derived['archiveActionDate']);
	}//end testAfgehandeldAddsTheArchivalPeriodToTheEndDate()

	/**
	 * `bewaren` is not a value the case schema accepts, so it maps onto the
	 * one that means the same thing and is.
	 *
	 * @return void
	 */
	public function testBewarenIsMappedOntoTheCaseSchemaEnum(): void {
		$this->seedResultType(
			id: 'rt-2',
			fields: [
				'archivalAction' => 'bewaren',
				'archivalPeriod' => 'P20Y',
				'sourceDateArchiveProcedure' => '{"afleidingswijze":"termijn"}',
			]
		);

		$derived = $this->deriver->derive(
			case: ['id' => 'case-1'],
			resultTypeId: 'rt-2',
			endDate: '2026-01-01',
		);

		$this->assertSame('blijvend_bewaren', $derived['archiveNomination']);
		$this->assertSame('2046-01-01', $derived['archiveActionDate']);
	}//end testBewarenIsMappedOntoTheCaseSchemaEnum()

	/**
	 * A base date that comes from outside the case yields the nomination and
	 * an explicitly empty date, never a half-written pair.
	 *
	 * @return void
	 */
	public function testAnUnderivableBaseDateStillNominates(): void {
		$this->seedResultType(
			id: 'rt-3',
			fields: [
				'archivalAction' => 'blijvend_bewaren',
				'archivalPeriod' => 'P10Y',
				'sourceDateArchiveProcedure' => '{"afleidingswijze":"ander_datumkenmerk"}',
			]
		);

		$derived = $this->deriver->derive(
			case: ['id' => 'case-1'],
			resultTypeId: 'rt-3',
			endDate: '2026-09-08',
		);

		$this->assertSame('blijvend_bewaren', $derived['archiveNomination']);
		$this->assertArrayHasKey('archiveActionDate', $derived);
		$this->assertNull($derived['archiveActionDate']);
	}//end testAnUnderivableBaseDateStillNominates()

	/**
	 * `hoofdzaak` starts from the parent case's end date.
	 *
	 * @return void
	 */
	public function testHoofdzaakDerivesFromTheParentCasesEndDate(): void {
		$this->objects->store['case']['11111111-2222-3333-4444-555555555555'] = [
			'id' => '11111111-2222-3333-4444-555555555555',
			'endDate' => '2020-06-30',
		];
		$this->seedResultType(
			id: 'rt-4',
			fields: [
				'archivalAction' => 'vernietigen',
				'archivalPeriod' => 'P1Y',
				'sourceDateArchiveProcedure' => ['afleidingswijze' => 'hoofdzaak'],
			]
		);

		$derived = $this->deriver->derive(
			case: [
				'id' => 'case-child',
				'parentCase' => '11111111-2222-3333-4444-555555555555',
			],
			resultTypeId: 'rt-4',
			endDate: '2026-09-08',
		);

		$this->assertSame('2021-06-30', $derived['archiveActionDate']);
	}//end testHoofdzaakDerivesFromTheParentCasesEndDate()

	/**
	 * The case carries its result type's selectielijst class, so OpenRegister
	 * reads the category through the case schema's `categoryProperty`
	 * (decision 127). It is copied even when no base date can be derived.
	 *
	 * @return void
	 */
	public function testTheResultTypesSelectionListClassIsCopiedOntoTheCase(): void {
		$this->seedResultType(
			id: 'rt-5',
			fields: [
				'archivalAction' => 'vernietigen',
				'archivalPeriod' => 'P5Y',
				'sourceDateArchiveProcedure' => '{"afleidingswijze":"ander_datumkenmerk"}',
				'selectionListClass' => 'https://selectielijst.openzaak.nl/api/v1/resultaten/8af64c99',
			]
		);

		$derived = $this->deriver->derive(case: ['id' => 'case-1'], resultTypeId: 'rt-5', endDate: '2026-09-08');

		$this->assertSame(
			'https://selectielijst.openzaak.nl/api/v1/resultaten/8af64c99',
			$derived['selectionListClass']
		);
	}//end testTheResultTypesSelectionListClassIsCopiedOntoTheCase()

	/**
	 * A result type without a class writes no key, so a class set on the case
	 * by hand survives the merge.
	 *
	 * @return void
	 */
	public function testAResultTypeWithoutAClassLeavesTheCasesClassAlone(): void {
		$this->seedResultType(
			id: 'rt-6',
			fields: ['archivalAction' => 'vernietigen', 'archivalPeriod' => 'P5Y', 'selectionListClass' => '  ']
		);

		$derived = $this->deriver->derive(case: ['id' => 'case-1'], resultTypeId: 'rt-6', endDate: '2026-09-08');

		$this->assertArrayNotHasKey('selectionListClass', $derived);
	}//end testAResultTypeWithoutAClassLeavesTheCasesClassAlone()

	/**
	 * A resultType nobody can read closes the case with no archival claim
	 * rather than refusing the close.
	 *
	 * @return void
	 */
	public function testAnUnreadableResultTypeDerivesNothing(): void {
		$this->assertSame(
			[],
			$this->deriver->derive(case: ['id' => 'case-1'], resultTypeId: 'rt-absent', endDate: '2026-09-08')
		);
	}//end testAnUnreadableResultTypeDerivesNothing()

	/**
	 * The ZGW path's extra hop: the resultType a case's written result points at.
	 *
	 * @return void
	 */
	public function testResultTypeForCaseFollowsTheWrittenResult(): void {
		$this->objects->store['result']['res-1'] = [
			'id' => 'res-1',
			'case' => 'case-1',
			'resultType' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
		];

		$this->assertSame(
			'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			$this->deriver->resultTypeForCase(caseId: 'case-1')
		);
		$this->assertNull($this->deriver->resultTypeForCase(caseId: 'case-without-a-result'));
	}//end testResultTypeForCaseFollowsTheWrittenResult()

	/**
	 * A deriver over the shared fake store with its own logger double.
	 *
	 * @param LoggerInterface $logger The logger double.
	 * @param string          $resultSchema The configured result schema ('' leaves it unconfigured).
	 *
	 * @return ArchivalNominationDeriver The deriver.
	 */
	private function deriverWith(LoggerInterface $logger, string $resultSchema = 'result'): ArchivalNominationDeriver {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'result_schema' => $resultSchema,
				'result_type_schema' => 'resultType',
				default => '',
			}
		);

		return new ArchivalNominationDeriver(
			settingsService: $settings,
			baseDates: new ArchivalBaseDateResolver(settingsService: $settings),
			logger: $logger,
		);
	}//end deriverWith()

	/**
	 * Without a result type or an end date nothing is read and nothing is derived.
	 *
	 * @return void
	 */
	public function testAMissingResultTypeOrEndDateDerivesNothing(): void {
		$this->seedResultType(id: 'rt-1', fields: ['archivalAction' => 'vernietigen', 'archivalPeriod' => 'P5Y']);

		$this->assertSame([], $this->deriver->derive(case: ['id' => 'case-1'], resultTypeId: '', endDate: '2026-09-08'));
		$this->assertSame([], $this->deriver->derive(case: ['id' => 'case-1'], resultTypeId: 'rt-1', endDate: ''));
	}//end testAMissingResultTypeOrEndDateDerivesNothing()

	/**
	 * A result type without an archival period dates the action on the base date itself.
	 *
	 * @return void
	 */
	public function testNoArchivalPeriodDatesTheActionOnTheBaseDate(): void {
		$this->seedResultType(
			id: 'rt-4',
			fields: ['archivalAction' => 'vernietigen', 'sourceDateArchiveProcedure' => ['derivationMethod' => 'afgehandeld']]
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$derived = $this->deriverWith(logger: $logger)->derive(case: ['id' => 'case-1'], resultTypeId: 'rt-4', endDate: '2026-09-08');

		$this->assertSame(['archiveNomination' => 'vernietigen', 'archiveActionDate' => '2026-09-08'], $derived);
	}//end testNoArchivalPeriodDatesTheActionOnTheBaseDate()

	/**
	 * An unparseable archival period falls back to the base date and warns.
	 *
	 * @return void
	 */
	public function testAnUnparseableArchivalPeriodFallsBackToTheBaseDateAndWarns(): void {
		$this->seedResultType(
			id: 'rt-5',
			fields: ['archiefnominatie' => 'bewaren', 'archiefactietermijn' => 'five years', 'brondatumArchiefprocedure' => '{"afleidingswijze":"afgehandeld"}']
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'zrc-021: unparseable archivalPeriod, archiveActionDate falls back to the base date',
			['archivalPeriod' => 'five years']
		);

		$derived = $this->deriverWith(logger: $logger)->derive(case: ['id' => 'case-1'], resultTypeId: 'rt-5', endDate: '2026-09-08');

		$this->assertSame(['archiveNomination' => 'blijvend_bewaren', 'archiveActionDate' => '2026-09-08'], $derived);
	}//end testAnUnparseableArchivalPeriodFallsBackToTheBaseDateAndWarns()

	/**
	 * An unknown nomination value writes no nomination key.
	 *
	 * @return void
	 */
	public function testAnUnknownNominationWritesNoNomination(): void {
		$this->seedResultType(
			id: 'rt-6',
			fields: ['archivalAction' => 'misschien', 'archivalPeriod' => 'P1Y', 'sourceDateArchiveProcedure' => '{"afleidingswijze":"afgehandeld"}']
		);

		$derived = $this->deriver->derive(case: ['id' => 'case-1'], resultTypeId: 'rt-6', endDate: '2026-09-08');

		$this->assertSame(['archiveActionDate' => '2027-09-08'], $derived);
	}//end testAnUnknownNominationWritesNoNomination()

	/**
	 * An empty case id, or an unconfigured result schema, finds no result type.
	 *
	 * @return void
	 */
	public function testResultTypeForCaseNeedsACaseIdAndAConfiguredSchema(): void {
		$this->objects->store['result']['res-1'] = [
			'id' => 'res-1',
			'case' => 'case-1',
			'resultType' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
		];
		// A result row with a blank case must not be found for a blank case id.
		$this->objects->store['result']['res-blank'] = [
			'id' => 'res-blank',
			'case' => '',
			'resultType' => 'ffffffff-bbbb-cccc-dddd-eeeeeeeeeeee',
		];

		$this->assertNull($this->deriver->resultTypeForCase(caseId: ''));
		$this->assertNull(
			$this->deriverWith(logger: $this->createMock(LoggerInterface::class), resultSchema: '')->resultTypeForCase(caseId: 'case-1')
		);
	}//end testResultTypeForCaseNeedsACaseIdAndAConfiguredSchema()
}//end class
