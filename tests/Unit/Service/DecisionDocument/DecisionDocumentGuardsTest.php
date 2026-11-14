<?php

/**
 * Unit tests for the configuration guards of the decision letter and the
 * concluded decision record lookup.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\DecisionDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\DecisionDocument;

use OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService;
use OCA\Dossiq\Service\DecisionDocument\ConcludedDecisionRecord;
use OCA\Dossiq\Service\DecisionDocument\DecisionLetter;
use OCA\Dossiq\Service\Drafts\CaseDocumentDrafts;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Without its schemas the decision letter reads and links nothing, and a
 * decision whose record cannot be found falls back to the event's references.
 *
 * @covers \OCA\Dossiq\Service\DecisionDocument\DecisionLetter
 * @covers \OCA\Dossiq\Service\DecisionDocument\ConcludedDecisionRecord
 * @uses   \OCA\Dossiq\Service\Zaakdossier\DocumentTypeResolver
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses   \OCA\Dossiq\Service\Support\TranslatedText
 */
class DecisionDocumentGuardsTest extends TestCase {

	/**
	 * The full configuration.
	 *
	 * @var array<string, string>
	 */
	private const CONFIG = [
		'register' => 'dossiq',
		'dossier_informatieobject_schema' => 'informatieobject',
		'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
		'dossier_besluitinformatieobject_schema' => 'besluitinformatieobject',
		'dossier_informatieobjecttype_schema' => 'informatieobjecttype',
	];

	/**
	 * The register.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * A register with one Besluit document on case-a, joined twice, once
	 * without a document.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$this->register->seed('informatieobject', 'io-1', ['title' => 'Besluit', 'informatieobjecttype' => 'Besluit', 'creatiedatum' => '2026-10-01']);
		$this->register->seed('zaakinformatieobject', 'j-1', ['case' => 'case-a', 'informatieobject' => 'io-1']);
		$this->register->seed('zaakinformatieobject', 'j-2', ['case' => 'case-a', 'informatieobject' => '']);
	}//end setUp()

	/**
	 * A join without a document is skipped; the joined document is found.
	 *
	 * @return void
	 */
	public function testAJoinWithoutADocumentIsSkipped(): void {
		$letter = $this->letter(config: self::CONFIG)->forCase('case-a', 'decision');

		$this->assertSame('io-1', $letter['document']['id']);
		$this->assertFalse($letter['empty']);
	}//end testAJoinWithoutADocumentIsSkipped()

	/**
	 * Missing schema keys.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function readSchemas(): array {
		return [
			'no document schema' => ['dossier_informatieobject_schema'],
			'no case-document join schema' => ['dossier_zaakinformatieobject_schema'],
			'no register' => ['register'],
		];
	}//end readSchemas()

	/**
	 * Without the schemas to read a case's documents, the letter is declared
	 * but empty.
	 *
	 * @param string $missing The configuration key left out.
	 *
	 * @dataProvider readSchemas
	 *
	 * @return void
	 */
	public function testWithoutItsSchemasTheLetterIsEmpty(string $missing): void {
		$config = self::CONFIG;
		unset($config[$missing]);

		$letter = $this->letter(config: $config)->forCase('case-a', 'decision');

		$this->assertSame('Besluit', $letter['declaration']['documentType']);
		$this->assertNull($letter['document']);
		$this->assertTrue($letter['empty']);
	}//end testWithoutItsSchemasTheLetterIsEmpty()

	/**
	 * Without the decision-document schema, a link writes nothing.
	 *
	 * @return void
	 */
	public function testWithoutTheLinkSchemaNothingIsLinked(): void {
		$config = self::CONFIG;
		unset($config['dossier_besluitinformatieobject_schema']);

		$this->letter(config: $config)->link('besluit-1', 'io-1');
		$this->letter(config: self::CONFIG, objectService: false)->link('besluit-1', 'io-1');

		$this->assertSame([], $this->register->all('besluitinformatieobject'));
	}//end testWithoutTheLinkSchemaNothingIsLinked()

