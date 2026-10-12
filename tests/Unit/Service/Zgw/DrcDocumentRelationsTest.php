<?php

/**
 * Unit tests for DrcDocumentRelations.
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The search half of the ObjectService, as the relations call it.
 */
interface DrcRelationSearchStub {
	public function buildSearchQuery(array $requestParams, string $register, string $schema): array;

	public function searchObjectsPaginated(array $query): array;
}//end interface

/**
 * The stored relations of a document: which exist, and whether a pair is linked already.
 *
 * @covers \OCA\Dossiq\Service\Zgw\DrcDocumentRelations
 * @uses \OCA\Dossiq\Service\ZgwRulesBase
 * @uses \OCA\Dossiq\Service\FieldValidator
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class DrcDocumentRelationsTest extends TestCase {
	private const DOCUMENT = '71000000-1111-4222-8333-444455556666';

	private const BESLUIT = '72000000-1111-4222-8333-444455556666';

	private const DOCUMENT_URL = 'https://drc.example.com/enkelvoudiginformatieobjecten/' . self::DOCUMENT;

	private const BESLUIT_URL = 'https://brc.example.com/besluiten/' . self::BESLUIT;

	private const DUPLICATE = 'De combinatie informatieobject + object + objectType bestaat al.';

	/**
	 * Every query the relations sent, each with its register and schema.
	 *
	 * @var array<int, array>
	 */
	private array $queries = [];

	/**
	 * The warnings the relations logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * Build the relations over a search that answers from its query.
	 *
	 * @param \Closure $answer Maps a query to a search result.
	 * @param array $mappingConfig The mapping configuration.
	 * @param array<string, string> $schemas Schema per settings key; a missing key answers its own name.
	 *
	 * @return DrcDocumentRelations
	 */
	private function relations(\Closure $answer, array $mappingConfig = ['sourceRegister' => '5'], array $schemas = []): DrcDocumentRelations {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($schemas[$key] ?? $key));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		$search = $this->createMock(DrcRelationSearchStub::class);
		$search->method('buildSearchQuery')->willReturnCallback(
			static fn (array $requestParams, string $register, string $schema): array => ($requestParams + ['@register' => $register, '@schema' => $schema])
		);
		$search->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use ($answer): array {
				$this->queries[] = $query;
				return $answer($query);
			}
		);

		$relations = new DrcDocumentRelations(logger: $logger, settingsService: $settings, fieldValidator: new FieldValidator());
		$relations->setContext(objectService: $search, mappingConfig: $mappingConfig);

		return $relations;
	}//end relations()

	/**
	 * A relation that stores the document's uuid is found by the first search.
	 *
	 * @return void
	 */
	public function testRelationsStoredByUuidAreFoundAtOnce(): void {
		$ids = $this->relations(answer: static fn (array $query): array => ['results' => [['id' => 'a'], ['@self' => ['id' => 'b']]]])
			->findOioRelationsForDocument(register: '5', schema: '9', docUuid: self::DOCUMENT);

		$this->assertSame(['a', 'b'], $ids);
		$this->assertSame([['document' => self::DOCUMENT, '_limit' => 100, '@register' => '5', '@schema' => '9']], $this->queries);
	}//end testRelationsStoredByUuidAreFoundAtOnce()

	/**
	 * A relation that stores the document's URL is found by the partial match, entity or array, id or not.
	 *
	 * @return void
	 */
	public function testRelationsStoredByUrlAreFoundByPartialMatch(): void {
		$entity = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['@self' => ['id' => 'entity']];
			}//end jsonSerialize()
		};
		$answer = static function (array $query) use ($entity): array {
			if ($query['document'] === self::DOCUMENT) {
				return [];
			}

			return ['results' => [$entity, ['id' => 'row'], ['title' => 'no id']]];
		};

		$ids = $this->relations(answer: $answer)->findOioRelationsForDocument(register: '5', schema: '9', docUuid: self::DOCUMENT);

		$this->assertSame(['entity', 'row'], $ids);
		$this->assertSame('%' . self::DOCUMENT . '%', $this->queries[1]['document']);
		$this->assertSame(100, $this->queries[1]['_limit']);
	}//end testRelationsStoredByUrlAreFoundByPartialMatch()

	/**
	 * A search that fails answers no relations and says so in the log.
	 *
	 * @return void
	 */
	public function testAFailedRelationSearchAnswersNone(): void {
		$answer = static function (array $query): array {
			throw new \RuntimeException('down');
		};

		$ids = $this->relations(answer: $answer)->findOioRelationsForDocument(register: '5', schema: '9', docUuid: self::DOCUMENT);

		$this->assertSame([], $ids);
		$this->assertSame('Drc-007: OIO relation search with partial match failed: down', $this->warnings[1]);
	}//end testAFailedRelationSearchAnswersNone()

	/**
	 * A pair nobody stored is unique after the link schema and the besluit's own schema are both read.
	 *
	 * @return void
	 */
	public function testAnUnlinkedPairIsUnique(): void {
		$error = $this->relations(answer: static fn (array $query): array => ['results' => []])
			->checkOioUniqueness(ioUrl: self::DOCUMENT_URL, objectUrl: self::BESLUIT_URL, objectType: 'decision');

		$this->assertNull($error);
		$this->assertSame(
			[
				['document' => self::DOCUMENT_URL, 'object' => self::BESLUIT_URL, '_limit' => 1, '@register' => '5', '@schema' => 'document_link_schema'],
				['document' => self::DOCUMENT, 'object' => self::BESLUIT, '_limit' => 1, '@register' => '5', '@schema' => 'document_link_schema'],
				['_search' => self::DOCUMENT . ' ' . self::BESLUIT, '_limit' => 1, '@register' => '5', '@schema' => 'document_link_schema'],
				['document' => self::DOCUMENT_URL, 'decision' => self::BESLUIT_URL, '_limit' => 1, '@register' => '5', '@schema' => 'decision_document_schema'],
				['document' => self::DOCUMENT, 'decision' => self::BESLUIT, '_limit' => 1, '@register' => '5', '@schema' => 'decision_document_schema'],
				['_search' => self::DOCUMENT . ' ' . self::BESLUIT, '_limit' => 1, '@register' => '5', '@schema' => 'decision_document_schema'],
			],
			$this->queries
		);
	}//end testAnUnlinkedPairIsUnique()

	/**
	 * A hit in the link schema, or in the schema of the object's own API, is a duplicate.
	 *
	 * @return void
	 */
	public function testAStoredPairIsADuplicate(): void {
		$expected = [
			'valid' => false,
			'status' => 400,
			'detail' => self::DUPLICATE,
			'invalidParams' => [['name' => 'nonFieldErrors', 'code' => 'unique', 'reason' => self::DUPLICATE]],
			'enrichedBody' => [],
		];

		foreach (['document_link_schema' => 'case', 'case_document_schema' => 'case', 'decision_document_schema' => 'decision'] as $schema => $type) {
			$answer = static fn (array $query): array => ['total' => (int)($query['@schema'] === $schema)];

			$this->assertSame(
				$expected,
				$this->relations(answer: $answer)->checkOioUniqueness(ioUrl: self::DOCUMENT_URL, objectUrl: self::BESLUIT_URL, objectType: $type),
				$schema
			);
		}
	}//end testAStoredPairIsADuplicate()

	/**
	 * URLs without a uuid are searched as URLs only, and an unknown objectType reads the link schema only.
	 *
	 * @return void
	 */
	public function testWhatIsSearchedFollowsWhatIsKnown(): void {
		$none = static fn (array $query): array => ['total' => 0];

		$this->assertNull($this->relations(answer: $none)->checkOioUniqueness(ioUrl: 'https://drc.example.com/a', objectUrl: 'https://x.example.com/b', objectType: 'verzoek'));
		$this->assertCount(1, $this->queries);

		$this->queries = [];
		$this->assertNull($this->relations(answer: $none)->checkOioUniqueness(ioUrl: self::DOCUMENT_URL, objectUrl: self::BESLUIT_URL, objectType: ''));
		$this->assertCount(3, $this->queries);
	}//end testWhatIsSearchedFollowsWhatIsKnown()

	/**
	 * No register means nothing is searched; an unset schema is skipped, the other still read.
	 *
	 * @return void
	 */
	public function testAMissingRegisterOrSchemaIsNotSearched(): void {
		$hit = static fn (array $query): array => ['total' => 1];

		$this->assertNull($this->relations(answer: $hit, mappingConfig: [])->checkOioUniqueness(ioUrl: self::DOCUMENT_URL, objectUrl: self::BESLUIT_URL, objectType: 'case'));
		$this->assertSame([], $this->queries);

		$schemas = ['document_link_schema' => '', 'case_document_schema' => ''];
		$this->assertNull($this->relations(answer: $hit, schemas: $schemas)->checkOioUniqueness(ioUrl: self::DOCUMENT_URL, objectUrl: self::BESLUIT_URL, objectType: 'case'));
		$this->assertSame([], $this->queries);

		$error = $this->relations(answer: $hit, schemas: ['document_link_schema' => ''])
			->checkOioUniqueness(ioUrl: self::DOCUMENT_URL, objectUrl: self::BESLUIT_URL, objectType: 'case');
		$this->assertSame('unique', $error['invalidParams'][0]['code']);
		$this->assertSame('case_document_schema', $this->queries[0]['@schema']);
	}//end testAMissingRegisterOrSchemaIsNotSearched()

	/**
	 * A duplicate search that cannot answer counts as a duplicate and is logged.
	 *
	 * @return void
	 */
	public function testAFailedDuplicateSearchFailsClosed(): void {
		$answer = static function (array $query): array {
			throw new \RuntimeException('down');
		};

		$error = $this->relations(answer: $answer)->checkOioUniqueness(ioUrl: self::DOCUMENT_URL, objectUrl: self::BESLUIT_URL, objectType: 'case');

		$this->assertSame('unique', $error['invalidParams'][0]['code']);
		$this->assertSame(['Drc-003: Uniqueness check failed for schema {schema}: {message}'], $this->warnings);
	}//end testAFailedDuplicateSearchFailsClosed()
}//end class
