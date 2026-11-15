<?php

/**
 * A case's always-available acts: read from its case type, each marked
 * available or not for this reader by its required role, nothing when the
 * case, its case type or the configuration is missing, and a storage
 * failure passed on rather than read as "no acts".
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\AlwaysAvailableActs;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\GuardResult;
use OCA\Dossiq\Service\Transitions\RoleGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\CaseType\AlwaysAvailableActs
 * @uses   \OCA\Dossiq\Service\CaseType\AlwaysAvailableActDeclaration
 * @uses   \OCA\Dossiq\Service\Workflow\WorkflowJsonProperty
 * @uses   \OCA\Dossiq\Service\Transitions\GuardResult
 */
class AlwaysAvailableActsTest extends TestCase {

	/**
	 * Stored objects by schema id and object id; an exception is thrown on read.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $objects = [];

	/**
	 * The reads, as [id, register, schema].
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	public array $reads = [];

	/**
	 * Configured values.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'reg', 'case_schema' => 'cases', 'case_type_schema' => 'types'];

	/**
	 * Whether an object service is installed.
	 *
	 * @var bool
	 */
	private bool $hasObjectService = true;

	/**
	 * The role guard.
	 *
	 * @var RoleGuard&MockObject
	 */
	private RoleGuard&MockObject $roles;

