<?php

/**
 * Every answer the case guard gives before it reads a case, allowed and refused.
 *
 * `hasCaseReadAccess()` and `hasCaseMutationAccess()` both refuse a caller
 * without a uid or a request without a case, let an administrator through
 * without reading the case, and treat an administrator check that throws as
 * no answer: they log it and go on to the case. Past that point they differ:
 * the mutation check admits only the assignee, the read check also admits a
 * caller named among the assignees. These pin both halves on both checks.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

class CaseAccessGuardCallerGoldenTest extends TestCase {

	/**
	 * Every log line, as "level message".
	 *
	 * @var array<int, string>
	 */
	public array $lines = [];

	/**
	 * How many times the case was read.
	 *
	 * @var int
	 */
	public int $reads = 0;

	/**
	 * The guard over a stored case and an administrator check.
	 *
	 * @param array<string, mixed>|null $case  The stored case, null when there is none.
	 * @param string                    $admin `yes`, `no` or `boom`.
	 *
	 * @return CaseAccessGuard The guard.
	 */
	private function guard(?array $case, string $admin): CaseAccessGuard {
		$test = $this;
		$objects = new class ($case, $test) {
			/**
			 * @param array<string, mixed>|null       $case The case.
			 * @param CaseAccessGuardCallerGoldenTest $test Where reads are counted.
			 */
			public function __construct(private ?array $case, private CaseAccessGuardCallerGoldenTest $test) {
			}

			/**
			 * The case, whatever is asked for.
			 *
			 * @param string $id       The uuid.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array<string, mixed>|null The case.
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): ?array {
				$this->test->reads++;
				return $this->case;
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getObjectGrantResolver')->willReturn(null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? '14' : ($key === 'case_schema' ? '24' : ''))
		);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isInGroup')->willReturn(false);
		$groups->method('isAdmin')->willReturnCallback(
			static function (string $uid) use ($admin): bool {
				if ($admin === 'boom') {
					throw new RuntimeException('groups down');
				}

				return $admin === 'yes';
			}
		);

		$logger = new class ($test) extends AbstractLogger {
			/**
			 * @param CaseAccessGuardCallerGoldenTest $test Where lines go.
			 */
			public function __construct(private CaseAccessGuardCallerGoldenTest $test) {
			}

			/**
			 * Keep a line.
			 *
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array<mixed>      $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->test->lines[] = (string)$level . ' ' . (string)$message;
			}
		};

		return new CaseAccessGuard(settingsService: $settings, groupManager: $groups, logger: $logger);
	}//end guard()

	/**
	 * A user double.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser The user.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * Both checks.
	 *
	 * @return array<string, array{0: string}> Per check: its method.
	 */
	public static function checks(): array {
		return ['read' => ['hasCaseReadAccess'], 'mutation' => ['hasCaseMutationAccess']];
	}//end checks()

	/**
	 * What a check answers.
	 *
	 * @param string                    $check  The method.
	 * @param array<string, mixed>|null $case   The stored case.
	 * @param string                    $admin  `yes`, `no` or `boom`.
	 * @param string                    $uid    The caller's uid.
	 * @param string                    $caseId The case asked about.
	 *
	 * @return bool The answer.
	 */
	private function answer(string $check, ?array $case, string $admin, string $uid, string $caseId = 'c-1'): bool {
		return $this->guard(case: $case, admin: $admin)->{$check}($caseId, $this->user(uid: $uid));
	}//end answer()

	/**
	 * No uid or no case is refused, even for an administrator, and nothing is read.
	 *
	 * @param string $check The method.
	 *
	 * @return void
	 *
	 * @dataProvider checks
	 */
	public function testNoCallerOrNoCaseIsRefused(string $check): void {
		self::assertFalse($this->answer(check: $check, case: ['assignee' => ''], admin: 'yes', uid: ''));
		self::assertFalse($this->answer(check: $check, case: ['assignee' => 'sanne'], admin: 'yes', uid: 'sanne', caseId: ''));
		self::assertSame(0, $this->reads);
		self::assertSame([], $this->lines);
	}//end testNoCallerOrNoCaseIsRefused()

	/**
	 * An administrator is let through without the case being read.
	 *
	 * @param string $check The method.
	 *
	 * @return void
	 *
	 * @dataProvider checks
	 */
	public function testAnAdministratorIsAllowed(string $check): void {
		self::assertTrue($this->answer(check: $check, case: null, admin: 'yes', uid: 'root'));
		self::assertSame(0, $this->reads);
	}//end testAnAdministratorIsAllowed()

	/**
	 * A failing administrator check is logged and grants nothing: the case decides.
	 *
	 * @param string $check The method.
	 *
	 * @return void
	 *
	 * @dataProvider checks
	 */
	public function testAFailingAdministratorCheckFallsThroughToTheCase(string $check): void {
		self::assertTrue($this->answer(check: $check, case: ['assignee' => 'sanne'], admin: 'boom', uid: 'sanne'));
		self::assertFalse($this->answer(check: $check, case: ['assignee' => 'piet'], admin: 'boom', uid: 'sanne'));
		self::assertFalse($this->answer(check: $check, case: null, admin: 'boom', uid: 'sanne'));
		self::assertSame(
			'warning Dossiq CaseAccessGuard: admin check failed: groups down',
			$this->lines[0]
		);
		self::assertSame(3, count(array_filter($this->lines, static fn (string $line): bool => str_contains($line, 'admin check failed'))));
	}//end testAFailingAdministratorCheckFallsThroughToTheCase()

	/**
	 * Past the administrator: the assignee is allowed by both, someone else by neither, a missing case by neither.
	 *
	 * @param string $check The method.
	 *
	 * @return void
	 *
	 * @dataProvider checks
	 */
	public function testTheCaseDecidesForEveryoneElse(string $check): void {
		self::assertTrue($this->answer(check: $check, case: ['assignee' => 'sanne'], admin: 'no', uid: 'sanne'));
		self::assertFalse($this->answer(check: $check, case: ['assignee' => 'piet'], admin: 'no', uid: 'sanne'));
		self::assertFalse($this->answer(check: $check, case: null, admin: 'no', uid: 'sanne'));
		self::assertFalse($this->answer(check: $check, case: ['assignee' => ''], admin: 'no', uid: 'sanne'));
	}//end testTheCaseDecidesForEveryoneElse()

	/**
	 * A caller named among the assignees may read the case but not change it.
	 *
	 * @return void
	 */
	public function testANamedAssigneeMayReadButNotChange(): void {
		$case = ['assignee' => 'piet', 'assignees' => ['sanne']];
		self::assertTrue($this->answer(check: 'hasCaseReadAccess', case: $case, admin: 'no', uid: 'sanne'));
		self::assertFalse($this->answer(check: 'hasCaseMutationAccess', case: $case, admin: 'no', uid: 'sanne'));
	}//end testANamedAssigneeMayReadButNotChange()
}//end class
