<?php

/**
 * A scored record gets the total its case's scoring formula gives, in the same save
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Kernel\Contracts\CaseObjectReaderInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\FormulaTotalOnSave;
use OCA\Dossiq\Service\Scoring\FormulaScorer;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\FormulaTotalOnSave
 * @uses \OCA\Dossiq\Service\Scoring\FormulaScorer
 * @uses \OCA\Dossiq\Listener\Support\SchemaMembership
 * @uses \OCA\Dossiq\Service\Support\ReferenceId
 * @uses \OCA\Dossiq\Service\Support\ObjectSearch
 */
class FormulaTotalOnSaveTest extends TestCase {

	/**
	 * The tender case the bids are scored on.
	 */
	private const TENDER = 'b84067ac-35ec-55b5-bf17-975206ce32f2';

	/**
	 * The price-quality formula as the package seeds it.
	 */
	private const PRICE_QUALITY = [
		'key' => 'price-quality',
		'expression' => 'round(qualityWeight * quality + priceWeight * maxPoints * lowestPrice / price, 2)',
	];

	/**
	 * Reads the case a scored record names.
	 *
	 * @var CaseObjectReaderInterface&MockObject
	 */
	private CaseObjectReaderInterface&MockObject $cases;

	/**
	 * The logger, to see why a total was cleared.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The stored records per schema slug the object service answers with.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $stored = [];

	/**
	 * The searches the object service was asked, as [schema, filters].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $searches = [];

	/**
	 * Set up the case reader, the logger and three bids on the tender.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->cases = $this->createMock(CaseObjectReaderInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->stored = [
			'bid-schema' => [
				['id' => 'bid-a', 'tender' => self::TENDER, 'priceAmount' => 612000, 'state' => 'admissible'],
				['id' => 'bid-b', 'tender' => self::TENDER, 'priceAmount' => 584000, 'state' => 'admissible'],
				['id' => 'bid-c', 'tender' => self::TENDER, 'priceAmount' => 400000, 'state' => 'excluded'],
			],
			'scoring-formula-schema' => [self::PRICE_QUALITY],
		];
	}//end setUp()

	/**
	 * The listener, with the evaluation, bid and formula schemas configured by slug.
	 *
	 * @return FormulaTotalOnSave
	 */
	private function listener(): FormulaTotalOnSave {
		$config = [
			'register' => 'dossiq',
			'evaluation_schema' => 'evaluation-schema',
			'bid_schema' => 'bid-schema',
			'scoring_formula_schema' => 'scoring-formula-schema',
		];
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($config[$key] ?? '')
		);
		$settings->method('getObjectService')->willReturn($this->objectService());

