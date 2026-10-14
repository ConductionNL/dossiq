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
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Access\CaseRecordGrantsBackfill
 * @uses   \OCA\Dossiq\Service\Access\CaseRecordLookup
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses   \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 * @uses   \OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException
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
	 * The accounts that exist, with whether each is enabled.
	 *
	 * @var array<string, bool>
	 */
	private array $accounts = ['svc' => true, 'admin' => true, 'handler' => true];

	/**
	 * The uid the app config names as the background service account.
	 */
	private string $configured = 'svc';

	/**
	 * The members of the background service group.
	 *
	 * @var list<string>
	 */
	private array $serviceMembers = ['svc'];

	/**
	 * What OpenRegister's special rights were asked to give, or the error it throws.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $given = [];

	/**
	 * Whether OpenRegister has special rights at all.
	 */
	private bool $hasSpecialRights = true;

	/**
	 * Notices the administrators got.
	 */
	private int $adminNotices = 0;

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
			static fn (string $key, string $default = ''): string => ['register' => 'dossiq', 'case_schema' => 'case', 'case_type_schema' => 'caseType', 'k' => 'privateNote', 'k2' => 'reviewBatch'][$key] ?? $default
		);

		// The real store, and its shared listing must not be what the walk reads:
		// that listing answers one page and answers nothing when its read fails.
		$caseTypes = $this->getMockBuilder(CaseTypeStore::class)->setConstructorArgs([$settings])->onlyMethods(['everyCaseType'])->getMock();
		$caseTypes->expects(self::never())->method('everyCaseType');
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturnCallback(static fn (string $id): array => ['id' => $id]);

		$this->access = $this->createMock(CaseReviewerAccess::class);
		$this->access->method('declarationFor')->willReturnCallback(
			static fn (array $caseType): array => ['records' => match ($caseType['id']) {
				'type-open' => [],
				'type-b' => [['schemaKey' => 'k2'], ['schemaKey' => 'k']],
				default => [['schemaKey' => 'k']],
			}]
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
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $gid): bool => $gid === BackgroundServiceAccount::GROUP && in_array($uid, $this->serviceMembers, true)
		);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('getUsers')->willReturnCallback(fn (): array => [$this->user(uid: 'admin')]);
		$groups->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => $gid === 'admin' ? $adminGroup : null);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => isset($this->accounts[$uid]) ? $this->user(uid: $uid) : null);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $key === BackgroundServiceAccount::CONFIG_KEY ? $this->configured : $default
		);
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturnCallback(
			function (): INotification {
				$notification = $this->createMock(INotification::class);
				foreach (['setApp', 'setUser', 'setObject', 'setDateTime', 'setSubject'] as $setter) {
					$notification->method($setter)->willReturnSelf();
				}

				return $notification;
			}
		);
		$notifications->method('notify')->willReturnCallback(function (): void {
			$this->adminNotices++;
		});
		$serviceAccount = new BackgroundServiceAccount($appConfig, $users, $groups, $session, new NullLogger(), $notifications);

		$specialRights = new class($this) {
			public function __construct(private readonly CaseRecordGrantsBackfillTest $test) {
			}

			public function giveInEveryOrganisation(string $right, string $group, ?string $register = null, ?array $schemas = null): array {
				return $this->test->recordGive(['right' => $right, 'group' => $group, 'register' => $register, 'schemas' => $schemas]);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $name) use ($specialRights): object {
				if ($name !== CaseRecordGrantsBackfill::SPECIAL_RIGHTS || $this->hasSpecialRights === false) {
					throw new RuntimeException('no such service: '.$name);
				}

				return $specialRights;
			}
		);

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->backfill = new CaseRecordGrantsBackfill(
			settingsService: $settings,
			caseTypes: $caseTypes,
			resolver: $resolver,
			access: $this->access,
			serviceAccount: $serviceAccount,
			userSession: $session,
			container: $container,
			logger: $this->logger,
		);

		$this->register->seed('caseType', 'type-b', ['title' => 'b']);
		$this->register->seed('caseType', 'type-open', ['title' => 'keeps nothing private']);
		$this->register->seed('caseType', 'type-a', ['title' => 'a']);
		$this->register->seed('case', 'a-1', ['caseType' => 'type-a']);
		$this->register->seed('case', 'b-1', ['caseType' => 'type-b']);
		$this->register->seed('case', 'open-1', ['caseType' => 'type-open']);
	}//end setUp()

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($this->accounts[$uid] ?? true);

		return $user;
	}//end user()

	/**
	 * Note what OpenRegister was asked to give, as the walk sees it.
	 *
	 * @param array<string, mixed> $call The arguments.
	 *
	 * @return array{organisations: int, changed: int}
	 */
	public function recordGive(array $call): array {
		$this->given[] = $call + ['as' => $this->signedIn];

		return ['organisations' => 1, 'changed' => 1];
	}//end recordGive()

	public function testEveryCaseOfADeclaringCaseTypeIsReconciledAsTheServiceAccount(): void {
		$answer = $this->backfill->run();

		self::assertSame(
			['status' => 'reconciled', 'actor' => 'svc', 'cases' => 2, 'granted' => 4, 'revoked' => 2, 'refused' => 0, 'failed' => [], 'unfinished' => []],
			$answer
		);
		self::assertSame(
			[['case' => 'a-1', 'as' => 'svc'], ['case' => 'b-1', 'as' => 'svc']],
			$this->asked,
			'declaring case types in a fixed order, as the service account; a case type that keeps nothing private is not walked'
		);
		self::assertSame('', $this->signedIn, 'nobody was signed in before, nobody is after');
	}//end testEveryCaseOfADeclaringCaseTypeIsReconciledAsTheServiceAccount()

	public function testTheServiceGroupIsGivenTheSpecialRightOnTheDeclaredSchemasOnlyBeforeTheWalk(): void {
		$this->backfill->run();

		self::assertSame(
			[[
				'right' => 'grant_manage',
				'group' => 'dossiq-background-service',
				'register' => 'dossiq',
				'schemas' => ['privateNote', 'reviewBatch'],
				'as' => '',
			]],
			$this->given,
			'once, narrowed to this register and the schemas the case types keep private'
		);
	}//end testTheServiceGroupIsGivenTheSpecialRightOnTheDeclaredSchemasOnlyBeforeTheWalk()

	public function testASignedInPersonIsPutBackAfter(): void {
		$this->signedIn = 'handler';

		self::assertSame('svc', $this->backfill->run()['actor']);
		self::assertSame([['case' => 'a-1', 'as' => 'svc'], ['case' => 'b-1', 'as' => 'svc']], $this->asked);
		self::assertSame('handler', $this->signedIn);
	}//end testASignedInPersonIsPutBackAfter()

	/**
	 * @dataProvider unusableAccounts
	 */
	public function testWithoutAUsableServiceAccountNothingRunsItSaysSoAndNoAdministratorIsBorrowed(string $configured, array $members, array $accounts): void {
		$this->configured = $configured;
		$this->serviceMembers = $members;
		$this->accounts = $accounts;
		$this->logger->expects(self::atLeastOnce())->method('error');

		$answer = $this->backfill->run();

		self::assertSame('no-service-account', $answer['status']);
		self::assertSame('', $answer['actor']);
		self::assertSame([], $this->asked, 'no case was walked, as anybody');
		self::assertSame([], $this->given, 'no right was given');
		self::assertSame('', $this->signedIn, 'nobody, and certainly no administrator, was signed in');
		self::assertGreaterThan(0, $this->adminNotices, 'the administrators were told');
	}//end testWithoutAUsableServiceAccountNothingRunsItSaysSoAndNoAdministratorIsBorrowed()

	/**
	 * @return array<string, array{0: string, 1: list<string>, 2: array<string, bool>}>
	 */
	public static function unusableAccounts(): array {
		return [
			'none chosen, while an administrator exists' => ['', ['svc'], ['svc' => true, 'admin' => true]],
			'chosen but disabled' => ['svc', ['svc'], ['svc' => false, 'admin' => true]],
			'chosen but not in the group' => ['svc', [], ['svc' => true, 'admin' => true]],
			'an administrator chosen who is not in the group' => ['admin', ['svc'], ['svc' => true, 'admin' => true]],
		];
	}//end unusableAccounts()

	public function testWithoutTheSpecialRightInOpenRegisterNothingIsWalked(): void {
		$this->hasSpecialRights = false;

		$answer = $this->backfill->run();

		self::assertSame('no-grant-right', $answer['status']);
		self::assertSame([], $this->asked);
		self::assertSame('', $this->signedIn);
	}//end testWithoutTheSpecialRightInOpenRegisterNothingIsWalked()

	public function testTheAdministratorRouteWalksAsTheAdministratorAndGivesNoRight(): void {
		$this->signedIn = 'admin';

		$answer = $this->backfill->runAsCaller();

		self::assertSame('admin', $answer['actor']);
		self::assertSame([['case' => 'a-1', 'as' => 'admin'], ['case' => 'b-1', 'as' => 'admin']], $this->asked);
		self::assertSame([], $this->given);
		self::assertSame('admin', $this->signedIn);
	}//end testTheAdministratorRouteWalksAsTheAdministratorAndGivesNoRight()

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

	public function testACaseTypeBeyondTheFirstPageOfCaseTypesIsStillWalked(): void {
		for ($n = 0; $n < 1200; $n++) {
			$this->register->seed('caseType', 'filler-' . $n, ['title' => 'a case type with no cases']);
		}

		$this->register->seed('caseType', 'type-z', ['title' => 'the last one read']);
		$this->register->seed('case', 'z-1', ['caseType' => 'type-z']);

		$answer = $this->backfill->run();

		self::assertContains('z-1', array_column($this->asked, 'case'), 'a case type on the second page of case types is not skipped');
		self::assertSame('reconciled', $answer['status']);
	}//end testACaseTypeBeyondTheFirstPageOfCaseTypesIsStillWalked()

	public function testACaseTypeReadThatFailsIsNotARunOverNoCases(): void {
		$this->objectService = new class {

			/**
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return never
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): never {
				throw new RuntimeException('the store is down');
			}//end searchObjectsBySlug()
		};

		try {
			$this->backfill->run();
			self::fail('a run that could not read the case types answered as if it had none');
		} catch (RuntimeException $e) {
			self::assertSame('the store is down', $e->getMessage());
		}

		self::assertSame([], $this->asked);
		self::assertSame('', $this->signedIn, 'the session is put back');
	}//end testACaseTypeReadThatFailsIsNotARunOverNoCases()

	public function testACaseTypeWithMoreCasesThanOneRunWalksIsNamedUnfinished(): void {
		// A store that always answers a full page: the walk can never see the end.
		$this->objectService = new class {

			/**
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($schema === 'caseType') {
					return [['id' => 'type-b'], ['id' => 'type-open'], ['id' => 'type-a']];
				}

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