	/**
	 * A found record names the case and the existing besluit.
	 *
	 * @return void
	 */
	public function testAFoundRecordNamesTheCaseAndBesluit(): void {
		$objects = $this->searching(rows: [['decisionRef' => 'd-1', 'caseRef' => 'case-r', 'besluitRef' => 'b-1']]);

		$this->assertSame(
			['case-r', 'b-1', ['decisionRef' => 'd-1', 'caseRef' => 'case-r', 'besluitRef' => 'b-1']],
			$this->record()->resolveCaseAndDecision($objects, 'dossiq', 'decisionRecord', 'd-1', 'subject-1', 'ext-1')
		);
	}//end testAFoundRecordNamesTheCaseAndBesluit()

	/**
	 * A failed lookup is logged and falls back to the external reference,
	 * then the subject.
	 *
	 * @return void
	 */
	public function testAFailedLookupFallsBackToTheReferences(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')
			->with('Dossiq DecisionConcludedListener: decisionRef lookup failed: store down');
		$objects = $this->searching(rows: null);
		$record = $this->record(logger: $logger);

		$this->assertSame(['ext-1', '', null], $record->resolveCaseAndDecision($objects, 'dossiq', 'decisionRecord', 'd-1', 'subject-1', 'ext-1'));
		$this->assertSame(['subject-1', '', null], $record->resolveCaseAndDecision($objects, 'dossiq', 'decisionRecord', 'd-1', 'subject-1', ''));
	}//end testAFailedLookupFallsBackToTheReferences()

	/**
	 * Without a schema or a decision id nothing is looked up.
	 *
	 * @return void
	 */
	public function testNothingIsLookedUpWithoutASchemaOrDecision(): void {
		$objects = $this->searching(rows: [['caseRef' => 'case-r']]);
		$record = $this->record();

		$this->assertSame(['ext-1', '', null], $record->resolveCaseAndDecision($objects, 'dossiq', '', 'd-1', 'subject-1', 'ext-1'));
		$this->assertSame(['ext-1', '', null], $record->resolveCaseAndDecision($objects, 'dossiq', 'decisionRecord', '', 'subject-1', 'ext-1'));
		$this->assertSame(0, $objects->searches);
	}//end testNothingIsLookedUpWithoutASchemaOrDecision()

	/**
	 * An object service whose slug search answers the rows, or fails.
	 *
	 * @param array<int, array<string, mixed>>|null $rows The rows, or null to fail.
	 *
	 * @return object
	 */
	private function searching(?array $rows): object {
		return new class ($rows) {
			/**
			 * The number of searches.
			 *
			 * @var integer
			 */
			public int $searches = 0;

			/**
			 * Constructor.
			 *
			 * @param array<int, array<string, mixed>>|null $rows The rows.
			 */
			public function __construct(private readonly ?array $rows) {
			}

			/**
			 * Search by slug.
			 *
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 * @param array  $filters  The filters.
			 *
			 * @return array
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->searches++;
				if ($this->rows === null) {
					throw new RuntimeException('store down');
				}

				return $this->rows;
			}
		};
	}//end searching()

	/**
	 * The record resolver.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return ConcludedDecisionRecord
	 */
	private function record(?LoggerInterface $logger = null): ConcludedDecisionRecord {
		return new ConcludedDecisionRecord(
			bacService: $this->createMock(AdvisoryCommitteeService::class),
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);
	}//end record()

	/**
	 * The decision letter over the register and configuration.
	 *
	 * @param array<string, string> $config        The configuration.
	 * @param boolean               $objectService Whether OpenRegister is available.
	 *
	 * @return DecisionLetter
	 */
	private function letter(array $config, bool $objectService = true): DecisionLetter {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService === true ? $this->register : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$drafts = $this->createMock(CaseDocumentDrafts::class);
		$drafts->method('declarationForCase')->willReturn(['documentType' => 'Besluit', 'title' => 'Besluit']);

		return new DecisionLetter(settingsService: $settings, drafts: $drafts);
	}//end letter()
}//end class