		return new FormulaTotalOnSave(
			settingsService: $settings,
			cases: $this->cases,
			scorer: new FormulaScorer(),
			logger: $this->logger,
		);
	}//end listener()

	/**
	 * An object service that answers slug searches from the stored records, filtered by field.
	 *
	 * @return object
	 */
	private function objectService(): object {
		$test = $this;

		return new class($test) {
			/**
			 * Constructor.
			 *
			 * @param FormulaTotalOnSaveTest $test The test holding the records.
			 */
			public function __construct(private readonly FormulaTotalOnSaveTest $test) {
			}

			/**
			 * The stored records of a schema that match the field filters.
			 *
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  Field filters and `_limit`.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return $this->test->answer(schema: $schema, filters: $filters);
			}
		};
	}//end objectService()

	/**
	 * Answer a search from the stored records; records the search.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Field filters and `_limit`.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function answer(string $schema, array $filters): array {
		$this->searches[] = [$schema, $filters];
		$rows = [];
		foreach (($this->stored[$schema] ?? []) as $row) {
			foreach ($filters as $field => $value) {
				if (str_starts_with($field, '_') === false && ($row[$field] ?? null) !== $value) {
					continue 2;
				}
			}

			$rows[] = $row;
		}

		return $rows;
	}//end answer()

	/**
	 * The tender case, as the reader returns it.
	 *
	 * @param array<string, mixed>|null $case The case.
	 *
	 * @return void
	 */
	private function tenderIs(?array $case): void {
		$this->cases->method('fetchCaseById')->with(self::TENDER)->willReturn($case);
	}//end tenderIs()

	/**
	 * An evaluation of a bid on the tender.
	 *
	 * @param array<string, mixed> $fields   Its fields.
	 * @param string               $schemaId The schema it is saved in.
	 *
	 * @return ObjectEntity
	 */
	private function evaluation(array $fields, string $schemaId = 'evaluation-schema'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject(array_merge(['bid' => 'bid-a', 'tender' => self::TENDER, 'evaluator' => 'panel', 'state' => 'submitted'], $fields));
		$entity->setSchema($schemaId);

		return $entity;
	}//end evaluation()

	/**
	 * Create the record and return what will be stored.
	 *
	 * @param ObjectEntity $record The record.
	 *
	 * @return array<string, mixed>
	 */
	private function create(ObjectEntity $record): array {
		$this->listener()->handle(new ObjectCreatingEvent($record));

		return $record->getObject();
	}//end create()

	/**
	 * With the price-quality formula, the total weighs quality and the price against the lowest price in the running.
	 *
	 * @return void
	 */
	public function testThePriceQualityFormulaTotalsAgainstTheLowestPriceInTheRunning(): void {
		$this->tenderIs(
			[
				'id' => self::TENDER,
				'properties' => [
					['name' => 'scoringFormula', 'value' => 'price-quality'],
					['name' => 'qualityWeight', 'value' => '0.6'],
					['name' => 'priceWeight', 'value' => 0.4],
					['name' => 'maxPoints', 'value' => 100],
				],
			]
		);

		$stored = $this->create($this->evaluation(['criterionScores' => ['quality' => 80]]));

		// 0.6 * 80 + 0.4 * 100 * 584000 / 612000; the excluded 400000 bid does not set the lowest price.
		$this->assertSame(86.17, $stored['weightedTotal']);
		$this->assertSame(['quality' => 80], $stored['criterionScores'], 'the scores are kept as given');
	}//end testThePriceQualityFormulaTotalsAgainstTheLowestPriceInTheRunning()

	/**
	 * The formula is looked up by the key the case names, among the stored formulas.
	 *
	 * @return void
	 */
	public function testTheFormulaIsLookedUpByTheKeyTheCaseNames(): void {
		$this->tenderIs(['id' => self::TENDER, 'scoringFormula' => 'price-quality', 'qualityWeight' => 1, 'priceWeight' => 0, 'maxPoints' => 100]);

		$stored = $this->create($this->evaluation(['criterionScores' => ['quality' => 70]]));

		$this->assertSame(70.0, $stored['weightedTotal']);
		$this->assertContains(['scoring-formula-schema', ['key' => 'price-quality', '_limit' => 1]], $this->searches);
		$this->assertContains(['bid-schema', ['tender' => self::TENDER, '_limit' => 500]], $this->searches);
	}//end testTheFormulaIsLookedUpByTheKeyTheCaseNames()

	/**
	 * A case that names no formula totals the scores as given.
	 *
	 * @return void
	 */
	public function testWithoutAFormulaTheTotalIsTheSumOfTheScores(): void {
		$this->tenderIs(['id' => self::TENDER]);

		$stored = $this->create($this->evaluation(['criterionScores' => ['kwaliteit' => 62.0, 'prijs' => '38.2', 'note' => 'n/a'], 'weightedTotal' => 1.0]));

		$this->assertSame(100.2, $stored['weightedTotal']);
		$this->assertSame([], $this->searches, 'no formula, so nothing is looked up');
	}//end testWithoutAFormulaTheTotalIsTheSumOfTheScores()

	/**
	 * A formula that names a value the record and its case do not have clears the total.
	 *
	 * @return void
	 */
	public function testAFormulaMissingAValueClearsTheTotal(): void {
		$this->tenderIs(['id' => self::TENDER, 'scoringFormula' => 'price-quality', 'maxPoints' => 100]);
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('could not total'),
			$this->callback(static fn (array $context): bool => str_contains((string)$context['reason'], 'scoring_formula_missing_value'))
		);

		$stored = $this->create($this->evaluation(['criterionScores' => ['quality' => 80], 'weightedTotal' => 99.0]));

		$this->assertArrayHasKey('weightedTotal', $stored);
		$this->assertNull($stored['weightedTotal'], 'a stale total is not kept');
	}//end testAFormulaMissingAValueClearsTheTotal()

	/**
	 * A formula key no stored formula has clears the total.
	 *
	 * @return void
	 */
	public function testAnUnknownFormulaClearsTheTotal(): void {
		$this->tenderIs(['id' => self::TENDER, 'scoringFormula' => 'no-such-formula']);
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('does not exist'));

		$stored = $this->create($this->evaluation(['criterionScores' => ['quality' => 80], 'weightedTotal' => 99.0]));

		$this->assertNull($stored['weightedTotal']);
	}//end testAnUnknownFormulaClearsTheTotal()

	/**
	 * An update is totalled too, from the scores it carries.
	 *
	 * @return void
	 */
	public function testAnUpdateIsTotalledFromTheNewScores(): void {
		$this->tenderIs(['id' => self::TENDER]);
		$updated = $this->evaluation(['criterionScores' => ['kwaliteit' => 50, 'prijs' => 40], 'weightedTotal' => 100.2]);

		$this->listener()->handle(new ObjectUpdatingEvent($updated, $this->evaluation(['criterionScores' => ['kwaliteit' => 62.0, 'prijs' => 38.2]])));

		$this->assertSame(90.0, $updated->getObject()['weightedTotal']);
	}//end testAnUpdateIsTotalledFromTheNewScores()

	/**
	 * A record of any other schema is left as it is.
	 *
	 * @return void
	 */
	public function testARecordOfAnotherSchemaIsLeftAlone(): void {
		$this->cases->expects($this->never())->method('fetchCaseById');

		$stored = $this->create($this->evaluation(['criterionScores' => ['quality' => 80]], 'bid-schema'));

		$this->assertArrayNotHasKey('weightedTotal', $stored);
	}//end testARecordOfAnotherSchemaIsLeftAlone()

	/**
	 * Any other event is ignored.
	 *
	 * @return void
	 */
	public function testAnyOtherEventIsIgnored(): void {
		$this->cases->expects($this->never())->method('fetchCaseById');

		$this->listener()->handle(new Event());

		$this->assertSame([], $this->searches);
	}//end testAnyOtherEventIsIgnored()

	/**
	 * The listener's table names fields the evaluation and bid schemas have, and a formula schema that is configured.
	 *
	 * @return void
	 */
	public function testTheTableNamesFieldsTheSchemasHave(): void {
		$path = dirname(__DIR__, 3) . '/lib/Settings/register.d/88-tender-records.json';
		$schemas = json_decode((string)file_get_contents($path), true)['components']['schemas'];
		$fields = FormulaTotalOnSave::SCORED['evaluation_schema'];

		foreach (['subject', 'case', 'scores', 'total'] as $key) {
			$this->assertArrayHasKey($fields[$key], $schemas['evaluation']['properties'], $key);
		}

		$this->assertArrayHasKey($fields['price'], $schemas['bid']['properties']);
		$this->assertArrayHasKey($fields['case'], $schemas['bid']['properties'], 'bids are found by the same case field');
		foreach ($fields['outOfRunning'] as $state) {
			$this->assertContains($state, $schemas['bid']['properties']['state']['enum']);
		}
	}//end testTheTableNamesFieldsTheSchemasHave()
}//end class
