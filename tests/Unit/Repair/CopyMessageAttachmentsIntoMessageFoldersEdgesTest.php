<?php

/**
 * Copying portal message attachments on the paths the main test leaves
 * alone: no OpenRegister, a record lookup that throws, a file id that names
 * a folder, and a copy that fails.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Kernel\Contracts\DocumentProjectionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DocumentRecordStoreInterface;
use OCA\Dossiq\Repair\CopyMessageAttachmentsIntoMessageFolders;
use OCA\Dossiq\Service\SettingsService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\CopyMessageAttachmentsIntoMessageFolders
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity
 */
class CopyMessageAttachmentsIntoMessageFoldersEdgesTest extends TestCase {

	/**
	 * What the app config holds.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Build the step.
	 *
	 * @param object|null                  $objects    The object service.
	 * @param DocumentRecordStoreInterface $store      The record store.
	 * @param IRootFolder                  $rootFolder The root folder.
	 * @param LoggerInterface              $logger     The logger.
	 *
	 * @return CopyMessageAttachmentsIntoMessageFolders The step.
	 */
	private function step(?object $objects, DocumentRecordStoreInterface $store, IRootFolder $rootFolder, LoggerInterface $logger): CopyMessageAttachmentsIntoMessageFolders {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ([
				'register' => 'dossiq',
				'portaal_bericht_schema' => 'portaalBericht',
			][$key] ?? $default)
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/system/files/Open Registers/dossiq/msg-1');
		$folder->method('nodeExists')->willReturn(false);
		$projection = $this->createMock(DocumentProjectionServiceInterface::class);
		$projection->method('folderOf')->willReturn($folder);

		return new CopyMessageAttachmentsIntoMessageFolders(
			settingsService: $settings,
			store: $store,
			projection: $projection,
			rootFolder: $rootFolder,
			appConfig: $appConfig,
			logger: $logger,
		);
	}//end step()

	/**
	 * An object service answering one page with one message.
	 *
	 * @param array<int, string> $attachments The message's attachment ids.
	 *
	 * @return object The fake.
	 */
	private function objects(array $attachments): object {
		return new class($attachments) {
			/**
			 * @param array<int, string> $attachments The attachment ids.
			 */
			public function __construct(private readonly array $attachments) {
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema The schema.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> One page.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return [['id' => 'msg-1', 'attachments' => $this->attachments]];
			}
		};
	}//end objects()

	/**
	 * Without OpenRegister nothing is copied and the step is not marked done.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsCopiedAndTheKeyStaysUnset(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with('Dossiq portal messages: OpenRegister unavailable; attachments stay where they are.');
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->never())->method('getById');

		$this->step(null, $this->createMock(DocumentRecordStoreInterface::class), $rootFolder, $this->createMock(LoggerInterface::class))
			->run($output);

		$this->assertArrayNotHasKey(CopyMessageAttachmentsIntoMessageFolders::DONE_KEY, $this->config);
	}//end testWithoutOpenRegisterNothingIsCopiedAndTheKeyStaysUnset()

	/**
	 * A record lookup that throws, and a file id that names a folder rather
	 * than a file, both count as an attachment without a file; a copy that
	 * fails is logged by name. None stops the run or the done key.
	 *
	 * @return void
	 */
	public function testUnresolvableAndUncopyableAttachmentsAreNamedAndTheRunCompletes(): void {
		$store = $this->createMock(DocumentRecordStoreInterface::class);
		$store->method('findRecord')->willThrowException(new RuntimeException('record schema missing'));

		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('besluit.pdf');
		$file->expects($this->once())->method('copy')
			->with('/system/files/Open Registers/dossiq/msg-1/besluit.pdf')
			->willThrowException(new RuntimeException('quota exceeded'));

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->willReturnCallback(
			fn (int $id): array => match ($id) {
				7 => [$this->createMock(Folder::class)],
				8 => [$file],
				default => [],
			}
		);

		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with(
			'Dossiq portal messages: copied 0 file(s) into their message, left 0 already there, 3 named in the log.'
		);

		$this->step($this->objects(['rec-1', '7', '8']), $store, $rootFolder, $logger)->run($output);

		$this->assertSame(
			[
				'Dossiq portal messages: attachment rec-1 of msg-1 has no file; it was not copied',
				'Dossiq portal messages: attachment 7 of msg-1 has no file; it was not copied',
				'Dossiq portal messages: besluit.pdf could not be copied into msg-1: quota exceeded',
			],
			$warnings
		);
		$this->assertSame(
			CopyMessageAttachmentsIntoMessageFolders::DONE_VERSION,
			$this->config[CopyMessageAttachmentsIntoMessageFolders::DONE_KEY]
		);
	}//end testUnresolvableAndUncopyableAttachmentsAreNamedAndTheRunCompletes()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepNamesItself(): void {
		$this->assertSame(
			'Copy the attachments of every Dossiq portal message into the message folder',
			$this->step(
				null,
				$this->createMock(DocumentRecordStoreInterface::class),
				$this->createMock(IRootFolder::class),
				$this->createMock(LoggerInterface::class)
			)->getName()
		);
	}//end testTheStepNamesItself()
}//end class
