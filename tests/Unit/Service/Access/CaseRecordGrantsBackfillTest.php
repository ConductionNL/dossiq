<?php

/**
 * The walk that reconciles the private records of every declaring case.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-on-upgrade-req-cra-012
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\CaseRecordGrantsBackfill;
use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Access\CaseRecordGrantsBackfill
 * @uses   \OCA\Dossiq\Service\Access\CaseRecordLookup
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CaseRecordGrantsBackfillTest extends TestCase {

	private InMemoryRegister $register;

	private CaseReviewerAccess&MockObject $access;

	private LoggerInterface&MockObject $logger;

	/**
	 * What reconcile() answers per case; a Throwable is thrown.
	 *
	 * @var array<string, mixed>
	 */
	private array $results = [];

	/**
	 * The cases reconcile() was asked for, with who was signed in at that moment.
	 *
	 * @var list<array{case: string, as: string}>
	 */
	private array $asked = [];

	/**
	 * Who is signed in; '' is nobody.
	 */
	private string $signedIn = '';

	/**
	 * The administrators, with whether each account is enabled.
	 *
	 * @var array<string, bool>
	 */
	private array $admins = ['zoe' => true, 'admin' => true, 'aaron' => false];

	/**
	 * The object service the settings answer; the in-memory register unless a test replaces it.
	 */
	private object $objectService;

	private CaseRecordGrantsBackfill $backfill;

	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$this->register->pages = true;
		$this->objectService = $this->register;
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturnCallback(fn (): object => $this->objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? $default
		);

		$caseTypes = $this->getMockBuilder(CaseTypeStore::class)->setConstructorArgs([$settings])->onlyMethods(['everyCaseType'])->getMock();
		$caseTypes->method('everyCaseType')->willReturn([['id' => 'type-b'], ['id' => 'type-open'], ['id' => 'type-a'], ['name' => 'a row without an id']]);
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturnCallback(static fn (string $id): array => ['id' => $id]);

		$this->access = $this->createMock(CaseReviewerAccess::class);
		$this->access->method('declarationFor')->willReturnCallback(
			static fn (array $caseType): array => ['records' => ($caseType['id'] === 'type-open' ? [] : [['schemaKey' => 'k']])]
		);
		$this->access->method('reconcile')->willReturnCallback(
			function (string $caseId): array {
				$this->asked[] = ['case' => $caseId, 'as' => $this->signedIn];
				$result = ($this->results[$caseId] ?? ['status' => 'reconciled', 'granted' => 2, 'revoked' => 1, 'refused' => 0]);
				if ($result instanceof RuntimeException) {
					throw $result;
				}

				return $result;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->signedIn === '' ? null : $this->user(uid: $this->signedIn));
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->signedIn = (string)$user?->getUID();
			}
		);
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturnCallback(fn (): array => array_map(fn (string $uid): IUser => $this->user(uid: $uid), array_keys($this->admins)));
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => $gid === 'admin' ? $group : null);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => isset($this->admins[$uid]));

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->backfill = new CaseRecordGrantsBackfill(
			settingsService: $settings,
			caseTypes: $caseTypes,
			resolver: $resolver,
			access: $this->access,
			groupManager: $groups,
			userSession: $session,
			logger: $this->logger,
		);

		$this->register->seed('case', 'a-1', ['caseType' => 'type-a']);
		$this->register->seed('case', 'b-1', ['caseType' => 'type-b']);
		$this->register->seed('case', 'open-1', ['caseType' => 'type-open']);
	}//end setUp()

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($this->admins[$uid] ?? true);

		return $user;
	}//end user()

	public function testEveryCaseOfADeclaringCaseTypeIsReconciledAsAnAdministrator(): void {
		$answer = $this->backfill->run();

		self::assertSame(
			['status' => 'reconciled', 'actor' => 'admin', 'cases' => 2, 'granted' => 4, 'revoked' => 2, 'refused' => 0, 'failed' => [], 'unfinished' => []],
			$answer
		);
		self::assertSame(
			[['case' => 'a-1', 'as' => 'admin'], ['case' => 'b-1', 'as' => 'admin']],
			$this->asked,
			'declaring case types in a fixed order, as the first enabled administrator; a case type that keeps nothing private is not walked'
		);
		self::assertSame('', $this->signedIn, 'nobody was signed in before, nobody is after');
	}//end testEveryCaseOfADeclaringCaseTypeIsReconciledAsAnAdministrator()

	public function testASignedInAdministratorIsTheActorAndStaysSignedIn(): void {
		$this->signedIn = 'zoe';

		self::assertSame('zoe', $this->backfill->run()['actor']);
		self::assertSame('zoe', $this->signedIn);
	}//end testASignedInAdministratorIsTheActorAndStaysSignedIn()

	public function testASignedInNonAdministratorIsPutBackAfter(): void {
		$this->signedIn = 'handler';

		self::assertSame('admin', $this->backfill->run()['actor']);
		self::assertSame('handler', $this->signedIn);
	}//end testASignedInNonAdministratorIsPutBackAfter()

	public function testWithoutAnEnabledAdministratorNothingRunsAndItSaysSo(): void {
		$this->admins = ['aaron' => false];

		$answer = $this->backfill->run();

		self::assertSame('no-administrator', $answer['status']);
		self::assertSame('', $answer['actor']);
		self::assertSame([], $this->asked);
	}//end testWithoutAnEnabledAdministratorNothingRunsAndItSaysSo()

	public function testItWalksEveryPageOfCases(): void {
		for ($n = 2; $n <= 250; $n++) {
			$this->register->seed('case', 'a-' . $n, ['caseType' => 'type-a']);
		}

		$answer = $this->backfill->run();

		self::assertSame(251, $answer['cases'], '250 cases of one type over three pages, and one of the other');
		self::assertSame('reconciled', $answer['status']);
		self::assertCount(251, array_unique(array_column($this->asked, 'case')), 'no case twice, none skipped');
	}//end testItWalksEveryPageOfCases()

	public function testACaseThatThrowsIsNamedAndTheWalkGoesOn(): void {
		$this->results['a-1'] = new RuntimeException('the store is down');
		$this->logger->expects(self::once())->method('error')->with(self::stringContains('could not be reconciled'), self::callback(static fn (array $context): bool => $context['case'] === 'a-1'));

		$answer = $this->backfill->run();

		self::assertSame('incomplete', $answer['status']);
		self::assertSame(['a-1'], $answer['failed']);
		self::assertSame(2, $answer['cases']);
		self::assertSame(2, $answer['granted'], 'the case after it was still reconciled');
		self::assertSame('', $this->signedIn, 'the session is put back');
	}//end testACaseThatThrowsIsNamedAndTheWalkGoesOn()

	public function testARefusedRecordMakesTheRunIncomplete(): void {
		$this->results['b-1'] = ['status' => 'reconciled', 'granted' => 1, 'revoked' => 0, 'refused' => 3];

		$answer = $this->backfill->run();

		self::assertSame('incomplete', $answer['status']);
		self::assertSame(['b-1'], $answer['failed']);
		self::assertSame(3, $answer['refused']);
	}//end testARefusedRecordMakesTheRunIncomplete()

	public function testWithoutSharingItStopsAtOnceAndIsNotASuccess(): void {
		$this->results['a-1'] = ['status' => 'unavailable', 'granted' => 0, 'revoked' => 0, 'refused' => 0];

		$answer = $this->backfill->run();

		self::assertSame('unavailable', $answer['status']);
		self::assertSame(0, $answer['cases']);
		self::assertCount(1, $this->asked, 'the second case is not asked');
		self::assertSame('', $this->signedIn);
	}//end testWithoutSharingItStopsAtOnceAndIsNotASuccess()

	public function testACaseTypeWithMoreCasesThanOneRunWalksIsNamedUnfinished(): void {
		// A store that always answers a full page: the walk can never see the end.
		$this->objectService = new class {

			/**
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return array_fill(0, (int)$filters['_limit'], ['id' => 'case-of-' . $filters['caseType'], 'caseType' => $filters['caseType']]);
			}//end searchObjectsBySlug()
		};
		$this->logger->expects(self::exactly(2))->method('error')->with(self::stringContains('NOT reconciled'));

		$answer = $this->backfill->run();

		self::assertSame('incomplete', $answer['status']);
		self::assertSame(['type-a', 'type-b'], $answer['unfinished']);
		self::assertSame((2 * CaseRecordGrantsBackfill::PAGE * CaseRecordGrantsBackfill::MAX_PAGES), $answer['cases']);
	}//end testACaseTypeWithMoreCasesThanOneRunWalksIsNamedUnfinished()
}//end class
