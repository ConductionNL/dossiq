<?php

/**
 * DocumentAccessExceptionWriter: checking the exception asked for, a record
 * without a file, and a history that is not a list.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-can-carry-an-access-exception-with-a-reason
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use InvalidArgumentException;
use OCA\Dossiq\Service\Zaakdossier\AccessExceptionValue;
use OCA\Dossiq\Service\Zaakdossier\DocumentAccessExceptionRule;
use OCA\Dossiq\Service\Zaakdossier\DocumentAccessExceptionWriter;
use OCA\Dossiq\Service\Zaakdossier\DocumentCaseIds;
use OCA\Dossiq\Service\Zaakdossier\FileRestrictionMirror;
use OCA\Dossiq\Service\Zaakdossier\RestrictedDocumentReaders;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\DocumentAccessExceptionWriter
 * @uses   \OCA\Dossiq\Service\Zaakdossier\DocumentAccessExceptionRule
 * @uses   \OCA\Dossiq\Service\Zaakdossier\AccessExceptionValue
 */
class DocumentAccessExceptionWriterValidationTest extends TestCase {

	/**
	 * Who exists as a person.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $users;

	/**
	 * Who exists as a group.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * The file mirror.
	 *
	 * @var FileRestrictionMirror&MockObject
	 */
	private FileRestrictionMirror&MockObject $files;

	/**
	 * The record saver.
	 *
	 * @var RestrictedDocumentReaders&MockObject
	 */
	private RestrictedDocumentReaders&MockObject $readers;

	/**
	 * Fresh collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->users   = $this->createMock(originalClassName: IUserManager::class);
		$this->groups  = $this->createMock(originalClassName: IGroupManager::class);
		$this->files   = $this->createMock(originalClassName: FileRestrictionMirror::class);
		$this->readers = $this->createMock(originalClassName: RestrictedDocumentReaders::class);
	}//end setUp()

	/**
	 * The writer over the real rule.
	 *
	 * @return DocumentAccessExceptionWriter The writer.
	 */
	private function writer(): DocumentAccessExceptionWriter {
		$rule = new DocumentAccessExceptionRule(
			caseIds: $this->createMock(originalClassName: DocumentCaseIds::class),
			cases: $this->createMock(originalClassName: CaseAccessGuard::class),
			groupManager: $this->groups,
		);

		return new DocumentAccessExceptionWriter(
			rule: $rule,
			files: $this->files,
			readers: $this->readers,
			userManager: $this->users,
			groupManager: $this->groups,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end writer()

	/**
	 * A user double.
	 *
	 * @return IUser&MockObject The user.
	 */
	private function user(): IUser&MockObject {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('handler');
		return $user;
	}//end user()

	/**
	 * People and groups are trimmed and deduplicated, and the reason trimmed.
	 *
	 * @return void
	 */
	public function testTheExceptionAskedForIsCleanedAndKept(): void {
		$this->users->expects(self::exactly(count: 2))->method('userExists')
			->willReturnCallback(static fn (string $uid): bool => in_array($uid, ['u1', '5'], true));
		$this->groups->expects(self::once())->method('groupExists')->with('g1')->willReturn(true);

		$result = $this->writer()->validated(
			input: ['users' => [' u1 ', 'u1', '', 5, ['nested']], 'groups' => 'g1', 'reason' => "  privacy  \n"]
		);

		self::assertSame(expected: ['users' => ['u1', '5'], 'groups' => ['g1'], 'reason' => 'privacy'], actual: $result);
	}//end testTheExceptionAskedForIsCleanedAndKept()

	/**
	 * An exception that names nobody is refused before anyone is looked up.
	 *
	 * @return void
	 */
	public function testAnExceptionNamingNobodyIsRefused(): void {
		$this->users->expects(self::never())->method('userExists');
		$this->groups->expects(self::never())->method('groupExists');

		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'Name at least one person or group');

		$this->writer()->validated(input: ['users' => ['  ', ''], 'reason' => 'why']);
	}//end testAnExceptionNamingNobodyIsRefused()

	/**
	 * An exception without a reason is refused, also when the reason is only spaces.
	 *
	 * @return void
	 */
	public function testAnExceptionWithoutAReasonIsRefused(): void {
		$this->users->expects(self::never())->method('userExists');

		foreach ([['users' => ['u1']], ['users' => ['u1'], 'reason' => "   \t"]] as $input) {
			try {
				$this->writer()->validated(input: $input);
				self::fail(message: 'An exception without a reason was accepted');
			} catch (InvalidArgumentException $e) {
				self::assertSame(expected: 'Give a reason for the exception', actual: $e->getMessage());
			}
		}
	}//end testAnExceptionWithoutAReasonIsRefused()

	/**
	 * An unknown person is named in the refusal, and groups are not looked at.
	 *
	 * @return void
	 */
	public function testAnUnknownPersonIsRefused(): void {
		$this->users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid === 'u1');
		$this->groups->expects(self::never())->method('groupExists');

		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'Unknown person: ghost');

