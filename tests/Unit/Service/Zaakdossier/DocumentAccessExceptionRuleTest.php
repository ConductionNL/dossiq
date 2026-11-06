<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-with-an-exception-is-readable-only-by-its-listed-people-the-cases-handler-and-administrators
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Listener\FileRestrictionAdmitListener;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Zaakdossier\AccessExceptionNames;
use OCA\Dossiq\Service\Zaakdossier\DocumentAccessExceptionRule;
use OCA\Dossiq\Service\Zaakdossier\DocumentCaseWriteGuard;
use OCA\Dossiq\Service\Zaakdossier\DossierUploadHandler;
use OCA\Dossiq\Service\Zaakdossier\FileDocumentLookup;
use OCP\IGroup;
use OCA\Dossiq\Service\Zaakdossier\DocumentCaseIds;
use OCP\EventDispatcher\Event;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Who reads a document that names its readers, and who OpenRegister is told to admit to its file.
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-with-an-exception-is-readable-only-by-its-listed-people-the-cases-handler-and-administrators
 */
class DocumentAccessExceptionRuleTest extends TestCase {

	private DocumentCaseIds&MockObject $store;

	private DocumentAccessExceptionRule $rule;

	/**
	 * The restricted document.
	 *
	 * @var array<string, mixed>
	 */
	private array $record = [
		'id' => 'doc-offerte',
		'accessException' => ['users' => ['pieter'], 'groups' => ['woo-team'], 'reason' => 'Prijzen'],
	];

