<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-only-a-caller-who-may-change-every-case-of-the-document-sets-the-exception
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DocumentAccessExceptionController;
use OCA\Dossiq\Service\Zaakdossier\DocumentAccessExceptionRule;
use OCA\Dossiq\Service\Zaakdossier\DocumentAccessExceptionWriter;
use OCA\Dossiq\Service\Zaakdossier\DocumentCaseWriteGuard;
use OCA\Dossiq\Service\Zaakdossier\DocumentCaseIds;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Service\Zaakdossier\RestrictedDocumentReaders;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\Zaakdossier\FileRestrictionMirror;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Setting, changing and removing the exception: who may, in which order, and what is recorded.
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-only-a-caller-who-may-change-every-case-of-the-document-sets-the-exception
 */
class DocumentAccessExceptionControllerTest extends TestCase {

	private DocumentCaseWriteGuard&MockObject $caseWrites;

	private InformatieobjectReader&MockObject $reader;

	private FileRestrictionMirror&MockObject $files;

	private DocumentRecordStore&MockObject $store;

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * What the store was asked to save.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * How many writes ran as the background service account.
	 *
	 * @var integer
	 */
	private int $ranAsServiceAccount = 0;

	private BackgroundServiceAccount&MockObject $serviceAccount;

	protected function setUp(): void {
		parent::setUp();
		$this->caseWrites = $this->createMock(DocumentCaseWriteGuard::class);
		$this->reader = $this->createMock(InformatieobjectReader::class);
		$this->files = $this->createMock(FileRestrictionMirror::class);
		$this->store = $this->createMock(DocumentRecordStore::class);
		$this->store->method('saveRecord')->willReturnCallback(
			function (array $record): string {
				$this->saved[] = $record;
				return (string)$record['id'];
			}
		);
	}//end setUp()

