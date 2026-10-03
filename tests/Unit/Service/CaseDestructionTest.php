<?php

/**
 * Destroying a case is a second act, and it has a name behind it.
 *
 * Every test here is a refusal except one, which is the shape of the
 * requirement: deleting a case is ordinary and destroying one is not. The
 * right comes from the case type rather than from the instance, because
 * C-documents-20's clause is that destructive acts scoped to one named role
 * is a control we state nowhere.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Recycle\CaseDestructionService;
use OCA\Dossiq\Service\Recycle\CaseRecycleService;
use OCA\Dossiq\Service\Recycle\RetentionClocks;
use OCA\Dossiq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The destroying role, the two clocks and the open window, each a refusal.
 *
 * @covers \OCA\Dossiq\Service\Recycle\CaseDestructionService
 */
class CaseDestructionTest extends TestCase {

	/**
	 * The deleted case and its window.
	 *
	 * @var CaseRecycleService&MockObject
	 */
	private CaseRecycleService $recycle;

	/**
	 * The two clocks.
	 *
	 * @var RetentionClocks&MockObject
	 */
	private RetentionClocks $clocks;

	/**
	 * The case type behind the destroying role.
	 *
	 * @var CaseTypeResolver&MockObject
	 */
	private CaseTypeResolver $caseTypes;

	/**
	 * The bridge to OpenRegister.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The caller's groups.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The object service that would carry out the purge.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Wire a deleted case whose window has lapsed, handled by a plain user.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->recycle = $this->createMock(originalClassName: CaseRecycleService::class);
		$this->clocks = $this->createMock(originalClassName: RetentionClocks::class);
		$this->caseTypes = $this->createMock(originalClassName: CaseTypeResolver::class);
		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		$this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$user->method('getDisplayName')->willReturn('Behandelaar');
		$this->userSession->method('getUser')->willReturn($user);

		$this->recycle->method('findDeleted')->willReturn($this->entity());
		$this->recycle->method('window')->willReturn($this->window(lapsed: true));
		$this->clocks->method('clocksFor')->willReturn(['disagree' => false]);
		$this->caseTypes->method('effectiveCaseType')->willReturn(['destructionRole' => 'recordmanagers']);

		$this->objectService = new class {

			/**
			 * Whether a permanent delete was asked for.
			 *
			 * @var bool
			 */
			public bool $purged = false;

