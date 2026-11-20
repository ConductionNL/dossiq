<?php

/**
 * RestrictedDocumentReaders: the reader fields of an unrestricted record, handlers that
 * cannot be read, which documents a case refresh rewrites, and an original that is gone.
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
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-openregisters-own-api-applies-the-same-rule-to-the-record
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\Zaakdossier\AccessExceptionValue;
use OCA\Dossiq\Service\Zaakdossier\DocumentCaseIds;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Service\Zaakdossier\FileRestrictionMirror;
use OCA\Dossiq\Service\Zaakdossier\RestrictedDocumentReaders;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\RestrictedDocumentReaders
 * @uses   \OCA\Dossiq\Service\Zaakdossier\AccessExceptionValue
 */
class RestrictedDocumentReadersEdgesTest extends TestCase {

	/**
	 * Case ids of a document and document ids of a case.
	 *
	 * @var DocumentCaseIds&MockObject
	 */
	private DocumentCaseIds&MockObject $caseIds;

	/**
	 * The record store.
	 *
	 * @var DocumentRecordStore&MockObject
	 */
	private DocumentRecordStore&MockObject $store;

	/**
	 * The file mirror.
	 *
	 * @var FileRestrictionMirror&MockObject
	 */
	private FileRestrictionMirror&MockObject $files;

	/**
	 * Fresh collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->caseIds = $this->createMock(DocumentCaseIds::class);
		$this->store   = $this->createMock(DocumentRecordStore::class);
		$this->files   = $this->createMock(FileRestrictionMirror::class);
	}//end setUp()

	/**
	 * The service, with a service account that runs each operation directly.
	 *
	 * @return RestrictedDocumentReaders The service.
	 */
	private function readers(): RestrictedDocumentReaders {
		$account = $this->createMock(BackgroundServiceAccount::class);
		$account->method('runAs')->willReturnCallback(static fn (callable $operation): mixed => $operation());

		return new RestrictedDocumentReaders(
			caseIds: $this->caseIds,
			store: $this->store,
			serviceAccount: $account,
			files: $this->files,
		);
	}//end readers()

	/**
	 * A record carrying an exception for the given people.
	 *
	 * @param string       $id    The record id.
	 * @param list<string> $users The people.
	 * @param array<string, mixed> $extra More fields.
	 *
	 * @return array<string, mixed> The record.
	 */
	private static function restricted(string $id, array $users, array $extra = []): array {
		return ['id' => $id, AccessExceptionValue::PROPERTY => ['users' => $users, 'groups' => ['g'], 'reason' => 'r']] + $extra;
	}//end restricted()

	/**
	 * A record without an exception, or with one naming nobody, is not restricted and no case is read.
	 *
	 * @return void
	 */
	public function testARecordWithoutAnExceptionIsNotRestricted(): void {
		$this->caseIds->expects(self::never())->method('caseIdsOf');

		$unrestricted = ['restricted' => false, 'accessUsers' => [], 'accessGroups' => [], 'accessHandlers' => []];
		self::assertSame(['id' => 'd', 'title' => 'x'] + $unrestricted, $this->readers()->withReaders(record: ['id' => 'd', 'title' => 'x']));

		$nobody = ['id' => 'd', AccessExceptionValue::PROPERTY => ['users' => [' '], 'groups' => []]];
		self::assertSame($nobody + $unrestricted, $this->readers()->withReaders(record: $nobody));
	}//end testARecordWithoutAnExceptionIsNotRestricted()

	/**
	 * The handlers of every case of the document are readers, once each, after the listed people.
	 *
	 * @return void
	 */
	public function testTheHandlersOfItsCasesAreAddedOnce(): void {
		$this->caseIds->method('caseIdsOf')->with('d')->willReturn(['c1', 'c2', 'c3']);
		$this->store->method('findCase')->willReturnMap(
			[
				['c1', ['assignee' => ' alice ']],
				['c2', ['assignee' => 'bob']],
				['c3', null],
			]
		);

		$result = $this->readers()->withReaders(record: self::restricted(id: 'd', users: ['bob', 'carol']));

		self::assertTrue($result['restricted']);
		self::assertSame(['bob', 'carol', 'alice'], $result['accessUsers']);
		self::assertSame(['g'], $result['accessGroups']);
		self::assertSame(['alice', 'bob'], $result['accessHandlers']);
	}//end testTheHandlersOfItsCasesAreAddedOnce()

	/**
	 * When a case cannot be read every handler is left out, and a record without id looks up nothing.
	 *
	 * @return void
	 */
	public function testHandlersThatCannotBeReadAreLeftOut(): void {
		$this->caseIds->expects(self::once())->method('caseIdsOf')->willReturn(['c1', 'c2']);
		$this->store->method('findCase')->willReturnCallback(
			static function (string $caseId): array {
				if ($caseId === 'c2') {
					throw new RuntimeException('register down');
				}

				return ['assignee' => 'alice'];
			}
		);

		$result = $this->readers()->withReaders(record: self::restricted(id: 'd', users: ['bob']));
		self::assertSame(['bob'], $result['accessUsers']);
		self::assertSame([], $result['accessHandlers']);

		$withoutId = $this->readers()->withReaders(record: [AccessExceptionValue::PROPERTY => ['users' => ['bob'], 'groups' => []]]);
		self::assertSame([], $withoutId['accessHandlers']);
	}//end testHandlersThatCannotBeReadAreLeftOut()

	/**
	 * A case refresh rewrites only restricted documents whose readers changed, and counts them.
	 *
	 * @return void
	 */
	public function testARefreshRewritesOnlyRestrictedDocumentsWhoseReadersChanged(): void {
		$this->caseIds->method('documentIdsOf')->with('case-1')->willReturn(['gone', 'open', 'same', 'stale']);
		$this->caseIds->method('caseIdsOf')->willReturn(['case-1']);
		$this->store->method('findCase')->willReturn(['assignee' => 'alice']);
		$this->store->method('findRecord')->willReturnCallback(
			static fn (string $recordId = '', int $fileId = 0): ?array => match ($recordId) {
				'open' => ['id' => 'open', 'restricted' => false],
				'same' => self::restricted(id: 'same', users: ['bob'], extra: ['restricted' => true, 'accessUsers' => ['bob', 'alice'], 'accessHandlers' => ['alice']]),
				'stale' => self::restricted(id: 'stale', users: ['bob'], extra: ['restricted' => true, 'accessUsers' => ['bob', 'old'], 'accessHandlers' => ['old']]),
				default => null,
			}
		);
		$this->store->expects(self::once())->method('saveRecord')->with(
			self::callback(static fn (array $record): bool => $record['id'] === 'stale' && $record['accessUsers'] === ['bob', 'alice'] && $record['accessHandlers'] === ['alice'])
		)->willReturn('stale');

		self::assertSame(1, $this->readers()->refreshCase(caseId: 'case-1'));
	}//end testARefreshRewritesOnlyRestrictedDocumentsWhoseReadersChanged()

	/**
	 * An original that cannot be found passes nothing on, and no file is restricted.
	 *
	 * @return void
	 */
	public function testAnOriginalThatIsGoneIsNotInherited(): void {
		$this->store->expects(self::once())->method('findRecord')->with('orig')->willReturn(null);
		$this->files->expects(self::never())->method('restrict');

		self::assertFalse($this->readers()->inheritFromId(originalId: 'orig', fileId: 9, madeBy: 'alice'));
	}//end testAnOriginalThatIsGoneIsNotInherited()
}//end class
