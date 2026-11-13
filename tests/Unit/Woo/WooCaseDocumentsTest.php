<?php

/**
 * Woo Case Documents Test
 *
 * Which documents a Woo case has (informatieobjecten through their
 * zaakinformatieobject join, then legacy document rows), how one document is
 * looked up across both schemas, and when its file is read into `content`.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Woo\WooCaseDocuments
 * @uses   \OCA\Dossiq\Service\Support\FindsFilesById
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class WooCaseDocumentsTest extends TestCase {

	/**
	 * Config the settings double answers.
	 *
	 * @var array<string, string>
	 */
	private array $config = [
		'register' => 'dossiq',
		'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
		'dossier_informatieobject_schema' => 'informatieobject',
		'document_schema' => 'document',
	];

	/**
	 * Rows per schema slug, for the search double.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $rows = [];

	/**
	 * Objects per "schema/id", for the find double.
	 *
	 * @var array<string, mixed>
	 */
	private array $objects = [];

	/**
	 * Schemas whose search throws.
	 *
	 * @var array<int, string>
	 */
	private array $failingSchemas = [];

	private ?object $objectService = null;

	private ?object $fileService = null;

	private IRootFolder&MockObject $rootFolder;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->objectServiceDouble();
	}//end setUp()

	/**
	 * An ObjectService double answering searchObjectsBySlug() and find().
	 *
	 * @return object
	 */
	private function objectServiceDouble(): object {
		$test = $this;
		return new class($test) {
			/**
			 * Filters each search was called with, by schema.
			 *
			 * @var array<string, array<string, mixed>>
			 */
			public array $filters = [];

			public function __construct(private readonly WooCaseDocumentsTest $test) {
			}

			/**
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, mixed>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->filters[$schema] = $filters;
				return $this->test->rowsFor($schema);
			}

			/**
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return mixed
			 */
			public function find(string $id, string $register, string $schema): mixed {
				return $this->test->objectFor($schema, $id);
			}
		};
	}//end objectServiceDouble()

	/**
	 * Rows for one schema; throws for a failing one.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, mixed>
	 */
	public function rowsFor(string $schema): array {
		if (in_array($schema, $this->failingSchemas, true) === true) {
			throw new RuntimeException('search exploded');
		}

		return $this->rows[$schema] ?? [];
	}//end rowsFor()

	/**
	 * One object; throws DoesNotExist when unknown, rethrows a stored Throwable.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The id.
	 *
	 * @return mixed
	 */
	public function objectFor(string $schema, string $id): mixed {
		$key = $schema . '/' . $id;
		if (array_key_exists($key, $this->objects) === false) {
			throw new DoesNotExistException('missing');
		}

		if ($this->objects[$key] instanceof \Throwable) {
			throw $this->objects[$key];
		}

		return $this->objects[$key];
	}//end objectFor()

	/**
	 * The class under test.
	 *
	 * @return WooCaseDocuments
	 */
	private function documents(): WooCaseDocuments {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturnCallback(fn (): ?object => $this->objectService);
		$settings->method('getFileService')->willReturnCallback(fn (): ?object => $this->fileService);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => $this->config[$key] ?? $default
		);

		return new WooCaseDocuments(settingsService: $settings, rootFolder: $this->rootFolder, logger: $this->logger);
	}//end documents()

	public function testIdsListInformatieobjectenFirstThenLegacyDocumentsDeduplicated(): void {
		$this->rows['zaakinformatieobject'] = [
			['case' => 'case-1', 'informatieobject' => 'io-1'],
			['case' => 'case-1', 'informatieobject' => 'io-2'],
			['case' => 'case-OTHER', 'informatieobject' => 'io-9'],
			['case' => 'case-1'],
		];
		$this->rows['document'] = [
			['case' => 'case-1', 'id' => 'doc-1'],
			['case' => 'case-1', 'uuid' => 'doc-2'],
			['case' => 'case-1', 'id' => 'io-1'],
		];

		$this->assertSame(['io-1', 'io-2', 'doc-1', 'doc-2'], $this->documents()->idsFor('case-1'));
		$this->assertSame(['case' => 'case-1', '_limit' => 500], $this->objectService->filters['zaakinformatieobject']);
	}

	public function testIdsSkipASchemaThatIsNotConfigured(): void {
		$this->config['dossier_zaakinformatieobject_schema'] = '';
		$this->rows['document'] = [['case' => 'case-1', 'id' => 'doc-1']];

		$this->assertSame(['doc-1'], $this->documents()->idsFor('case-1'));
		$this->assertArrayNotHasKey('zaakinformatieobject', $this->objectService->filters);
	}

	public function testIdsAreEmptyWithoutObjectServiceRegisterOrCase(): void {
		$this->rows['document'] = [['case' => 'case-1', 'id' => 'doc-1']];

		$this->assertSame([], $this->documents()->idsFor(''));

		$this->config['register'] = '';
		$this->assertSame([], $this->documents()->idsFor('case-1'));

		$this->config['register'] = 'dossiq';
		$this->objectService = null;
		$this->assertSame([], $this->documents()->idsFor('case-1'));
	}

	public function testAFailingSearchIsLoggedAndTheOtherSchemaStillCounts(): void {
		$this->failingSchemas = ['zaakinformatieobject'];
		$this->rows['document'] = [['case' => 'case-1', 'id' => 'doc-1']];
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'WooCaseDocuments: the case documents could not be read',
				['schema' => 'zaakinformatieobject', 'error' => 'search exploded']
			);

		$this->assertSame(['doc-1'], $this->documents()->idsFor('case-1'));
	}

	public function testMetaPrefersTheInformatieobjectAndFallsBackToTheLegacyRow(): void {
		$this->objects['informatieobject/io-1'] = ['id' => 'io-1', 'titel' => 'Besluit'];
		$this->objects['document/doc-1'] = ['id' => 'doc-1', 'title' => 'Old'];

		$this->assertSame(['id' => 'io-1', 'titel' => 'Besluit'], $this->documents()->meta('io-1'));
		$this->assertSame(['id' => 'doc-1', 'title' => 'Old'], $this->documents()->meta('doc-1'));
		$this->assertNull($this->documents()->meta('nowhere'));
	}

	public function testMetaTreatsALookupErrorAsNotFoundAndSkipsUnconfiguredSchemas(): void {
		$this->objects['informatieobject/x'] = new RuntimeException('db down');
		$this->objects['document/x'] = ['id' => 'x'];

		$this->assertSame(['id' => 'x'], $this->documents()->meta('x'));

		$this->config['document_schema'] = '';
		$this->assertNull($this->documents()->meta('x'));
	}

	public function testMetaIsNullWithoutAnIdOrObjectService(): void {
		$this->assertNull($this->documents()->meta(''));

		$this->objectService = null;
		$this->assertNull($this->documents()->meta('io-1'));
	}

	public function testLoadReadsTheFileIntoBase64Content(): void {
		$this->objects['informatieobject/io-1'] = ['id' => 'io-1', 'fileId' => 42];
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('%PDF-1.7');
		$this->rootFolder->expects($this->once())->method('getById')->with(42)->willReturn([$file]);

		$this->assertSame(
			['id' => 'io-1', 'fileId' => 42, 'content' => base64_encode('%PDF-1.7')],
			$this->documents()->load('io-1')
		);
	}

	public function testLoadKeepsExistingContentAndIgnoresRowsWithoutAFile(): void {
		$this->objects['informatieobject/a'] = ['id' => 'a', 'fileId' => 42, 'content' => 'already'];
		$this->objects['informatieobject/b'] = ['id' => 'b'];
		$this->rootFolder->expects($this->never())->method('getById');

		$this->assertSame('already', $this->documents()->load('a')['content']);
		$this->assertSame(['id' => 'b'], $this->documents()->load('b'));
		$this->assertNull($this->documents()->load('missing'));
	}

	public function testLoadLeavesContentOutWhenTheFileIsGone(): void {
		$this->objects['informatieobject/io-1'] = ['id' => 'io-1', 'fileId' => 42];
		$this->rootFolder->method('getById')->willReturn([]);

		$this->assertSame(['id' => 'io-1', 'fileId' => 42], $this->documents()->load('io-1'));
	}

	public function testAnUnreadableFileIsLoggedAndTheRowStillReturned(): void {
		$this->objects['informatieobject/io-1'] = ['id' => 'io-1', 'fileId' => '7'];
		$this->rootFolder->method('getById')->willThrowException(new RuntimeException('storage offline'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('WooCaseDocuments: a document file could not be read', ['fileId' => 7, 'error' => 'storage offline']);

		$this->assertSame(['id' => 'io-1', 'fileId' => '7'], $this->documents()->load('io-1'));
	}
}//end class
