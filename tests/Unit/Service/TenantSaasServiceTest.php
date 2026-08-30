<?php

/**
 * TenantSaasService Unit Tests
 *
 * Validates slug generation (lowercasing, hyphenation, 64-char cap) and
 * the lifecycle state machine (legal vs illegal transitions). Persistence
 * paths are not exercised here — they require a live OR; chain member 12
 * adds the integration tests.
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-02-tenant-crud-lifecycle/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Dossiq\Service\TenantAuditTrailService;
use OCA\Dossiq\Service\TenantSaasService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\TenantSaasService
 *
 * @uses \OCA\Dossiq\Service\TenantAuditTrailService
 */
class TenantSaasServiceTest extends TestCase {
	private TenantSaasService $service;

	/**
	 * Build a TenantAuditTrailService with no OpenRegister audit sink, so these
	 * tests assert the emit() WIRING (that a mutation emits an audit entry at
	 * all) without needing a live OR. The durable-row contract itself is proven
	 * in TenantAuditTrailServiceTest.
	 *
	 * @param LoggerInterface $logger Audit logger to observe.
	 *
	 * @return TenantAuditTrailService
	 */
	private function makeAudit(LoggerInterface $logger): TenantAuditTrailService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn([]);

		return new TenantAuditTrailService(
			logger: $logger,
			appManager: $appManager,
			container: $this->createMock(ContainerInterface::class),
		);
	}//end makeAudit()

	protected function setUp(): void {
		parent::setUp();
		$appManager = $this->createMock(IAppManager::class);
		$container = $this->createMock(ContainerInterface::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new TenantSaasService(
			appManager: $appManager,
			container: $container,
			logger: $logger,
			audit: $this->makeAudit($this->createMock(LoggerInterface::class)),
			userSession: $this->createMock(IUserSession::class),
		);
	}//end setUp()

	/**
	 * create() emits a `tenant.provisioned` audit row — the wiring that backs
	 * the hardening-checklist `audit_logged_mutations` claim (procest#223 #2).
	 *
	 * @return void
	 */
	public function testCreateEmitsProvisioningAuditRow(): void {
		$auditLogger = $this->createMock(LoggerInterface::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$service = $this->getMockBuilder(TenantSaasService::class)
			->setConstructorArgs([
				$this->createMock(IAppManager::class),
				$this->createMock(ContainerInterface::class),
				$this->createMock(LoggerInterface::class),
				$this->makeAudit($auditLogger),
				$userSession,
			])
			->onlyMethods(['slugExists', 'saveTenant'])
			->getMock();
		$service->method('slugExists')->willReturn(false);
		$service->method('saveTenant')->willReturn(['id' => 't-1', 'slug' => 'gemeente-x', 'status' => 'onboarding']);

		$auditLogger->expects($this->once())
			->method('info')
			->with(
				'Dossiq AUDIT',
				$this->callback(static fn (array $e): bool => $e['action'] === 'tenant.provisioned' && $e['actor'] === 'admin')
			);

		$service->create('Gemeente X', '12345678', 'basic');
	}//end testCreateEmitsProvisioningAuditRow()

	/**
	 * updateStatus() emits a `tenant.status_changed` audit row on every
	 * status mutation.
	 *
	 * @return void
	 */
	public function testUpdateStatusEmitsStatusChangeAuditRow(): void {
		$auditLogger = $this->createMock(LoggerInterface::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$service = $this->getMockBuilder(TenantSaasService::class)
			->setConstructorArgs([
				$this->createMock(IAppManager::class),
				$this->createMock(ContainerInterface::class),
				$this->createMock(LoggerInterface::class),
				$this->makeAudit($auditLogger),
				$userSession,
			])
			->onlyMethods(['getById', 'saveTenant'])
			->getMock();
		$service->method('getById')->willReturn(['id' => 't-1', 'status' => 'onboarding']);
		$service->method('saveTenant')->willReturn(['id' => 't-1', 'status' => 'active']);

		$auditLogger->expects($this->once())
			->method('info')
			->with(
				'Dossiq AUDIT',
				$this->callback(static fn (array $e): bool => $e['action'] === 'tenant.status_changed' && $e['actor'] === 'system')
			);

		$service->updateStatus('t-1', 'active');
	}//end testUpdateStatusEmitsStatusChangeAuditRow()

	/**
	 * Slugify lowercases and collapses non-alphanumerics into single hyphens.
	 *
	 * @return void
	 */
	public function testSlugifyBasic(): void {
		$this->assertSame('gemeente-amsterdam', $this->service->slugify('Gemeente Amsterdam'));
	}//end testSlugifyBasic()

	/**
	 * Slugify collapses multiple spaces, special chars, and trims hyphens.
	 *
	 * @return void
	 */
	public function testSlugifyCollapses(): void {
		$this->assertSame('foo-bar-baz', $this->service->slugify('  Foo!!  --Bar??  Baz  '));
	}//end testSlugifyCollapses()

	/**
	 * Slugify caps the slug at 64 chars.
	 *
	 * @return void
	 */
	public function testSlugifyEnforces64CharCap(): void {
		$long = str_repeat('a', 80);
		$slug = $this->service->slugify($long);
		$this->assertLessThanOrEqual(64, mb_strlen($slug));
	}//end testSlugifyEnforces64CharCap()

	/**
	 * Slugify handles unicode-letter input gracefully.
	 *
	 * @return void
	 */
	public function testSlugifyHandlesUnicode(): void {
		$slug = $this->service->slugify('Café Münster');
		$this->assertNotSame('', $slug);
		$this->assertStringContainsString('café', $slug);
	}//end testSlugifyHandlesUnicode()

	/**
	 * Legal transition: onboarding → active.
	 *
	 * @return void
	 */
	public function testAssertLegalTransitionOnboardingToActive(): void {
		$this->service->assertLegalTransition('onboarding', 'active');
		$this->expectNotToPerformAssertions();
	}//end testAssertLegalTransitionOnboardingToActive()

	/**
	 * Legal: active → suspended → active.
	 *
	 * @return void
	 */
	public function testAssertLegalTransitionActiveSuspendedRoundTrip(): void {
		$this->service->assertLegalTransition('active', 'suspended');
		$this->service->assertLegalTransition('suspended', 'active');
		$this->expectNotToPerformAssertions();
	}//end testAssertLegalTransitionActiveSuspendedRoundTrip()

	/**
	 * Legal: active → terminated.
	 *
	 * @return void
	 */
	public function testAssertLegalTransitionActiveToTerminated(): void {
		$this->service->assertLegalTransition('active', 'terminated');
		$this->expectNotToPerformAssertions();
	}//end testAssertLegalTransitionActiveToTerminated()

	/**
	 * Illegal: onboarding cannot jump to terminated.
	 *
	 * @return void
	 */
	public function testAssertLegalTransitionRejectsOnboardingToTerminated(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->assertLegalTransition('onboarding', 'terminated');
	}//end testAssertLegalTransitionRejectsOnboardingToTerminated()

	/**
	 * Illegal: terminated is terminal — cannot resurrect.
	 *
	 * @return void
	 */
	public function testAssertLegalTransitionRejectsTerminatedReactivation(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->assertLegalTransition('terminated', 'active');
	}//end testAssertLegalTransitionRejectsTerminatedReactivation()

	/**
	 * Illegal: no-op transition.
	 *
	 * @return void
	 */
	public function testAssertLegalTransitionRejectsNoOp(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->assertLegalTransition('active', 'active');
	}//end testAssertLegalTransitionRejectsNoOp()

	/**
	 * Illegal: unknown source status.
	 *
	 * @return void
	 */
	public function testAssertLegalTransitionRejectsUnknownSource(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->assertLegalTransition('purgatory', 'active');
	}//end testAssertLegalTransitionRejectsUnknownSource()

	/**
	 * Lifecycle graph: onboarding has exactly one outgoing transition.
	 *
	 * @return void
	 */
	public function testLifecycleGraphShape(): void {
		$g = $this->service->getLifecycleGraph();
		$this->assertSame(['active'], $g['onboarding']);
		$this->assertSame(['suspended', 'terminated'], $g['active']);
		$this->assertSame(['active', 'terminated'], $g['suspended']);
		$this->assertSame([], $g['terminated']);
	}//end testLifecycleGraphShape()

	/**
	 * Create rejects invalid tier without touching OR.
	 *
	 * @return void
	 */
	public function testCreateRejectsInvalidTier(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid tier');
		$this->service->create(name: 'Test', kvkNumber: '12345678', tier: 'gold');
	}//end testCreateRejectsInvalidTier()

	/**
	 * The dropped fields are not written back into a new tenant.
	 *
	 * `contractRef`, `isolationMode` and `dataResidency` were removed because
	 * nothing read them: `isolationMode` was a pure function of `tier`, and
	 * `dataResidency` was the constant `'nl'`. Writing a field no reader
	 * consults is invisible — it costs a column, appears in every admin detail
	 * view as a value somebody might act on, and nothing fails when it drifts.
	 *
	 * This asserts the payload rather than the schema, because the schema no
	 * longer declares them: a write of an undeclared property is exactly the
	 * kind of thing that gets silently accepted and then queried for later.
	 *
	 * @return void
	 */
	public function testCreateDoesNotWriteTheDroppedFields(): void {
		$service = $this->getMockBuilder(TenantSaasService::class)
			->setConstructorArgs([
				$this->createMock(IAppManager::class),
				$this->createMock(ContainerInterface::class),
				$this->createMock(LoggerInterface::class),
				$this->makeAudit($this->createMock(LoggerInterface::class)),
				$this->createMock(IUserSession::class),
			])
			->onlyMethods(['slugExists', 'saveTenant'])
			->getMock();
		$service->method('slugExists')->willReturn(false);

		$written = null;
		$service->method('saveTenant')->willReturnCallback(
			static function (array $tenant, ?string $uuid) use (&$written): array {
				$written = $tenant;

				return (['id' => 't-1'] + $tenant);
			}
		);

		$service->create('Gemeente X', '12345678', 'basic');

		$this->assertIsArray($written);
		foreach (['contractRef', 'isolationMode', 'dataResidency'] as $dropped) {
			$this->assertArrayNotHasKey(
				$dropped,
				$written,
				$dropped . ' has no reader and must not be written'
			);
		}

		// The fields that DO drive behaviour are still written: `tier` selects
		// the zaaktype templates to seed and the quota defaults to stamp.
		$this->assertSame('basic', $written['tier']);
		$this->assertSame('12345678', $written['kvkNumber']);
	}//end testCreateDoesNotWriteTheDroppedFields()

	/**
	 * The tenant schema no longer declares the dropped fields.
	 *
	 * Removing the write without removing the declaration would leave three
	 * properties visible in the admin detail view, permanently empty, looking
	 * like data that had simply never been filled in.
	 *
	 * @return void
	 */
	public function testTheTenantSchemaNoLongerDeclaresTheDroppedFields(): void {
		$register = json_decode(
			(string) file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);
		$this->assertIsArray($register);

		$tenant = null;
		$walk = static function (mixed $node) use (&$walk, &$tenant): void {
			if (is_array($node) === false || $tenant !== null) {
				return;
			}

			if (($node['slug'] ?? null) === 'tenant' && isset($node['properties']) === true) {
				$tenant = $node;
				return;
			}

			foreach ($node as $child) {
				$walk($child);
			}
		};
		$walk($register);

		$this->assertIsArray($tenant, 'the tenant schema must still exist');
		foreach (['contractRef', 'isolationMode', 'dataResidency'] as $dropped) {
			$this->assertArrayNotHasKey($dropped, $tenant['properties']);
		}

		$this->assertArrayHasKey('tier', $tenant['properties']);
		$this->assertArrayHasKey('kvkNumber', $tenant['properties']);
	}//end testTheTenantSchemaNoLongerDeclaresTheDroppedFields()

}//end class
