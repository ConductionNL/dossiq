<?php

/**
 * What CollectionCaseLinks does when the register fails it.
 *
 * A collection that cannot be read is nobody's; a case object or the
 * collection's own record that cannot be saved is logged and the rest of the
 * link still happens; a publication whose title cannot be read is described by
 * the note alone; and every write runs as the system when the object service
 * offers that.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Intake
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-dossier-records-the-request-it-started-req-wri-004
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\CollectionCaseLinks;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Intake\CollectionCaseLinks
 * @uses   \OCA\Dossiq\Service\Support\UnscopedObjectReader
 */
class CollectionCaseLinksFailureTest extends TestCase {

	private const OWNER = 'subject-ref-anna';

	private const COLLECTION = 'col-1';

	/**
	 * Log lines, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * A collection whose read throws is not the resident's, and the failure is logged.
	 *
	 * @return void
	 */
	public function testAnUnreadableCollectionIsNobodys(): void {
		$register = $this->register(failingReads: ['collection']);

		self::assertNull($this->links()->owned(objectService: $register, collectionId: self::COLLECTION, subjectRef: self::OWNER));
		self::assertSame(
			[['warning', 'CollectionCaseLinks: the collection could not be read', ['collection' => self::COLLECTION, 'error' => 'read failed: collection']]],
			$this->logged
		);
	}//end testAnUnreadableCollectionIsNobodys()

	/**
	 * A collection without an owner is not the resident's.
	 *
	 * @return void
	 */
	public function testACollectionWithoutOwnerIsNobodys(): void {
		$register = $this->register();
		$register->seed(schema: 'collection', uuid: 'col-2', row: ['items' => []]);

		self::assertNull($this->links()->owned(objectService: $register, collectionId: 'col-2', subjectRef: self::OWNER));
	}//end testACollectionWithoutOwnerIsNobodys()

	/**
	 * A case object that cannot be saved is logged per item; the collection still records the case.
	 *
	 * @return void
	 */
	public function testAFailedCaseObjectIsLoggedAndTheCollectionStillLearnsTheCase(): void {
		$register = $this->register();
		$register->refusedSchemas = ['caseObject'];
		$links = $this->links();

		$links->link(objectService: $register, register: 'dossiq', caseId: 'case-1', collection: $this->owned(links: $links, register: $register));

		self::assertSame([], $register->all(schema: 'caseObject'));
		self::assertSame(
			[
				['error', 'CollectionCaseLinks: a collection item could not be linked to the case', ['case' => 'case-1', 'publication' => 'pub-a', 'error' => 'Save refused for caseObject']],
				['error', 'CollectionCaseLinks: a collection item could not be linked to the case', ['case' => 'case-1', 'publication' => 'pub-b', 'error' => 'Save refused for caseObject']],
			],
			$this->logged
		);
		self::assertSame(['dossiq:case:old', CollectionCaseLinks::SOURCE_PREFIX . 'case-1'], $register->row(schema: 'collection', uuid: self::COLLECTION)['sourceOf']);
	}//end testAFailedCaseObjectIsLoggedAndTheCollectionStillLearnsTheCase()

	/**
	 * A collection that cannot record the case is logged; the case objects are still written.
	 *
	 * @return void
	 */
	public function testACollectionThatCannotRecordTheCaseIsLogged(): void {
		$register = $this->register();
		$links = $this->links();
		$owned = $this->owned(links: $links, register: $register);
		$register->refusedSchemas = ['collection'];

		$links->link(objectService: $register, register: 'dossiq', caseId: 'case-2', collection: $owned);

		self::assertCount(2, $register->all(schema: 'caseObject'));
		self::assertSame(
			[['warning', 'CollectionCaseLinks: the collection could not record the case it started', ['collection' => self::COLLECTION, 'case' => 'case-2', 'error' => 'Save refused for collection']]],
			$this->logged
		);
		self::assertSame(['dossiq:case:old'], $register->row(schema: 'collection', uuid: self::COLLECTION)['sourceOf']);
	}//end testACollectionThatCannotRecordTheCaseIsLogged()

	/**
	 * Earlier case references are kept, non-string ones dropped, and the new one appended.
	 *
	 * @return void
	 */
	public function testTheNewCaseIsAppendedToTheStringReferences(): void {
		$register = $this->register();
		$links = $this->links();
		$owned = $this->owned(links: $links, register: $register);
		$owned['sourceOf'] = ['dossiq:case:old', 42, null];

		$links->link(objectService: $register, register: 'dossiq', caseId: 'case-3', collection: $owned);

		$row = $register->row(schema: 'collection', uuid: self::COLLECTION);
		self::assertSame(['dossiq:case:old', 'dossiq:case:case-3'], $row['sourceOf']);
		self::assertArrayNotHasKey('@self', $row['items'][0] ?? []);
		self::assertSame(self::OWNER, $row['owner']);
	}//end testTheNewCaseIsAppendedToTheStringReferences()

