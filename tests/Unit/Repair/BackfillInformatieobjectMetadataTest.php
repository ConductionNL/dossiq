<?php

/**
 * The informatieobject back-fill: which dossier files get metadata, and what it records.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\BackfillInformatieobjectMetadata;
use OCA\Dossiq\Service\SettingsService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every file in a case folder under dossiq/documenten without an
 * informatieobject gets one, linked to its case; part files, files already
 * registered and anything that is not a file are left alone.
 *
 * @covers \OCA\Dossiq\Repair\BackfillInformatieobjectMetadata
 * @uses   \OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
final class BackfillInformatieobjectMetadataTest extends TestCase {
	/** 2025-10-12 12:00 UTC: the same calendar day in every time zone within twelve hours. */
	private const NOON = 1760270400;

	/** @var array<string, string> */
	private array $config = ['register' => '23', 'dossier_informatieobject_schema' => '7', 'dossier_zaakinformatieobject_schema' => '8'];

	/** @var array<int, string> */
	private array $lines = [];

	/** @var array<int, string> */
	private array $warnings = [];

	/**
	 * An object store holding these registered file names.
	 *
	 * @param array<int, string> $registered File names already registered.
	 * @param array<int, string> $refuse     File names whose save fails.
	 */
	private function store(array $registered = [], array $refuse = []): object {
		return new class($registered, $refuse) {
			/** @var array<int, array{object: array, register: string, schema: string}> */
			public array $saved = [];

			/** @var array<int, array> */
			public array $searches = [];

			public bool $asSystem = false;

			/**
			 * @param array<int, string> $registered File names already registered.
			 * @param array<int, string> $refuse     File names whose save fails.
			 */
			public function __construct(private array $registered, private array $refuse) {
			}

			public function runAsSystem(callable $operation): mixed {
				$this->asSystem = true;
				return $operation();
			}

			public function searchObjects(array $query): array {
				$this->searches[] = $query;
				return array_map(static fn (string $name): array => ['fileName' => $name], $this->registered);
			}

			public function saveObject(array $object, int|string $register, int|string $schema): object {
				if (in_array(($object['fileName'] ?? ''), $this->refuse, true) === true) {
					throw new RuntimeException('store refused');
				}

				$this->saved[] = ['object' => $object, 'register' => (string)$register, 'schema' => (string)$schema];
				$uuid = 'io-'.count($this->saved);
				return new class($uuid) {
					public function __construct(private string $uuid) {
					}

					public function getUuid(): string {
						return $this->uuid;
					}
				};
			}
		};
	}//end store()

	/**
	 * A dossier file.
	 *
	 * @param string $name    The file name.
	 * @param string $content The content.
	 */
	private function file(string $name, string $content = 'inhoud'): File {
		$owner = $this->createMock(originalClassName: IUser::class);
		$owner->method('getDisplayName')->willReturn('Anna Bakker');
		$file = $this->createMock(originalClassName: File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturn($content);
		$file->method('getOwner')->willReturn($owner);
		$file->method('getSize')->willReturn(strlen($content));
		$file->method('getMimeType')->willReturn('application/pdf');
		$file->method('getMTime')->willReturn(self::NOON);
		$file->method('getId')->willReturn(901);
		return $file;
	}//end file()

	/**
	 * A folder with these children.
	 *
	 * @param string $name     The folder name.
	 * @param array  $children The nodes in it.
	 */
	private function folder(string $name, array $children): Folder {
		$folder = $this->createMock(originalClassName: Folder::class);
		$folder->method('getName')->willReturn($name);
		$folder->method('getDirectoryListing')->willReturn($children);
		return $folder;
	}//end folder()

	/**
	 * Run the step over this store and storage root.
	 *
	 * @param object|null $store The object store, or null when OpenRegister is absent.
	 * @param IRootFolder $root  The root folder.
	 */
	private function backfill(?object $store, IRootFolder $root): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(fn (string $key, string $default = ''): string => ($this->config[$key] ?? $default));

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $line): void {
				$this->lines[] = $line;
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		(new BackfillInformatieobjectMetadata(settingsService: $settings, rootFolder: $root, logger: $logger))->run($output);
	}//end backfill()

	/**
	 * A root whose admin folder holds this documents folder, or none.
	 *
	 * @param Folder|null $documents The dossiq/documenten folder.
	 */
	private function root(?Folder $documents): IRootFolder {
		$userFolder = $this->createMock(originalClassName: Folder::class);
		$userFolder->method('nodeExists')->with('dossiq/documenten')->willReturn($documents !== null);
		$userFolder->method('get')->with('dossiq/documenten')->willReturn($documents);
		$root = $this->createMock(originalClassName: IRootFolder::class);
		$root->method('getUserFolder')->with('admin')->willReturn($userFolder);
		return $root;
	}//end root()

	public function testANewFileGetsAnInformatieobjectLinkedToItsCase(): void {
		$store     = $this->store(registered: ['oud.pdf']);
		$documents = $this->folder(
			name: 'documenten',
			children: [
				$this->file(name: 'losse-file.pdf'),
				$this->folder(name: 'case-1', children: [$this->file(name: 'brief.pdf', content: 'brief'), $this->file(name: '_part_brief.pdf'), $this->file(name: 'oud.pdf'), $this->folder(name: 'sub', children: [])]),
			]
		);

		$this->backfill(store: $store, root: $this->root(documents: $documents));

		$this->assertTrue($store->asSystem);
		$this->assertSame([['_limit' => 10000, '@self' => ['register' => 23, 'schema' => 7]]], $store->searches);
		$this->assertCount(2, $store->saved);

		$info = $store->saved[0];
		$this->assertSame(['23', '7'], [$info['register'], $info['schema']]);
		$integrity = $info['object']['integrity'];
		unset($info['object']['integrity']);
		$this->assertSame(
			[
				'title' => 'brief.pdf',
				'fileName' => 'brief.pdf',
				'bestandsomvang' => 5,
				'format' => 'application/pdf',
				'vertrouwelijkheidaanduiding' => 'intern',
				'auteur' => 'Anna Bakker',
				'status' => 'draft',
				'informatieobjecttype' => '',
				'creatiedatum' => '2025-10-12',
				'taal' => 'nld',
				'fileId' => 901,
			],
			$info['object']
		);
		$this->assertSame(['sha256', hash('sha256', 'brief')], [$integrity['algorithm'], $integrity['value']]);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $integrity['date']);

		$join = $store->saved[1];
		$this->assertSame(['23', '8'], [$join['register'], $join['schema']]);
		$this->assertSame(['case' => 'case-1', 'informatieobject' => 'io-1', 'natureRelationshipDisplay' => 'Hoort at omgekeerd'], array_slice($join['object'], 0, 3, true));
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $join['object']['registrationDate']);

		$this->assertSame(['Dossiq backfill: created 1 informatieobject(en), skipped 1 existing.'], $this->lines);
	}//end testANewFileGetsAnInformatieobjectLinkedToItsCase()

	public function testAFileNameIsRegisteredOnceAcrossCases(): void {
		$store = $this->store();
		$documents = $this->folder(
			name: 'documenten',
			children: [$this->folder(name: 'case-1', children: [$this->file(name: 'besluit.pdf')]), $this->folder(name: 'case-2', children: [$this->file(name: 'besluit.pdf')])]
		);

		$this->backfill(store: $store, root: $this->root(documents: $documents));

		$this->assertSame('case-1', $store->saved[1]['object']['case']);
		$this->assertCount(2, $store->saved);
		$this->assertSame(['Dossiq backfill: created 1 informatieobject(en), skipped 1 existing.'], $this->lines);
	}//end testAFileNameIsRegisteredOnceAcrossCases()

	public function testWithoutAJoinSchemaOnlyTheInformatieobjectIsSaved(): void {
		unset($this->config['dossier_zaakinformatieobject_schema']);
		$store = $this->store();

		$this->backfill(store: $store, root: $this->root(documents: $this->folder(name: 'documenten', children: [$this->folder(name: 'case-1', children: [$this->file(name: 'a.pdf')])])));

		$this->assertCount(1, $store->saved);
		$this->assertSame('7', $store->saved[0]['schema']);
	}//end testWithoutAJoinSchemaOnlyTheInformatieobjectIsSaved()

	public function testAFailingFileIsLoggedAndTheRestContinues(): void {
		$store = $this->store(refuse: ['kapot.pdf']);

		$this->backfill(store: $store, root: $this->root(documents: $this->folder(name: 'documenten', children: [$this->folder(name: 'case-1', children: [$this->file(name: 'kapot.pdf'), $this->file(name: 'heel.pdf')])])));

		$this->assertSame('heel.pdf', $store->saved[0]['object']['fileName']);
		$this->assertSame(['Dossiq backfill: failed for kapot.pdf: store refused'], $this->warnings);
		$this->assertSame(['Dossiq backfill: created 1 informatieobject(en), skipped 0 existing.'], $this->lines);
	}//end testAFailingFileIsLoggedAndTheRestContinues()

	public function testNothingRunsWithoutOpenRegister(): void {
		$root = $this->createMock(originalClassName: IRootFolder::class);
		$root->expects($this->never())->method('getUserFolder');

		$this->backfill(store: null, root: $root);

		$this->assertSame(['Dossiq backfill: OpenRegister unavailable; skipping.'], $this->lines);
	}//end testNothingRunsWithoutOpenRegister()

	public function testNothingRunsWithoutTheSchemas(): void {
		$this->config['dossier_informatieobject_schema'] = '';
		$root = $this->createMock(originalClassName: IRootFolder::class);
		$root->expects($this->never())->method('getUserFolder');
		$store = $this->store();

		$this->backfill(store: $store, root: $root);

		$this->assertSame(['Dossiq backfill: dossier schemas not configured; skipping.'], $this->lines);
		$this->assertSame([], $store->searches);
	}//end testNothingRunsWithoutTheSchemas()

	public function testAMissingOrUnreadableStorageFolderIsNothingToDo(): void {
		$store = $this->store();
		$this->backfill(store: $store, root: $this->root(documents: null));

		$missing = $this->createMock(originalClassName: IRootFolder::class);
		$missing->method('getUserFolder')->willThrowException(new NotFoundException('no admin'));
		$this->backfill(store: $store, root: $missing);

		$broken = $this->createMock(originalClassName: IRootFolder::class);
		$broken->method('getUserFolder')->willThrowException(new RuntimeException('storage offline'));
		$this->backfill(store: $store, root: $broken);

		$this->assertSame(array_fill(0, 3, 'Dossiq backfill: storage folder absent; nothing to back-fill.'), $this->lines);
		$this->assertSame(['Dossiq backfill: cannot resolve storage folder: storage offline'], $this->warnings);
		$this->assertSame([], $store->saved);
	}//end testAMissingOrUnreadableStorageFolderIsNothingToDo()
}//end class
