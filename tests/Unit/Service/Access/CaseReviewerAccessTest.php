<?php

/**
 * Case Reviewer Access Test
 *
 * Who reads and who changes the records a case type keeps private to the
 * case, computed from the case type's `reviewerAccess` declaration over one
 * in-memory register, with the OpenRegister side (ObjectReadGrants) doubled.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\Access\ObjectReadGrants;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Access\CaseReviewerAccess
 * @uses   \OCA\Dossiq\Exception\RefusedException
 * @uses   \OCA\Dossiq\Service\Access\CaseRecordLookup
 * @uses   \OCA\Dossiq\Service\Access\CaseRecordReaders
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CaseReviewerAccessTest extends TestCase {

	private const CASE_ID = '44444444-4444-4444-8444-444444444444';

	private const CASE_TYPE = '55555555-5555-4555-8555-555555555555';

	private InMemoryRegister $register;

	/** @var array<string, mixed> */
	private array $caseType = [];

	/** @var list<array{schema: string, uuid: string, readers: list<string>, group: string}> */
	private array $calls = [];

	private bool $available = true;

	private CaseTypeStore&MockObject $caseTypes;

	private CaseReviewerAccess $access;

	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'case_schema' => 'case',
				'assessment_schema_key' => 'assessment',
				'batch_schema_key' => 'batch',
				'review_schema_key' => 'review',
			][$key] ?? $default
		);

		$this->caseTypes = $this->getMockBuilder(CaseTypeStore::class)
			->setConstructorArgs([$settings])
			->onlyMethods(['everyCaseType'])
			->getMock();
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturnCallback(fn (string $id): array => $id === self::CASE_TYPE ? $this->caseType : []);

		$grants = $this->createMock(ObjectReadGrants::class);
		$grants->method('available')->willReturnCallback(fn (): bool => $this->available);
		$grants->method('reconcile')->willReturnCallback(
			function (string $schema, string $uuid, array $readers, string $group): array {
				$this->calls[] = ['schema' => $schema, 'uuid' => $uuid, 'readers' => $readers, 'group' => $group];
				return ['granted' => count($readers), 'revoked' => 0, 'refused' => 0];
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => $uid === 'coordinator' && $group === 'dossiq-coordinators');

		$this->access = new CaseReviewerAccess(
			settingsService: $settings,
			caseTypes: $this->caseTypes,
			resolver: $resolver,
			grants: $grants,
			groupManager: $groups,
		);

		$this->register->seed('case', self::CASE_ID, ['caseType' => self::CASE_TYPE, 'assignee' => 'handler']);
		$this->register->seed('assessment', 'a-1', ['caseRef' => self::CASE_ID, '@self' => ['owner' => 'reviewer1']]);
		$this->register->seed('assessment', 'a-2', ['caseRef' => self::CASE_ID, '@self' => ['owner' => 'reviewer2']]);
		$this->register->seed('assessment', 'a-other', ['caseRef' => 'another-case']);
		$this->register->seed('batch', 'b-1', ['case' => self::CASE_ID, 'assignee' => 'reviewer1']);
		$this->register->seed('batch', 'b-2', ['case' => self::CASE_ID, 'assignee' => 'reviewer2']);
	}//end setUp()

	/**
	 * The declaration the tests run with.
	 *
	 * @param bool $hideOthers Whether reviewers are barred from each other's records.
	 *
	 * @return void
	 */
	private function declare(bool $hideOthers = false): void {
		$this->caseType = [
			'reviewerAccess' => [
				'records' => [['schemaKey' => 'assessment_schema_key', 'caseField' => 'caseRef']],
				'reviewers' => [['schemaKey' => 'batch_schema_key', 'caseField' => 'case', 'userField' => 'assignee']],
				'hideOthers' => $hideOthers,
			],
		];
	}//end declare()

	public function testTheAssigneeAndEveryReviewerReadEveryRecordOfTheCase(): void {
		$this->declare();

		$answer = $this->access->reconcile(caseId: self::CASE_ID);

		self::assertSame('reconciled', $answer['status']);
		self::assertSame(['a-1', 'a-2'], array_column($this->calls, 'uuid'), 'a record of another case is never touched');
		self::assertSame(['handler', 'reviewer1', 'reviewer2'], $this->calls[0]['readers']);
		self::assertSame('dossiq-coordinators', $this->calls[0]['group'], 'the supervisors own the records by default');
		self::assertSame('assessment', $this->calls[0]['schema']);
		self::assertSame(6, $answer['granted']);
	}//end testTheAssigneeAndEveryReviewerReadEveryRecordOfTheCase()

	public function testHidingLeavesOnlyTheAssigneeAsReader(): void {
		$this->declare(hideOthers: true);

		$this->access->reconcile(caseId: self::CASE_ID);

		self::assertSame(['handler'], $this->calls[0]['readers']);
		self::assertSame(['handler'], $this->calls[1]['readers']);
	}//end testHidingLeavesOnlyTheAssigneeAsReader()

	public function testARecordSharedWithTheAssigneeOnlyReachesNoReviewer(): void {
		$this->declare();
		$this->caseType['reviewerAccess']['records'] = [
			['schemaKey' => 'assessment_schema_key', 'caseField' => 'caseRef', 'readers' => 'assignee'],
		];

		$this->access->reconcile(caseId: self::CASE_ID);

		self::assertSame([['handler'], ['handler']], array_column($this->calls, 'readers'));
	}//end testARecordSharedWithTheAssigneeOnlyReachesNoReviewer()

	public function testAReviewerReadsOnlyTheRecordThatNamesThem(): void {
		$this->declare();
		$this->caseType['reviewerAccess']['records'] = [
			['schemaKey' => 'batch_schema_key', 'caseField' => 'case', 'readers' => 'own', 'userField' => 'assignee'],
		];

		$this->access->reconcile(caseId: self::CASE_ID);

		self::assertSame(
			['b-1' => ['handler', 'reviewer1'], 'b-2' => ['handler', 'reviewer2']],
			array_column($this->calls, 'readers', 'uuid'),
			'the reviewer of one batch is no reader of the other'
		);
	}//end testAReviewerReadsOnlyTheRecordThatNamesThem()

	public function testARecordOfABatchIsReadByThatBatchsReviewer(): void {
		$this->declare();
		$this->register->seed('review', 'r-1', ['case' => self::CASE_ID, 'batch' => 'b-2']);
		$this->register->seed('review', 'r-2', ['case' => self::CASE_ID, 'batch' => '']);
		$this->caseType['reviewerAccess']['records'] = [
			['schemaKey' => 'review_schema_key', 'caseField' => 'case', 'readers' => 'own', 'reviewerRowField' => 'batch'],
		];

		$this->access->reconcile(caseId: self::CASE_ID);

		self::assertSame(
			['r-1' => ['handler', 'reviewer2'], 'r-2' => ['handler']],
			array_column($this->calls, 'readers', 'uuid'),
			'a record in no batch is read by the assignee alone'
		);
	}//end testARecordOfABatchIsReadByThatBatchsReviewer()

	public function testEveryRecordOfTheCaseIsReadWhoeverAsks(): void {
		$this->register->hiddenFromCaller = ['batch'];

		$rows = $this->access->recordsOf(schemaKey: 'batch_schema_key', caseField: 'case', caseId: self::CASE_ID);

		self::assertSame(['reviewer1', 'reviewer2'], array_column($rows, 'assignee'));
		self::assertSame([], $this->access->recordsOf(schemaKey: 'unknown_key', caseField: 'case', caseId: self::CASE_ID));
	}//end testEveryRecordOfTheCaseIsReadWhoeverAsks()

	public function testACaseTypeThatDeclaresNothingIsLeftAlone(): void {
		$answer = $this->access->reconcile(caseId: self::CASE_ID);

		self::assertSame('not-declared', $answer['status']);
		self::assertSame([], $this->calls);
	}//end testACaseTypeThatDeclaresNothingIsLeftAlone()

	public function testWithoutSharingItIsUnavailableAndWritesNothing(): void {
		$this->declare();
		$this->available = false;

		self::assertSame('unavailable', $this->access->reconcile(caseId: self::CASE_ID)['status']);
		self::assertSame('unavailable', $this->access->reconcileAll()['status']);
		self::assertSame([], $this->calls);
	}//end testWithoutSharingItIsUnavailableAndWritesNothing()

	public function testOnlyTheAuthorASupervisorOrAnAdministratorMayChangeARecord(): void {
		$this->declare();
		$record = $this->register->row('assessment', 'a-1');

		self::assertTrue($this->access->mayChange(caseId: self::CASE_ID, record: $record, userId: 'reviewer1'));
		self::assertFalse($this->access->mayChange(caseId: self::CASE_ID, record: $record, userId: 'reviewer2'));
		self::assertTrue($this->access->mayChange(caseId: self::CASE_ID, record: $record, userId: 'coordinator'));
		self::assertTrue($this->access->mayChange(caseId: self::CASE_ID, record: $record, userId: 'admin'));
		self::assertFalse($this->access->mayChange(caseId: self::CASE_ID, record: $record, userId: ''));
	}//end testOnlyTheAuthorASupervisorOrAnAdministratorMayChangeARecord()

	public function testAnUndeclaredCaseTypeKeepsEveryRecordChangeable(): void {
		self::assertTrue($this->access->mayChange(caseId: self::CASE_ID, record: $this->register->row('assessment', 'a-1'), userId: 'reviewer2'));
	}//end testAnUndeclaredCaseTypeKeepsEveryRecordChangeable()

	public function testReconcileAllRunsEveryCaseOfADeclaringCaseType(): void {
		$this->declare();
		$this->caseTypes->method('everyCaseType')->willReturn([['id' => self::CASE_TYPE], ['id' => 'undeclared-type']]);

		$answer = $this->access->reconcileAll();

		self::assertSame(1, $answer['cases']);
		self::assertSame(6, $answer['granted']);
		self::assertSame([], $answer['failed']);
	}//end testReconcileAllRunsEveryCaseOfADeclaringCaseType()

	public function testTheDeclarationIsNormalised(): void {
		$declaration = $this->access->declarationFor(caseType: ['reviewerAccess' => ['records' => [['schemaKey' => ' k '], ['schemaKey' => '']], 'supervisorGroup' => 'woo-leads']]);

		self::assertSame(
			[['schemaKey' => 'k', 'caseField' => 'case', 'readers' => 'all', 'userField' => '', 'reviewerRowField' => '']],
			$declaration['records']
		);
		self::assertSame('woo-leads', $declaration['supervisorGroup']);
		self::assertFalse($declaration['hideOthers']);
	}//end testTheDeclarationIsNormalised()
	public function testARefusalNamesOnlyTheReferenceAndTheRule(): void {
		$this->declare();
		$record = $this->register->row('assessment', 'a-1');

		self::assertNull($this->access->refusalFor(caseId: self::CASE_ID, record: $record, userId: 'reviewer1', reference: 'doc-1'));
		self::assertSame(
			['documentRef' => 'doc-1', 'errors' => ['assessment' => 'owned-by-another-reviewer']],
			$this->access->refusalFor(caseId: self::CASE_ID, record: $record, userId: 'reviewer2', reference: 'doc-1')
		);
	}//end testARefusalNamesOnlyTheReferenceAndTheRule()

	public function testNothingCreatedReconcilesNothing(): void {
		$this->declare();

		self::assertSame('nothing-created', $this->access->reconcileAfterCreate(caseId: self::CASE_ID, created: 0)['status']);
		self::assertSame([], $this->calls);
		self::assertSame('reconciled', $this->access->reconcileAfterCreate(caseId: self::CASE_ID, created: 1)['status']);
	}//end testNothingCreatedReconcilesNothing()

	public function testOnlyASupervisorHandsOnACaseWithPrivateRecords(): void {
		$this->declare();
		$case = ['id' => self::CASE_ID, 'caseType' => self::CASE_TYPE, 'assignee' => 'handler'];

		self::assertSame('reassign-needs-supervisor', $this->access->reassignRefusal(case: $case, userId: 'handler')['error'] ?? null);
		self::assertNull($this->access->reassignRefusal(case: $case, userId: 'coordinator'));
		self::assertNull($this->access->reassignRefusal(case: $case, userId: 'admin'));

		unset($this->register->rows['assessment']);
		self::assertNull($this->access->reassignRefusal(case: $case, userId: 'handler'), 'no private records, nothing to guard');
	}//end testOnlyASupervisorHandsOnACaseWithPrivateRecords()

	public function testAnUndeclaredCaseTypeChangesHandsAsBefore(): void {
		self::assertNull($this->access->reassignRefusal(case: ['id' => self::CASE_ID, 'caseType' => self::CASE_TYPE], userId: 'handler'));
	}//end testAnUndeclaredCaseTypeChangesHandsAsBefore()

	public function testAssertMayChangeRefusesWithoutNamingTheAuthor(): void {
		$this->declare();
		$record = $this->register->row('assessment', 'a-1');

		$this->access->assertMayChange(caseId: self::CASE_ID, record: $record, userId: 'reviewer1');

		try {
			$this->access->assertMayChange(caseId: self::CASE_ID, record: $record, userId: 'reviewer2');
			self::fail('Another reviewer must be refused.');
		} catch (\OCA\Dossiq\Exception\RefusedException $refusal) {
			self::assertSame('owned-by-another-reviewer', $refusal->getRule());
			self::assertSame(403, $refusal->getStatus());
			self::assertStringNotContainsString('reviewer1', $refusal->getSentence());
		}
	}//end testAssertMayChangeRefusesWithoutNamingTheAuthor()
}//end class
