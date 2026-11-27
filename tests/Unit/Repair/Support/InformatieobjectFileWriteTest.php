<?php

/**
 * InformatieobjectFileWrite Unit Tests
 *
 * One file becomes one informatieobject in the informatieobject schema, with
 * the owner as author (empty without an owner) and a SHA-256 of its content,
 * and a link to its case in the join schema when one is configured and the
 * store answered a saved object.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Support;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Repair\InformatieobjectTarget;
use OCA\Dossiq\Repair\Support\InformatieobjectFileWrite;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\Files\File;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for InformatieobjectFileWrite.
 *
 * @covers \OCA\Dossiq\Repair\Support\InformatieobjectFileWrite
 * @uses   \OCA\Dossiq\Repair\InformatieobjectTarget
 */
final class InformatieobjectFileWriteTest extends TestCase {

	/**
	 * An object store that records its saves and answers a uuid, or a bare value.
	 *
	 * @param bool $answersObjects Whether a save answers an object with a uuid.
	 *
	 * @return object
	 */
	private function store(bool $answersObjects = true): object {
		return new class($answersObjects) {
			/** @var array<int, array{object: array, register: string, schema: string}> */
			public array $saved = [];

			public function __construct(private bool $answersObjects) {
			}

			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions()): mixed {
				$this->saved[] = ['object' => $object, 'register' => (string)$options->target->register, 'schema' => (string)$options->target->schema];
				if ($this->answersObjects === false) {
					return [];
				}

				$uuid = 'io-' . count($this->saved);
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
	 * A file, with or without an owner.
	 *
	 * @param IUser|null $owner The owner.
	 *
	 * @return File
	 */
	private function file(?IUser $owner): File {
		$file = $this->createMock(originalClassName: File::class);
		$file->method('getName')->willReturn('brief.pdf');
		$file->method('getContent')->willReturn('brief');
		$file->method('getOwner')->willReturn($owner);
		$file->method('getSize')->willReturn(5);
		$file->method('getMimeType')->willReturn('application/pdf');
		$file->method('getMTime')->willReturn(1760270400);
		$file->method('getId')->willReturn(901);
		return $file;
	}//end file()

	/**
	 * The writer over settings naming the given join schema.
	 *
	 * @param string $joinSchema The join schema, '' for none.
	 *
	 * @return InformatieobjectFileWrite
	 */
	private function writer(string $joinSchema): InformatieobjectFileWrite {
		$settings = $this->createMock(originalClassName: SettingsServiceInterface::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'dossier_zaakinformatieobject_schema') ? $joinSchema : ''
		);
		return new InformatieobjectFileWrite(settingsService: $settings);
	}//end writer()

	/**
	 * The informatieobject goes to its schema and the link to the join schema, naming the case folder.
	 *
	 * @return void
	 */
	public function testTheInformatieobjectAndItsCaseLinkAreSaved(): void {
		$owner = $this->createMock(originalClassName: IUser::class);
		$owner->method('getDisplayName')->willReturn('Anna Bakker');
		$store = $this->store();

		$this->writer(joinSchema: '8')->write(
			target: new InformatieobjectTarget(objectService: $store, register: '23', schema: '7'),
			stored: ['case-1', $this->file(owner: $owner)]
		);

		$this->assertCount(2, $store->saved);
		[$info, $join] = $store->saved;
		$this->assertSame(['23', '7'], [$info['register'], $info['schema']]);
		$this->assertSame('Anna Bakker', $info['object']['auteur']);
		$this->assertSame(['brief.pdf', 'brief.pdf', 5, 'application/pdf', 901], [$info['object']['title'], $info['object']['fileName'], $info['object']['bestandsomvang'], $info['object']['format'], $info['object']['fileId']]);
		$this->assertSame(['sha256', hash('sha256', 'brief')], [$info['object']['integrity']['algorithm'], $info['object']['integrity']['value']]);
		$this->assertSame(['23', '8'], [$join['register'], $join['schema']]);
		$this->assertSame(['case-1', 'io-1'], [$join['object']['case'], $join['object']['informatieobject']]);
	}//end testTheInformatieobjectAndItsCaseLinkAreSaved()

	/**
	 * A file without an owner gets an empty author.
	 *
	 * @return void
	 */
	public function testAFileWithoutAnOwnerHasNoAuthor(): void {
		$store = $this->store();

		$this->writer(joinSchema: '')->write(
			target: new InformatieobjectTarget(objectService: $store, register: '23', schema: '7'),
			stored: ['case-1', $this->file(owner: null)]
		);

		$this->assertCount(1, $store->saved);
		$this->assertSame('', $store->saved[0]['object']['auteur']);
	}//end testAFileWithoutAnOwnerHasNoAuthor()

	/**
	 * A save that answers no object gives no uuid to link, so no link is written.
	 *
	 * @return void
	 */
	public function testNoLinkIsWrittenWithoutASavedUuid(): void {
		$store = $this->store(answersObjects: false);

		$this->writer(joinSchema: '8')->write(
			target: new InformatieobjectTarget(objectService: $store, register: '23', schema: '7'),
			stored: ['case-1', $this->file(owner: null)]
		);

		$this->assertCount(1, $store->saved);
		$this->assertSame('7', $store->saved[0]['schema']);
	}//end testNoLinkIsWrittenWithoutASavedUuid()
}//end class
