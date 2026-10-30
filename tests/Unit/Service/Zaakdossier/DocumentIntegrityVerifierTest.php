<?php

/**
 * Stored checksums are checked again: the file is hashed now and compared
 * with the hash recorded when it was stored, and the check is recorded on the
 * document with its time.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 * @spec openspec/changes/document-integrity-check/specs/document-integrity-check/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DocumentDefaults;
use OCA\Dossiq\Service\Zaakdossier\DocumentIntegrityVerifier;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\DocumentIntegrityVerifier
 */
class DocumentIntegrityVerifierTest extends TestCase {

	/**
	 * The stored cursor.
	 *
	 * @var int
	 */
	private int $cursor = 0;

	/**
	 * A verifier over the register, where file 11 still hashes to its stored value,
	 * file 12 now hashes differently and file 13 is gone.
	 *
	 * @param InMemoryRegister $register The register.
	 *
	 * @return DocumentIntegrityVerifier
	 */
	private function verifier(InMemoryRegister $register): DocumentIntegrityVerifier {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(value: $register);
		$settings->method('getConfigValue')->willReturnCallback(
			callback: static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'dossier_informatieobject_schema' => 'informatieobject',
			][$key] ?? $default
		);

		$root = $this->createMock(originalClassName: IRootFolder::class);
		$root->method('getFirstNodeById')->willReturnCallback(
			callback: function (int $fileId): ?File {
				if ($fileId === 13) {
					return null;
				}

				$file = $this->createMock(originalClassName: File::class);
				$file->method('getId')->willReturn(value: $fileId);
				return $file;
			}
		);

		$defaults = $this->createMock(originalClassName: DocumentDefaults::class);
		$defaults->method('integrityOf')->willReturnCallback(
			callback: static function (File $node): array {
				$value = 'zzz';
				if ($node->getId() === 11) {
					$value = 'aaa';
				}

				return ['algorithm' => 'sha256', 'value' => $value, 'date' => 'now'];
			}
		);

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(callback: fn (): int => $this->cursor);
		$config->method('setValueInt')->willReturnCallback(
			callback: function (string $app, string $key, int $value): bool {
				$this->cursor = $value;
				return true;
			}
		);

		return new DocumentIntegrityVerifier(settingsService: $settings, rootFolder: $root, defaults: $defaults, appConfig: $config);
	}//end verifier()

	/**
	 * Three documents with a stored hash and one without.
	 *
	 * @return InMemoryRegister
	 */
	private function register(): InMemoryRegister {
		$register = new InMemoryRegister();
		$register->pages = true;
		$register->seed(
			schema: 'informatieobject',
			uuid: 'd1',
			row: ['fileId' => 11, 'integrity' => ['algorithm' => 'sha256', 'value' => 'aaa', 'date' => '2026-09-13T16:57:49']]
		);
		$register->seed(
			schema: 'informatieobject',
			uuid: 'd2',
			row: ['fileId' => 12, 'integrity' => ['algorithm' => 'sha256', 'value' => 'bbb', 'date' => '2026-09-13T16:57:49']]
		);
		$register->seed(
			schema: 'informatieobject',
			uuid: 'd3',
			row: ['fileId' => 13, 'integrity' => ['algorithm' => 'sha256', 'value' => 'ccc', 'date' => '2026-09-13T16:57:49']]
		);
		$register->seed(schema: 'informatieobject', uuid: 'd4', row: ['fileId' => 14]);

		return $register;
	}//end register()

	/**
	 * Each hashed document gets the check's outcome and time; the stored hash and its date stay.
	 *
	 * @return void
	 */
	public function testRecordsTheOutcomeAndTimeOfEachCheck(): void {
		$register = $this->register();
		$checked = $this->verifier(register: $register)->verifyNext(batch: 10);

		self::assertSame(expected: 4, actual: $checked);
		self::assertSame(expected: 'unchanged', actual: $register->row(schema: 'informatieobject', uuid: 'd1')['integrity']['status']);
		self::assertSame(expected: 'changed', actual: $register->row(schema: 'informatieobject', uuid: 'd2')['integrity']['status']);
		self::assertSame(expected: 'missing', actual: $register->row(schema: 'informatieobject', uuid: 'd3')['integrity']['status']);
		self::assertSame(expected: 'aaa', actual: $register->row(schema: 'informatieobject', uuid: 'd1')['integrity']['value']);
		self::assertSame(expected: '2026-09-13T16:57:49', actual: $register->row(schema: 'informatieobject', uuid: 'd1')['integrity']['date']);
		self::assertNotEmpty(actual: $register->row(schema: 'informatieobject', uuid: 'd1')['integrity']['checkedAt']);
	}//end testRecordsTheOutcomeAndTimeOfEachCheck()

	/**
	 * A document whose file was never hashed gets its hash on the first check; one without a file stays without.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-document-without-a-checksum-gets-one/specs/document-integrity-check/spec.md#requirement-a-document-without-a-stored-checksum-gets-one-on-its-first-check
	 */
	public function testADocumentWithoutAHashGetsOneOnItsFirstCheck(): void {
		$register = $this->register();
		$register->seed(schema: 'informatieobject', uuid: 'd5', row: ['fileId' => 13]);
		$register->seed(schema: 'informatieobject', uuid: 'd6', row: ['title' => 'No file']);

		$checked = $this->verifier(register: $register)->verifyNext(batch: 10);

		$recorded = $register->row(schema: 'informatieobject', uuid: 'd4')['integrity'];
		self::assertSame(expected: 4, actual: $checked);
		self::assertSame(expected: 'zzz', actual: $recorded['value']);
		self::assertSame(expected: 'now', actual: $recorded['date']);
		self::assertSame(expected: 'unchanged', actual: $recorded['status']);
		self::assertNotEmpty(actual: $recorded['checkedAt']);
		self::assertArrayNotHasKey(key: 'integrity', array: $register->row(schema: 'informatieobject', uuid: 'd5'));
		self::assertArrayNotHasKey(key: 'integrity', array: $register->row(schema: 'informatieobject', uuid: 'd6'));
	}//end testADocumentWithoutAHashGetsOneOnItsFirstCheck()

	/**
	 * A run takes the next batch and starts over after the last one.
	 *
	 * @return void
	 */
	public function testWalksTheDocumentsInBatchesAndStartsOver(): void {
		$verifier = $this->verifier(register: $this->register());

		$verifier->verifyNext(batch: 3);
		self::assertSame(expected: 3, actual: $this->cursor);
		$verifier->verifyNext(batch: 3);
		self::assertSame(expected: 0, actual: $this->cursor);
	}//end testWalksTheDocumentsInBatchesAndStartsOver()
}//end class