		$this->writer()->validated(input: ['users' => ['u1', 'ghost'], 'groups' => ['g1'], 'reason' => 'why']);
	}//end testAnUnknownPersonIsRefused()

	/**
	 * An unknown group is named in the refusal.
	 *
	 * @return void
	 */
	public function testAnUnknownGroupIsRefused(): void {
		$this->groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid === 'g1');

		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'Unknown group: nobody');

		$this->writer()->validated(input: ['groups' => ['g1', 'nobody'], 'reason' => 'why']);
	}//end testAnUnknownGroupIsRefused()

	/**
	 * A record without a file: no file is restricted, and a failed save puts no file back.
	 *
	 * @return void
	 */
	public function testAFailedSaveOfARecordWithoutAFileTouchesNoFile(): void {
		$this->files->expects(self::never())->method('restrict');
		$this->files->expects(self::never())->method('lift');
		$this->readers->expects(self::once())->method('save')->willThrowException(new RuntimeException('store down'));

		try {
			$this->writer()->set(
				user: $this->user(),
				record: ['id' => 'doc-1'],
				exception: ['users' => ['u1'], 'groups' => [], 'reason' => 'why']
			);
			self::fail(message: 'A failed save was not reported');
		} catch (RuntimeException $e) {
			self::assertSame(expected: 'The access exception could not be saved', actual: $e->getMessage());
			self::assertSame(expected: 'store down', actual: $e->getPrevious()?->getMessage());
		}
	}//end testAFailedSaveOfARecordWithoutAFileTouchesNoFile()

	/**
	 * A history that is not a list is started afresh; non-array entries are dropped.
	 *
	 * @return void
	 */
	public function testAHistoryThatIsNotAListStartsAfresh(): void {
		$saved = [];
		$this->readers->method('save')->willReturnCallback(
			static function (array $record) use (&$saved): string {
				$saved[] = $record;
				return 'doc';
			}
		);

		$writer = $this->writer();
		$writer->set(
			user: $this->user(),
			record: ['id' => 'doc', AccessExceptionValue::HISTORY => 'garbage'],
			exception: ['users' => ['u1'], 'groups' => [], 'reason' => 'why']
		);
		$writer->set(
			user: $this->user(),
			record: ['id' => 'doc', AccessExceptionValue::HISTORY => ['loose', ['action' => 'set']]],
			exception: ['users' => ['u1'], 'groups' => [], 'reason' => 'why']
		);

		self::assertCount(expectedCount: 1, haystack: $saved[0][AccessExceptionValue::HISTORY]);
		self::assertSame(expected: 'set', actual: $saved[0][AccessExceptionValue::HISTORY][0]['action']);
		self::assertSame(expected: 'handler', actual: $saved[0][AccessExceptionValue::HISTORY][0]['by']);
		self::assertSame(expected: [0, 1], actual: array_keys($saved[1][AccessExceptionValue::HISTORY]));
		self::assertSame(expected: ['action' => 'set'], actual: $saved[1][AccessExceptionValue::HISTORY][0]);
	}//end testAHistoryThatIsNotAListStartsAfresh()
}//end class