			/**
			 * Record the purge rather than performing one.
			 *
			 * @param string $uuid The object UUID.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param bool $permanent Whether the object goes for good.
			 *
			 * @return bool Always true.
			 */
			public function deleteObject(string $uuid, string $register, string $schema, bool $permanent = false): bool {
				$this->purged = $permanent;
				return true;
			}
		};

		$this->settingsService->method('getObjectService')->willReturn($this->objectService);
		$this->settingsService->method('getOpenRegisterClass')->willReturn(null);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				default => $default,
			}
		);
	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return CaseDestructionService
	 */
	private function service(): CaseDestructionService {
		return new CaseDestructionService(
			recycle: $this->recycle,
			clocks: $this->clocks,
			caseTypes: $this->caseTypes,
			settingsService: $this->settingsService,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end service()

	/**
	 * One soft-deleted case entity.
	 *
	 * @return object The entity.
	 */
	private function entity(): object {
		return new class {

			/**
			 * The entity's UUID.
			 *
			 * @return string The UUID.
			 */
			public function getUuid(): string {
				return 'case-9';
			}

			/**
			 * The entity's payload.
			 *
			 * @return array<string, mixed> The payload.
			 */
			public function getObject(): array {
				return [
					'caseType' => 'ct-bezwaar',
					'title' => 'Bezwaar tegen de aanslag',
				];
			}
		};
	}//end entity()

	/**
	 * One published window.
	 *
	 * @param bool $lapsed Whether the recovery window has passed.
	 *
	 * @return array<string, mixed> The window.
	 */
	private function window(bool $lapsed): array {
		return [
			'destroyableFrom' => '2026-01-01T00:00:00+00:00',
			'daysRemaining' => ($lapsed === true ? 0 : 12),
			'retentionDays' => 30,
			'retentionSource' => 'schema',
			'lapsed' => $lapsed,
		];
	}//end window()

	/**
	 * REQ-CRW-02: a handler without the role the case type declares is
	 * refused, and nothing is destroyed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testOnlyTheDeclaredRoleDestroys(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturn(false);

		try {
			$this->service()->destroy(caseId: 'case-9');
			$this->fail('A handler without the declared role destroyed a case.');
		} catch (RuntimeException $e) {
			$this->assertSame('destroy_role_missing', $e->getMessage());
		}

		$this->assertFalse($this->objectService->purged);
	}//end testOnlyTheDeclaredRoleDestroys()

	/**
	 * The role is read off the case type, so the handler who holds it for a
	 * bezwaar destroys one and the act reaches OpenRegister.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testTheDeclaredRoleDestroys(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $userId, string $group): bool => ($group === 'recordmanagers')
		);

		$result = $this->service()->destroy(caseId: 'case-9');

		$this->assertTrue($result['success']);
		$this->assertTrue($this->objectService->purged);
	}//end testTheDeclaredRoleDestroys()

	/**
	 * A case type that names no destroying role refuses every destruction, and
	 * says which rule refused rather than answering a bare Forbidden.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testAnUndeclaredRoleRefuses(): void {
		$this->caseTypes = $this->createMock(originalClassName: CaseTypeResolver::class);
		$this->caseTypes->method('effectiveCaseType')->willReturn([]);
		$this->groupManager->method('isAdmin')->willReturn(false);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('destroy_role_undeclared');

		$this->service()->destroy(caseId: 'case-9');
	}//end testAnUndeclaredRoleRefuses()

	/**
	 * REQ-CRW-02 and D-3: destroying is a SECOND act. A case that was never
	 * deleted cannot be destroyed straight out of the list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testALiveCaseCannotBeDestroyed(): void {
		$this->recycle = $this->createMock(originalClassName: CaseRecycleService::class);
		$this->recycle->method('findDeleted')->willReturn(null);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('case_not_deleted');

		$this->service()->destroy(caseId: 'case-live');
	}//end testALiveCaseCannotBeDestroyed()

	/**
	 * REQ-CRW-01: a case still inside its recovery window is not destroyed,
	 * because it can still come back. Waiving the window is explicit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testAnOpenWindowRefusesUntilItIsWaived(): void {
		$this->recycle = $this->createMock(originalClassName: CaseRecycleService::class);
		$this->recycle->method('findDeleted')->willReturn($this->entity());
		$this->recycle->method('window')->willReturn($this->window(lapsed: false));
		$this->groupManager->method('isAdmin')->willReturn(true);

		try {
			$this->service()->destroy(caseId: 'case-9');
			$this->fail('A case inside its recovery window was destroyed.');
		} catch (RuntimeException $e) {
			$this->assertSame('recovery_window_open', $e->getMessage());
		}

		$this->assertFalse($this->objectService->purged);
		$this->assertTrue($this->service()->destroy(caseId: 'case-9', waiveWindow: true)['success']);
		$this->assertTrue($this->objectService->purged);
	}//end testAnOpenWindowRefusesUntilItIsWaived()

	/**
	 * REQ-CRW-03: where the two clocks disagree nothing is destroyed, and a
	 * person decides which rule wins.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testDisagreeingClocksRefuseTheDestruction(): void {
		$this->clocks = $this->createMock(originalClassName: RetentionClocks::class);
		$this->clocks->method('clocksFor')->willReturn(['disagree' => true]);
		$this->groupManager->method('isAdmin')->willReturn(true);

		try {
			$this->service()->destroy(caseId: 'case-9');
			$this->fail('A case whose clocks disagree was destroyed.');
		} catch (RuntimeException $e) {
			$this->assertSame('retention_clocks_disagree', $e->getMessage());
		}

		$this->assertFalse($this->objectService->purged);
	}//end testDisagreeingClocksRefuseTheDestruction()

	/**
	 * The preview answers with the scope, the window and both clocks, and it
	 * destroys nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testThePreviewDestroysNothing(): void {
		$this->groupManager->method('isAdmin')->willReturn(true);

		$preview = $this->service()->preview(caseId: 'case-9');

		$this->assertSame('case-9', $preview['caseId']);
		$this->assertArrayHasKey('scope', $preview);
		$this->assertArrayHasKey('clocks', $preview);
		$this->assertSame('recordmanagers', $preview['destroyingRole']);
		$this->assertFalse($this->objectService->purged);
	}//end testThePreviewDestroysNothing()

	/**
	 * An instance that declares no destruction scope destroys exactly what it
	 * destroyed before this change: the case, and nothing else claimed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testAnUndeclaredScopeIsAValidAnswer(): void {
		$this->groupManager->method('isAdmin')->willReturn(true);

		$result = $this->service()->destroy(caseId: 'case-9');

		$this->assertSame([], $result['scope']['scope']);
		$this->assertTrue($result['scope']['destroyable']);
		$this->assertTrue($this->objectService->purged);
	}//end testAnUndeclaredScopeIsAValidAnswer()

	/**
	 * The record names who decided, when, and under which rule, and it is
	 * written before the object goes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testTheRecordNamesWhoDecided(): void {
		$this->groupManager->method('isAdmin')->willReturn(true);

		$record = $this->service()->destroy(caseId: 'case-9')['destruction'];

		$this->assertSame('behandelaar', $record['destroyedBy']);
		$this->assertSame('case-type-destroying-role', $record['rule']);
		$this->assertSame('case-9', $record['objectUuid']);
		$this->assertNotSame('', (string)$record['destroyedAt']);
	}//end testTheRecordNamesWhoDecided()

	/**
	 * The recovery window a case type declares is published, so the page can
	 * say how long a delete of this type can be undone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testTheCaseTypeDeclaresItsRecoveryWindow(): void {
		$this->caseTypes = $this->createMock(originalClassName: CaseTypeResolver::class);
		$this->caseTypes->method('effectiveCaseType')->willReturn(['recoveryWindowDays' => 90]);

		$this->assertSame(90, $this->service()->declaredWindowDays(case: ['caseType' => 'ct-bezwaar']));
		$this->assertNull($this->service()->declaredWindowDays(case: []));
	}//end testTheCaseTypeDeclaresItsRecoveryWindow()
}//end class