	protected function setUp(): void {
		parent::setUp();
		$this->store = $this->createMock(DocumentCaseIds::class);
		$this->store->method('caseIdsOf')->willReturn(['case-1']);
		$cases = $this->createMock(CaseAccessGuard::class);
		$cases->method('hasCaseMutationAccess')->willReturnCallback(
			static fn (string $caseId, IUser $user): bool => $caseId === 'case-1' && in_array($user->getUID(), ['hanna', 'root'], true)
		);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $user): array => $user->getUID() === 'kees' ? ['woo-team'] : ['dossiq-medewerkers']
		);
		$this->rule = new DocumentAccessExceptionRule(caseIds: $this->store, cases: $cases, groupManager: $groupManager);
	}//end setUp()

	/**
	 * A user.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser&MockObject
	 */
	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	public function testADocumentWithoutAnExceptionIsNotNarrowed(): void {
		$this->assertTrue($this->rule->admits(user: $this->user('anna'), record: ['id' => 'doc-verzoek']));
	}//end testADocumentWithoutAnExceptionIsNotNarrowed()

	public function testAnExceptionThatNamesNobodyIsNone(): void {
		$record = ['id' => 'x', 'accessException' => ['users' => [' '], 'groups' => [], 'reason' => 'r']];

		$this->assertNull($this->rule->exceptionOf(record: $record));
		$this->assertTrue($this->rule->admits(user: $this->user('anna'), record: $record));
	}//end testAnExceptionThatNamesNobodyIsNone()

	public function testAReaderOfTheCaseWhoIsNotListedIsRefused(): void {
		$this->assertFalse($this->rule->admits(user: $this->user('anna'), record: $this->record));
	}//end testAReaderOfTheCaseWhoIsNotListedIsRefused()

	public function testAListedPersonAGroupMemberTheHandlerAndAnAdministratorAreAdmitted(): void {
		foreach (['pieter', 'kees', 'hanna', 'root'] as $uid) {
			$this->assertTrue($this->rule->admits(user: $this->user($uid), record: $this->record), $uid);
		}
	}//end testAListedPersonAGroupMemberTheHandlerAndAnAdministratorAreAdmitted()

	public function testAFailingCaseLookupRefuses(): void {
		$store = $this->createMock(DocumentCaseIds::class);
		$store->method('caseIdsOf')->willThrowException(new RuntimeException('OpenRegister down'));
		$rule = new DocumentAccessExceptionRule(
			caseIds: $store,
			cases: $this->createMock(CaseAccessGuard::class),
			groupManager: $this->createMock(IGroupManager::class)
		);

		$this->assertFalse($rule->admits(user: $this->user('hanna'), record: $this->record));
	}//end testAFailingCaseLookupRefuses()

	/**
	 * OpenRegister's event, as a double carrying the same methods.
	 *
	 * @param int    $fileId The file.
	 * @param string $uid    The person asking.
	 *
	 * @return Event
	 */
	private function admitEvent(int $fileId, string $uid): Event {
		if (class_exists(FileRestrictionAdmitListener::EVENT) === false) {
			eval('namespace OCA\OpenRegister\Event; class FileRestrictionAdmitEvent extends \OCP\EventDispatcher\Event {'
				. ' private bool $admitted = false;'
				. ' public function __construct(private int $fileId, private string $userId, private array $restriction = []) {}'
				. ' public function getFileId(): int { return $this->fileId; }'
				. ' public function getUserId(): string { return $this->userId; }'
				. ' public function admit(): void { $this->admitted = true; }'
				. ' public function isAdmitted(): bool { return $this->admitted; } }');
		}

		$class = FileRestrictionAdmitListener::EVENT;

		return new $class($fileId, $uid, ['users' => ['pieter'], 'groups' => ['woo-team']]);
	}//end admitEvent()

	public function testOpenRegisterIsToldToAdmitTheHandlerAndNobodyElse(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): IUser => $this->user($uid));
		$files = $this->createMock(FileDocumentLookup::class);
		$files->method('recordIdOfFile')->willReturnCallback(static fn (int $fileId): string => $fileId === 42 ? 'doc-offerte' : '');
		$listener = new FileRestrictionAdmitListener(files: $files, rule: $this->rule, userManager: $users);

		$handler = $this->admitEvent(fileId: 42, uid: 'hanna');
		$reader = $this->admitEvent(fileId: 42, uid: 'anna');
		$otherFile = $this->admitEvent(fileId: 99, uid: 'hanna');
		$listener->handle($handler);
		$listener->handle($reader);
		$listener->handle($otherFile);

		$this->assertTrue($handler->isAdmitted());
		$this->assertFalse($reader->isAdmitted());
		$this->assertFalse($otherFile->isAdmitted());
	}//end testOpenRegisterIsToldToAdmitTheHandlerAndNobodyElse()
	public function testTheCardGetsTheDisplayNamesOfTheExceptionAndNothingForOtherRows(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => $uid === 'pieter' ? 'Pieter Jansen' : null);
		$group = $this->createMock(IGroup::class);
		$group->method('getDisplayName')->willReturn('Woo-team');
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn($group);

		$rows = (new AccessExceptionNames(userManager: $users, groupManager: $groups))->withNames(documents: [$this->record, ['id' => 'doc-verzoek']]);

		$this->assertSame(['Pieter Jansen', 'Woo-team'], $rows[0]['accessExceptionNames']);
		$this->assertArrayNotHasKey('accessExceptionNames', $rows[1]);
	}//end testTheCardGetsTheDisplayNamesOfTheExceptionAndNothingForOtherRows()

	public function testTheListingSaysPerDocumentWhetherTheCallerMayChangeItAskingEachCaseOnce(): void {
		$store = $this->createMock(DocumentCaseIds::class);
		$store->method('caseIdsOf')->willReturnCallback(
			static fn (string $id): array => ['doc-a' => ['case-1'], 'doc-b' => ['case-1', 'case-2'], 'doc-c' => []][$id]
		);
		$uploads = $this->createMock(DossierUploadHandler::class);
		$uploads->expects($this->exactly(2))->method('hasCaseUploadAccess')->willReturnCallback(
			static fn (IUser $user, string $caseId): bool => $caseId === 'case-1'
		);

		$verdicts = (new DocumentCaseWriteGuard(caseIds: $store, uploads: $uploads))
			->writableDocuments(user: $this->user('hanna'), documentIds: ['doc-a', 'doc-b', 'doc-c']);

		$this->assertSame(['doc-a' => true, 'doc-b' => false, 'doc-c' => true], $verdicts);
	}//end testTheListingSaysPerDocumentWhetherTheCallerMayChangeItAskingEachCaseOnce()
}//end class