	/**
	 * A case on case type ct-1, which declares three acts.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->roles = $this->createMock(RoleGuard::class);
		$this->objects = [
			'cases' => ['c-1' => ['id' => 'c-1', 'caseType' => ['id' => 'ct-1']]],
			'types' => [
				'ct-1' => [
					'id' => 'ct-1',
					AlwaysAvailableActs::DECLARATION => json_encode(
						[
							['id' => 'note', 'label' => 'Notitie toevoegen'],
							['id' => 'withdraw', 'label' => 'Intrekken', 'description' => 'Trek de aanvraag in', 'requiredRole' => 'behandelaar'],
							['id' => 'escalate', 'label' => 'Escaleren', 'requiredRole' => 'teamleider'],
						]
					),
				],
			],
		];
	}//end setUp()

	/**
	 * The service over the current store and configuration.
	 *
	 * @return AlwaysAvailableActs
	 */
	private function acts(): AlwaysAvailableActs {
		$test = $this;
		$objectService = new class($test) {
			/**
			 * @param AlwaysAvailableActsTest $test The test.
			 */
			public function __construct(private AlwaysAvailableActsTest $test) {
			}

			/**
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return mixed
			 */
			public function find(string $id, mixed $register=null, mixed $schema=null): mixed {
				return $this->test->answer(id: $id, register: (string)$register, schema: (string)$schema);
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->hasObjectService === true ? $objectService : null);
		$settings->method('getConfigValue')->willReturnCallback(fn (string $key): string => ($this->config[$key] ?? ''));

		return new AlwaysAvailableActs(settings: $settings, roles: $this->roles);
	}//end acts()

	/**
	 * The object service's answer for one read.
	 *
	 * @param string $id       The id.
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 *
	 * @return mixed
	 */
	public function answer(string $id, string $register, string $schema): mixed {
		$this->reads[] = [$id, $register, $schema];
		$found = ($this->objects[$schema][$id] ?? null);
		if ($found instanceof \Throwable) {
			throw $found;
		}

		if ($found === null) {
			throw new DoesNotExistException('not found');
		}

		return $found;
	}//end answer()

	/**
	 * Each act is marked available or not by its role, with the guard's reason.
	 *
	 * @return void
	 */
	public function testEachActIsDecidedByItsRequiredRole(): void {
		$this->roles->method('evaluate')->willReturnCallback(
			static fn (array $guardConfig, array $case, string $userId): GuardResult => $guardConfig['allowedRoles'] === ['behandelaar']
				? new GuardResult(passed: true)
				: new GuardResult(passed: false, failureMessage: 'Alleen een teamleider mag escaleren.')
		);

		$acts = $this->acts()->forCase(caseId: ' c-1 ', userId: 'anna');

		self::assertSame(
			[
				['id' => 'note', 'label' => 'Notitie toevoegen', 'description' => '', 'alwaysAvailable' => true, 'available' => true, 'reason' => ''],
				['id' => 'withdraw', 'label' => 'Intrekken', 'description' => 'Trek de aanvraag in', 'alwaysAvailable' => true, 'available' => true, 'reason' => ''],
				['id' => 'escalate', 'label' => 'Escaleren', 'description' => '', 'alwaysAvailable' => true, 'available' => false, 'reason' => 'Alleen een teamleider mag escaleren.'],
			],
			$acts
		);
		self::assertSame([['c-1', 'reg', 'cases'], ['ct-1', 'reg', 'types']], $this->reads);
	}//end testEachActIsDecidedByItsRequiredRole()

	/**
	 * The guard is asked with the case and reader, only for acts with a role.
	 *
	 * @return void
	 */
	public function testTheGuardIsAskedOnlyForActsWithARole(): void {
		$this->roles->expects($this->exactly(2))->method('evaluate')
			->with($this->anything(), $this->objects['cases']['c-1'], 'anna')
			->willReturn(new GuardResult(passed: false));

		$acts = $this->acts()->forCase(caseId: 'c-1', userId: 'anna');

		self::assertSame([true, false, false], array_column($acts, 'available'));
		self::assertSame(['', '', ''], array_column($acts, 'reason'));
	}//end testTheGuardIsAskedOnlyForActsWithARole()

	/**
	 * A case type given as a plain id is read the same way.
	 *
	 * @return void
	 */
	public function testACaseTypeReferenceMayBeAPlainId(): void {
		$this->objects['cases']['c-1']['caseType'] = ' ct-1 ';
		$this->roles->method('evaluate')->willReturn(new GuardResult(passed: true));

		self::assertCount(3, $this->acts()->forCase(caseId: 'c-1', userId: 'anna'));
	}//end testACaseTypeReferenceMayBeAPlainId()

	/**
	 * Nothing is offered when the case, its case type or the configuration is missing.
	 *
	 * @return void
	 */
	public function testNothingIsOfferedWithoutCaseCaseTypeOrConfiguration(): void {
		self::assertSame([], $this->acts()->forCase(caseId: 'c-unknown', userId: 'anna'));
		self::assertSame([], $this->acts()->forCase(caseId: '  ', userId: 'anna'));

		$this->objects['cases']['c-1']['caseType'] = ['uuid' => ''];
		self::assertSame([], $this->acts()->forCase(caseId: 'c-1', userId: 'anna'));

		$this->objects['cases']['c-1']['caseType'] = 'ct-gone';
		self::assertSame([], $this->acts()->forCase(caseId: 'c-1', userId: 'anna'));

		$this->config['case_type_schema'] = '';
		$this->objects['cases']['c-1']['caseType'] = 'ct-1';
		self::assertSame([], $this->acts()->forCase(caseId: 'c-1', userId: 'anna'));

		$this->hasObjectService = false;
		self::assertSame([], $this->acts()->forCase(caseId: 'c-1', userId: 'anna'));
	}//end testNothingIsOfferedWithoutCaseCaseTypeOrConfiguration()

	/**
	 * A case type declaring no acts offers none.
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutDeclarationOffersNothing(): void {
		unset($this->objects['types']['ct-1'][AlwaysAvailableActs::DECLARATION]);

		self::assertSame([], $this->acts()->forCase(caseId: 'c-1', userId: 'anna'));
	}//end testACaseTypeWithoutDeclarationOffersNothing()

	/**
	 * A storage failure is passed on, not read as "no acts".
	 *
	 * @return void
	 */
	public function testAStorageFailureIsPassedOn(): void {
		$this->objects['types']['ct-1'] = new \RuntimeException('register unreachable');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('register unreachable');

		$this->acts()->forCase(caseId: 'c-1', userId: 'anna');
	}//end testAStorageFailureIsPassedOn()
}//end class
