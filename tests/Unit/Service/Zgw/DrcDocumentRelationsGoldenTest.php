<?php

/**
 * Golden test for the uniqueness check of DrcDocumentRelations.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zgw\DrcDocumentRelations;
use OCA\Dossiq\Service\Zgw\ZgwRuleLookups;
use OCA\Dossiq\Service\Zgw\ZgwRuleResults;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The search half of the ObjectService, as the uniqueness check calls it.
 */
interface DrcGoldenSearchStub {
	/**
	 * Build a search query.
	 *
	 * @param array<string, mixed> $requestParams The search parameters.
	 * @param string               $register      The register.
	 * @param string               $schema        The schema.
	 *
	 * @return array<string, mixed>
	 */
	public function buildSearchQuery(array $requestParams, string $register, string $schema): array;

	/**
	 * Run a search query.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array<string, mixed>
	 */
	public function searchObjectsPaginated(array $query): array;
}//end interface

/**
 * Every search the uniqueness check sends and every answer it gives, per case.
 *
 * @covers \OCA\Dossiq\Service\Zgw\DrcDocumentRelations
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleLookups
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 * @uses \OCA\Dossiq\Service\FieldValidator
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class DrcDocumentRelationsGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	private const IO = 'https://drc.example.com/enkelvoudiginformatieobjecten/71000000-1111-4222-8333-444455556666';

	private const ZAAK = 'https://zrc.example.com/zaken/73000000-1111-4222-8333-444455556666';

	private const BESLUIT = 'https://brc.example.com/besluiten/72000000-1111-4222-8333-444455556666';

	/**
	 * What happened in one case: the calls, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * The worlds: URLs, object type, the schemas the settings answer and which search hits.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, string>, 4: int, 5: bool}>
	 */
	public static function worlds(): array {
		$uuids = ['document_link_schema' => '11', 'case_document_schema' => '12', 'decision_document_schema' => '13'];

		return [
			'case, nothing stored' => [self::IO, self::ZAAK, 'case', $uuids, 0, false],
			'decision, nothing stored' => [self::IO, self::BESLUIT, 'decision', $uuids, 0, false],
			'unknown type, nothing stored' => [self::IO, self::ZAAK, 'verzoek', $uuids, 0, false],
			'case, found by url' => [self::IO, self::ZAAK, 'case', $uuids, 1, false],
			'case, found by uuid' => [self::IO, self::ZAAK, 'case', $uuids, 2, false],
			'case, found by text' => [self::IO, self::ZAAK, 'case', $uuids, 3, false],
			'case, found in the case schema' => [self::IO, self::ZAAK, 'case', $uuids, 4, false],
			'decision, found by uuid in its schema' => [self::IO, self::BESLUIT, 'decision', $uuids, 5, false],
			'no uuid in the urls' => ['https://drc.example.com/a', 'https://x.example.com/b', 'case', $uuids, 0, false],
			'object url without uuid' => [self::IO, 'https://x.example.com/b', 'case', $uuids, 0, false],
			'document url without uuid' => ['https://drc.example.com/a', self::ZAAK, 'decision', $uuids, 0, false],
			'no link schema' => [self::IO, self::ZAAK, 'case', ['document_link_schema' => '', 'case_document_schema' => '12'], 0, false],
			'no case schema' => [self::IO, self::ZAAK, 'case', ['document_link_schema' => '11', 'case_document_schema' => ''], 0, false],
			'no schemas at all' => [self::IO, self::ZAAK, 'decision', ['document_link_schema' => '', 'decision_document_schema' => ''], 0, false],
			'search throws' => [self::IO, self::ZAAK, 'case', $uuids, 0, true],
			'search throws after a miss' => [self::IO, self::ZAAK, 'case', $uuids, 2, true],
		];
	}//end worlds()

	/**
	 * The calls and the answer match the recording.
	 *
	 * @param string                $ioUrl     The document URL.
	 * @param string                $objectUrl The object URL.
	 * @param string                $type      The object type.
	 * @param array<string, string> $schemas   Schema per settings key.
	 * @param int                   $hitAt     The search (1-based) that finds a relation, 0 for none.
	 * @param bool                  $throws    Whether the search at $hitAt (or the first) throws instead.
	 *
	 * @dataProvider worlds
	 *
	 * @return void
	 */
	public function testTheUniquenessCheckMatchesTheRecording(
		string $ioUrl,
		string $objectUrl,
		string $type,
		array $schemas,
		int $hitAt,
		bool $throws,
	): void {
		$answer = $this->relations(schemas: $schemas, hitAt: $hitAt, throws: $throws)
			->checkOioUniqueness(ioUrl: $ioUrl, objectUrl: $objectUrl, objectType: $type);

		$name = strtolower((string)preg_replace('/[^a-z]+/i', '-', $this->dataName()));
		$this->assertMatchesGolden(name: 'drc-document-relations/' . trim($name, '-'), value: ['calls' => $this->log, 'answer' => $answer]);
	}//end testTheUniquenessCheckMatchesTheRecording()

	/**
	 * The relations over a recording search.
	 *
	 * @param array<string, string> $schemas Schema per settings key; a missing key answers ''.
	 * @param int                   $hitAt   The search (1-based) that finds a relation, 0 for none.
	 * @param bool                  $throws  Whether that search throws instead.
	 *
	 * @return DrcDocumentRelations
	 */
	private function relations(array $schemas, int $hitAt, bool $throws): DrcDocumentRelations {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key) use ($schemas): string {
				$this->log[] = ['getConfigValue' => $key];
				return ($schemas[$key] ?? '');
			}
		);

		$search = $this->createMock(originalClassName: DrcGoldenSearchStub::class);
		$search->method('buildSearchQuery')->willReturnCallback(
			function (array $requestParams, string $register, string $schema): array {
				$this->log[] = ['buildSearchQuery' => ['requestParams' => $requestParams, 'register' => $register, 'schema' => $schema]];
				return ['q' => $requestParams, 'r' => $register, 's' => $schema];
			}
		);
		$count = 0;
		$search->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use (&$count, $hitAt, $throws): array {
				$count++;
				$this->log[] = ['searchObjectsPaginated' => $query];
				if ($throws === true && ($count === $hitAt || ($hitAt === 0 && $count === 1))) {
					throw new \RuntimeException('store down at ' . $count);
				}

				if ($count === $hitAt) {
					return ['results' => [['id' => 'hit']]];
				}

				return ['total' => 0];
			}
		);

		$lookups = new ZgwRuleLookups(settingsService: $settings);
		$lookups->setContext(objectService: $search, mappingConfig: ['sourceRegister' => '5']);

		return new DrcDocumentRelations(
			lookups: $lookups,
			results: new ZgwRuleResults(logger: $this->createMock(originalClassName: LoggerInterface::class)),
			settingsService: $settings,
			fieldValidator: new FieldValidator()
		);
	}//end relations()
}//end class