	/**
	 * Without a readable title the note alone describes the item; without a note, the title alone.
	 *
	 * @return void
	 */
	public function testDescriptionFallsBackToNoteOrTitle(): void {
		$register = $this->register(failingReads: ['publication']);
		$links = $this->links();
		$collection = ['id' => self::COLLECTION, 'items' => [['publication' => 'pub-a', 'note' => '  Het besluit  ']], 'sourceOf' => []];

		$links->link(objectService: $register, register: 'dossiq', caseId: 'case-4', collection: $collection);
		self::assertSame(['Het besluit'], array_column(array_values($register->all(schema: 'caseObject')), 'description'));

		$plain = $this->register();
		$links->link(objectService: $plain, register: 'dossiq', caseId: 'case-5', collection: ['id' => self::COLLECTION, 'items' => [['publication' => 'pub-a', 'note' => ' ']], 'sourceOf' => []]);
		self::assertSame(['Besluit'], array_column(array_values($plain->all(schema: 'caseObject')), 'description'));
	}//end testDescriptionFallsBackToNoteOrTitle()

	/**
	 * Every read and write goes through runAsSystem when the object service offers it.
	 *
	 * @return void
	 */
	public function testWritesRunAsTheSystemWhenOffered(): void {
		$register = new class extends InMemoryRegister {
			/**
			 * How often an operation ran as the system.
			 *
			 * @var int
			 */
			public int $systemRuns = 0;

			/**
			 * Run the operation as the system.
			 *
			 * @param callable $operation The operation.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->systemRuns++;

				return $operation();
			}
		};
		$this->seed(register: $register);
		$links = $this->links();

		$links->link(objectService: $register, register: 'dossiq', caseId: 'case-6', collection: $this->owned(links: $links, register: $register));

		self::assertSame(6, $register->systemRuns, 'three reads (collection, two titles) and three writes (two case objects, the collection)');
		self::assertCount(2, $register->all(schema: 'caseObject'));
	}//end testWritesRunAsTheSystemWhenOffered()

	/**
	 * The owned collection.
	 *
	 * @param CollectionCaseLinks $links    The links.
	 * @param InMemoryRegister    $register The register.
	 *
	 * @return array<string, mixed>
	 */
	private function owned(CollectionCaseLinks $links, InMemoryRegister $register): array {
		$owned = $links->owned(objectService: $register, collectionId: self::COLLECTION, subjectRef: self::OWNER);
		self::assertIsArray($owned);

		return $owned;
	}//end owned()

	/**
	 * A seeded register whose reads of the given schemas throw.
	 *
	 * @param array<int, string> $failingReads Schemas whose reads throw.
	 *
	 * @return InMemoryRegister
	 */
	private function register(array $failingReads = []): InMemoryRegister {
		$register = new class ($failingReads) extends InMemoryRegister {
			/**
			 * Constructor.
			 *
			 * @param array<int, string> $failingReads Schemas whose reads throw.
			 */
			public function __construct(private readonly array $failingReads) {
			}

			/**
			 * Read a row, throwing for a failing schema.
			 *
			 * @param int|string $id            The id.
			 * @param mixed      $_extend       Ignored.
			 * @param bool       $files         Ignored.
			 * @param int|string $register      The register.
			 * @param int|string $schema        The schema.
			 * @param bool       $_rbac         Access scope.
			 * @param bool       $_multitenancy Tenancy scope.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(
				int|string $id,
				mixed $_extend = null,
				bool $files = false,
				int|string $register = '',
				int|string $schema = '',
				bool $_rbac = true,
				bool $_multitenancy = true,
			): ?array {
				if (in_array((string)$schema, $this->failingReads, true) === true) {
					throw new RuntimeException('read failed: ' . (string)$schema);
				}

				return parent::find(id: $id, _extend: $_extend, files: $files, register: $register, schema: $schema, _rbac: $_rbac, _multitenancy: $_multitenancy);
			}
		};
		$this->seed(register: $register);

		return $register;
	}//end register()

	/**
	 * Seed two publications and the resident's collection.
	 *
	 * @param InMemoryRegister $register The register.
	 *
	 * @return void
	 */
	private function seed(InMemoryRegister $register): void {
		$register->seed(schema: 'publication', uuid: 'pub-a', row: ['title' => 'Besluit']);
		$register->seed(schema: 'publication', uuid: 'pub-b', row: ['title' => 'Bijlage']);
		$register->seed(
			schema: 'collection',
			uuid: self::COLLECTION,
			row: [
				'owner' => self::OWNER,
				'items' => [
					['publication' => 'pub-a', 'note' => 'Het besluit'],
					['publication' => 'pub-b', 'attachment' => 1, 'note' => ''],
				],
				'sourceOf' => ['dossiq:case:old'],
			]
		);
	}//end seed()

	/**
	 * The links under test, logging into $this->logged.
	 *
	 * @return CollectionCaseLinks
	 */
	private function links(): CollectionCaseLinks {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => ['case_object_schema' => 'caseObject'][$key] ?? $default
		);
		$settings->method('getWooPublicationConfigValue')->willReturnCallback(
			fn (string $key): string => SettingsService::WOO_PUBLICATION_DEFAULTS[$key] ?? ''
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'https://gemeente.test' . $path);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		return new CollectionCaseLinks(settingsService: $settings, urlGenerator: $urls, logger: $logger);
	}//end links()
}//end class
