<?php

/**
 * Golden test for DocumentAccessExceptionWriter::set and ::remove: the record
 * saved, the file calls in order, the log lines, and the rollback on a failed save.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\DocumentAccessExceptionRule;
use OCA\Dossiq\Service\Zaakdossier\DocumentAccessExceptionWriter;
use OCA\Dossiq\Service\Zaakdossier\FileRestrictionMirror;
use OCA\Dossiq\Service\Zaakdossier\RestrictedDocumentReaders;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

class DocumentAccessExceptionWriterGoldenTest extends TestCase {

	private array $calls = [];

	private array $logged = [];

	private const OLD = ['users' => ['u-old'], 'groups' => ['g-old'], 'reason' => 'oud', 'setBy' => 'x', 'setAt' => 'y'];

	private function writer(?array $old, bool $failSave = false, bool $failRestore = false): DocumentAccessExceptionWriter {
		$calls = &$this->calls;
		$rule  = $this->createMock(DocumentAccessExceptionRule::class);
		$rule->method('exceptionOf')->willReturn($old);
		$files = $this->createMock(FileRestrictionMirror::class);
		$files->method('restrict')->willReturnCallback(
			static function (int $fileId, array $users, array $groups, string $reason) use (&$calls, $failRestore): void {
				$calls[] = ['restrict', $fileId, $users, $groups, $reason];
				if ($failRestore === true && $users === ['u-old']) {
					throw new RuntimeException('restore down');
				}
			}
		);
		$files->method('lift')->willReturnCallback(
			static function (int $fileId) use (&$calls): void {
				$calls[] = ['lift', $fileId];
			}
		);
		$readers = $this->createMock(RestrictedDocumentReaders::class);
		$readers->method('save')->willReturnCallback(
			static function (array $record) use (&$calls, $failSave): string {
				// The clock is not frozen: every stamp of this write reads NOW.
				if (isset($record[DocumentAccessExceptionRule::PROPERTY]) === true) {
					$record[DocumentAccessExceptionRule::PROPERTY]['setAt'] = 'NOW';
				}

				foreach (($record[DocumentAccessExceptionRule::HISTORY] ?? []) as $i => $entry) {
					if (is_array($entry) === true && isset($entry['at']) === true && $entry['at'] !== 'then') {
						$record[DocumentAccessExceptionRule::HISTORY][$i]['at'] = 'NOW';
						if (isset($entry['new']['setAt']) === true) {
							$record[DocumentAccessExceptionRule::HISTORY][$i]['new']['setAt'] = 'NOW';
						}
					}
				}

				$calls[] = ['save', $record];
				if ($failSave === true) {
					throw new RuntimeException('store down');
				}

				return 'saved';
			}
		);
		$logged = &$this->logged;
		$logger = new class ($logged) extends AbstractLogger {
			private array $lines;

			public function __construct(array &$lines) {
				$this->lines = &$lines;
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [(string) $level, (string) $message, $context];
			}
		};

		return new DocumentAccessExceptionWriter(
			rule: $rule,
			files: $files,
			readers: $readers,
			userManager: $this->createMock(IUserManager::class),
			groupManager: $this->createMock(IGroupManager::class),
			logger: $logger
		);
	}//end writer()

	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		return $user;
	}//end user()

	public function testSettingAndChanging(): void {
		$exception = ['users' => ['u1'], 'groups' => ['g1'], 'reason' => 'privacy'];
		$record    = ['id' => 'doc-1', 'fileId' => '42', DocumentAccessExceptionRule::HISTORY => ['junk', ['action' => 'set', 'at' => 'then']]];

		$stored = $this->writer(null)->set(user: $this->user(), record: $record, exception: $exception);
		$this->assertSame(['users', 'groups', 'reason', 'setBy', 'setAt'], array_keys($stored));
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $stored['setAt']);
		$new = ['users' => ['u1'], 'groups' => ['g1'], 'reason' => 'privacy', 'setBy' => 'jan', 'setAt' => 'NOW'];
		$this->assertSame(
			[
				['restrict', 42, ['u1'], ['g1'], 'privacy'],
				[
					'save',
					[
						'id' => 'doc-1',
						'fileId' => '42',
						DocumentAccessExceptionRule::HISTORY => [
							['action' => 'set', 'at' => 'then'],
							['action' => 'set', 'by' => 'jan', 'at' => 'NOW', 'old' => null, 'new' => $new, 'reason' => 'privacy'],
						],
						DocumentAccessExceptionRule::PROPERTY => $new,
					],
				],
			],
			$this->calls
		);
		$this->assertSame([['info', 'Dossiq: access exception of a document changed', ['app' => 'dossiq', 'documentId' => 'doc-1', 'fileId' => 42]]], $this->logged);

		$this->calls = [];
		$this->writer(self::OLD)->set(user: $this->user(), record: ['id' => 'doc-2'], exception: $exception);
		$this->assertCount(1, $this->calls);
		$this->assertSame('change', $this->calls[0][1][DocumentAccessExceptionRule::HISTORY][0]['action']);
		$this->assertSame(self::OLD, $this->calls[0][1][DocumentAccessExceptionRule::HISTORY][0]['old']);
	}//end testSettingAndChanging()

	public function testAFailedSaveRestoresTheFile(): void {
		$exception = ['users' => ['u1'], 'groups' => [], 'reason' => 'r'];
		foreach ([[null, ['lift', 7]], [self::OLD, ['restrict', 7, ['u-old'], ['g-old'], 'oud']]] as [$old, $restore]) {
			$this->calls = [];
			try {
				$this->writer($old, failSave: true)->set(user: $this->user(), record: ['id' => 'd', 'fileId' => 7], exception: $exception);
				$this->fail('saved');
			} catch (RuntimeException $e) {
				$this->assertSame('The access exception could not be saved', $e->getMessage());
				$this->assertSame('store down', $e->getPrevious()?->getMessage());
			}

			$this->assertSame(['restrict', 'save', $restore[0]], array_column($this->calls, 0));
			$this->assertSame($restore, $this->calls[2]);
		}

		$this->calls = [];
		try {
			$this->writer(self::OLD, failSave: true, failRestore: true)->set(user: $this->user(), record: ['id' => 'd', 'fileId' => 7], exception: $exception);
		} catch (RuntimeException $e) {
			$this->assertSame('The access exception could not be saved', $e->getMessage());
		}

		$this->assertSame(
			[['error', 'Dossiq: the file restriction could not be put back after a failed save', ['app' => 'dossiq', 'fileId' => 7, 'exception' => 'restore down']]],
			$this->logged
		);
	}//end testAFailedSaveRestoresTheFile()

	public function testRemoving(): void {
		$this->assertFalse($this->writer(null)->remove(user: $this->user(), record: ['id' => 'd', 'fileId' => 3]));
		$this->assertSame([], $this->calls);

		$this->assertTrue($this->writer(self::OLD)->remove(user: $this->user(), record: ['id' => 'd', 'fileId' => 3, DocumentAccessExceptionRule::PROPERTY => self::OLD], reason: '  klaar '));
		$this->assertSame(
			[
				['lift', 3],
				['save', ['id' => 'd', 'fileId' => 3, DocumentAccessExceptionRule::HISTORY => [['action' => 'remove', 'by' => 'jan', 'at' => 'NOW', 'old' => self::OLD, 'new' => null, 'reason' => 'klaar']]]],
			],
			$this->calls
		);

		$this->calls = [];
		$this->writer(self::OLD)->remove(user: $this->user(), record: ['id' => 'd']);
		$this->assertSame(['save'], array_column($this->calls, 0));
		$this->assertSame('', $this->calls[0][1][DocumentAccessExceptionRule::HISTORY][0]['reason']);
	}//end testRemoving()
}//end class