	/**
	 * The controller, acting as pieter.
	 *
	 * @return DocumentAccessExceptionController
	 */
	private function controller(): DocumentAccessExceptionController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid !== 'ghost');
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid !== 'nogroup');

		$rule = new DocumentAccessExceptionRule(
			caseIds: $this->createMock(DocumentCaseIds::class),
			cases: $this->createMock(CaseAccessGuard::class),
			groupManager: $groupManager
		);
		$caseIds = $this->createMock(DocumentCaseIds::class);
		$caseIds->method('caseIdsOf')->willReturn(['case-0082']);
		$this->store->method('findCase')->willReturn(['id' => 'case-0082', 'assignee' => 'hanna']);
		$this->serviceAccount = $this->createMock(BackgroundServiceAccount::class);
		$this->serviceAccount->method('runAs')->willReturnCallback(
			function (callable $operation): mixed {
				$this->ranAsServiceAccount++;
				return $operation();
			}
		);
		$writer = new DocumentAccessExceptionWriter(
			rule: $rule,
			files: $this->files,
			readers: new RestrictedDocumentReaders(caseIds: $caseIds, store: $this->store, serviceAccount: $this->serviceAccount, files: $this->files),
			userManager: $userManager,
			groupManager: $groupManager,
			logger: new NullLogger()
		);

		return new DocumentAccessExceptionController('dossiq', $request, $session, $this->caseWrites, $this->reader, $writer);
	}//end controller()

	/**
	 * The document on the board, with or without its exception.
	 *
	 * @param bool $withException Whether it carries the exception.
	 *
	 * @return array<string, mixed>
	 */
	private function offerte(bool $withException = false): array {
		$record = ['id' => 'doc-offerte', 'title' => 'Offerte verlichting.pdf', 'fileId' => 42];
		if ($withException === true) {
			$record['accessException'] = ['users' => ['pieter'], 'groups' => ['woo-team'], 'reason' => 'Prijzen', 'setBy' => 'pieter', 'setAt' => '2026-10-24T10:00:00+00:00'];
		}

		return $record;
	}//end offerte()

	public function testACallerWhoMayNotChangeTheCaseIsRefusedBeforeAnythingIsRead(): void {
		$this->caseWrites->method('refusedCase')->willReturn('case-b');
		$this->reader->expects($this->never())->method('loadReadable');
		$this->files->expects($this->never())->method('restrict');
		$this->params = ['users' => ['pieter'], 'reason' => 'Prijzen'];

		$response = $this->controller()->update('doc-offerte');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('case-b', $response->getData()['caseId']);
		$this->assertSame([], $this->saved);
	}//end testACallerWhoMayNotChangeTheCaseIsRefusedBeforeAnythingIsRead()

	public function testADocumentTheCallerMayNotReadAnswersTheReadersRefusal(): void {
		$this->reader->method('loadReadable')->willReturn(new JSONResponse(['error' => 'x'], 403));
		$this->params = ['users' => ['pieter'], 'reason' => 'Prijzen'];

		$this->assertSame(403, $this->controller()->update('doc-offerte')->getStatus());
		$this->assertSame([], $this->saved);
	}//end testADocumentTheCallerMayNotReadAnswersTheReadersRefusal()

	/**
	 * Inputs that are refused.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function invalidInputs(): array {
		return [
			'nobody named' => [['users' => [], 'groups' => [], 'reason' => 'Prijzen']],
			'no reason' => [['users' => ['pieter'], 'reason' => '  ']],
			'unknown person' => [['users' => ['ghost'], 'reason' => 'Prijzen']],
			'unknown group' => [['groups' => ['nogroup'], 'reason' => 'Prijzen']],
		];
	}//end invalidInputs()

	/**
	 * @dataProvider invalidInputs
	 *
	 * @param array<string, mixed> $params The request.
	 */
	public function testAnInvalidExceptionIs400AndStoresNothing(array $params): void {
		$this->reader->method('loadReadable')->willReturn($this->offerte());
		$this->files->expects($this->never())->method('restrict');
		$this->params = $params;

		$this->assertSame(400, $this->controller()->update('doc-offerte')->getStatus());
		$this->assertSame([], $this->saved);
	}//end testAnInvalidExceptionIs400AndStoresNothing()

	public function testSettingRestrictsTheFileFirstAndRecordsWhoWhenAndWhy(): void {
		$this->reader->method('loadReadable')->willReturn($this->offerte());
		$this->files->expects($this->once())->method('restrict')->with(42, ['pieter'], ['woo-team'], 'Bedrijfsgevoelige prijzen');
		$this->params = ['users' => ['pieter'], 'groups' => ['woo-team'], 'reason' => ' Bedrijfsgevoelige prijzen '];

		$response = $this->controller()->update('doc-offerte');

		$this->assertSame(200, $response->getStatus());
		$stored = $this->saved[0];
		$this->assertSame(['pieter'], $stored['accessException']['users']);
		$this->assertSame(['woo-team'], $stored['accessException']['groups']);
		$this->assertSame('Bedrijfsgevoelige prijzen', $stored['accessException']['reason']);
		$this->assertSame('pieter', $stored['accessException']['setBy']);
		$this->assertNotSame('', $stored['accessException']['setAt']);
		$this->assertCount(1, $stored['accessExceptionHistory']);
		$entry = $stored['accessExceptionHistory'][0];
		$this->assertSame('set', $entry['action']);
		$this->assertSame('pieter', $entry['by']);
		$this->assertNull($entry['old']);
		$this->assertSame($stored['accessException'], $entry['new']);
		$this->assertSame('Bedrijfsgevoelige prijzen', $entry['reason']);
	}//end testSettingRestrictsTheFileFirstAndRecordsWhoWhenAndWhy()

	public function testChangingRecordsTheOldAndTheNew(): void {
		$this->reader->method('loadReadable')->willReturn($this->offerte(withException: true));
		$this->params = ['users' => ['pieter', 'anna'], 'reason' => 'Ook Anna'];

		$this->controller()->update('doc-offerte');

		$entry = $this->saved[0]['accessExceptionHistory'][0];
		$this->assertSame('change', $entry['action']);
		$this->assertSame(['pieter'], $entry['old']['users']);
		$this->assertSame(['pieter', 'anna'], $entry['new']['users']);
	}//end testChangingRecordsTheOldAndTheNew()

	public function testWithoutAnOpenRegisterThatRestrictsFilesNothingIsStored(): void {
		$this->reader->method('loadReadable')->willReturn($this->offerte());
		$this->files->method('restrict')->willThrowException(new RuntimeException('OpenRegister on this instance cannot restrict a file'));
		$this->params = ['users' => ['pieter'], 'reason' => 'Prijzen'];

		$this->assertSame(503, $this->controller()->update('doc-offerte')->getStatus());
		$this->assertSame([], $this->saved);
	}//end testWithoutAnOpenRegisterThatRestrictsFilesNothingIsStored()

	public function testAFailedSaveFreesTheFileAgain(): void {
		$this->reader->method('loadReadable')->willReturn($this->offerte());
		$store = $this->createMock(DocumentRecordStore::class);
		$store->method('saveRecord')->willThrowException(new RuntimeException('save failed'));
		$this->store = $store;
		$this->files->expects($this->once())->method('restrict');
		$this->files->expects($this->once())->method('lift')->with(42);
		$this->params = ['users' => ['pieter'], 'reason' => 'Prijzen'];

		$this->assertSame(503, $this->controller()->update('doc-offerte')->getStatus());
	}//end testAFailedSaveFreesTheFileAgain()

	public function testRemovingFreesTheFileAndRecordsIt(): void {
		$this->reader->method('loadReadable')->willReturn($this->offerte(withException: true));
		$this->files->expects($this->once())->method('lift')->with(42);
		$this->params = ['reason' => 'Prijzen zijn openbaar'];

		$response = $this->controller()->destroy('doc-offerte');

		$this->assertTrue($response->getData()['removed']);
		$stored = $this->saved[0];
		$this->assertArrayNotHasKey('accessException', $stored);
		$entry = $stored['accessExceptionHistory'][0];
		$this->assertSame('remove', $entry['action']);
		$this->assertSame(['pieter'], $entry['old']['users']);
		$this->assertNull($entry['new']);
		$this->assertSame('Prijzen zijn openbaar', $entry['reason']);
	}//end testRemovingFreesTheFileAndRecordsIt()

	public function testRemovingWhereThereIsNoneChangesNothing(): void {
		$this->reader->method('loadReadable')->willReturn($this->offerte());
		$this->files->expects($this->never())->method('lift');

		$this->assertFalse($this->controller()->destroy('doc-offerte')->getData()['removed']);
		$this->assertSame([], $this->saved);
	}//end testRemovingWhereThereIsNoneChangesNothing()

	public function testTheExceptionIsSavedAsTheServiceAccountWithTheReadersOpenRegisterTests(): void {
		$this->reader->method('loadReadable')->willReturn($this->offerte());
		$this->params = ['users' => ['pieter'], 'groups' => ['woo-team'], 'reason' => 'Prijzen'];

		$this->controller()->update('doc-offerte');

		$this->assertSame(1, $this->ranAsServiceAccount);
		$stored = $this->saved[0];
		$this->assertTrue($stored['restricted']);
		$this->assertSame(['pieter', 'hanna'], $stored['accessUsers'], 'the case handler reads it in OpenRegister too');
		$this->assertSame(['woo-team'], $stored['accessGroups']);
		$this->assertSame(['hanna'], $stored['accessHandlers']);
	}//end testTheExceptionIsSavedAsTheServiceAccountWithTheReadersOpenRegisterTests()

	public function testRemovingClearsTheReadersOpenRegisterTests(): void {
		$this->reader->method('loadReadable')->willReturn($this->offerte(withException: true));

		$this->controller()->destroy('doc-offerte');

		$this->assertSame(1, $this->ranAsServiceAccount);
		$this->assertFalse($this->saved[0]['restricted']);
		$this->assertSame([], $this->saved[0]['accessUsers']);
		$this->assertSame([], $this->saved[0]['accessGroups']);
	}//end testRemovingClearsTheReadersOpenRegisterTests()
}//end class
