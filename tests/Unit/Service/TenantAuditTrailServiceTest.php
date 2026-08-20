<?php

/**
 * TenantAuditTrailService Unit Tests
 *
 * Proves the compliance contract of procest#223 finding 1: emit() writes a
 * DURABLE audit row (not merely a log line), and hardeningChecklist() never
 * attests a control it cannot back.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/tenant-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\TenantAuditTrailService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\TenantAuditTrailService
 */
class TenantAuditTrailServiceTest extends TestCase {
	private TenantAuditTrailService $svc;

	/**
	 * Records every durable audit row the service writes.
	 *
	 * @var object
	 */
	private object $auditSink;

	/**
	 * Build a service whose OpenRegister audit sink is available (or not).
	 *
	 * @param bool $orAvailable Whether the openregister app resolves.
	 *
	 * @return TenantAuditTrailService
	 */
	private function makeService(bool $orAvailable = true): TenantAuditTrailService {
		$appManager = $this->createMock(IAppManager::class);
		$installed = [];
		if ($orAvailable === true) {
			$installed = ['openregister'];
		}

		$appManager->method('getInstalledApps')->willReturn($installed);

		// Duck-typed stand-ins: the OpenRegister classes are not autoloaded in
		// the unit-test env, and the service resolves them by FQCN string.
		$objectService = new class {
			/**
			 * @param string $id Object id.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return object
			 */
			public function find(string $id, string $register = '', string $schema = ''): object {
				return (object)['uuid' => $id, 'register' => $register, 'schema' => $schema];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === 'OCA\\OpenRegister\\Db\\AuditTrailMapper') {
					return $this->auditSink;
				}

				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				throw new \RuntimeException('unknown service ' . $id);
			}
		);

		return new TenantAuditTrailService(
			logger: $this->createMock(LoggerInterface::class),
			appManager: $appManager,
			container: $container,
		);
	}//end makeService()

	protected function setUp(): void {
		parent::setUp();

		$this->auditSink = new class {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $rows = [];

			/**
			 * @param object $object Anchor entity.
			 * @param string $action Audit action.
			 * @param array<string, mixed> $context Audit context.
			 *
			 * @return object
			 */
			public function createAuditTrailEntry(object $object, string $action, array $context = []): object {
				$this->rows[] = ['object' => $object, 'action' => $action, 'context' => $context];
				return (object)['id' => count($this->rows)];
			}
		};

		$this->svc = $this->makeService();
	}//end setUp()

	public function testEmitNormalisesFields(): void {
		$entry = $this->svc->emit([
			'action' => 'case.create',
			'actor' => 'alice',
			'role' => 'case_handler',
			'resource' => 'case-uuid-1',
			'tenantId' => 'tenant-uuid-1',
			'ip' => '127.0.0.1',
			'ua' => 'TestAgent/1.0',
		]);

		$this->assertSame('case.create', $entry['action']);
		$this->assertSame('alice', $entry['actor']);
		$this->assertSame('tenant-uuid-1', $entry['tenantId']);
		$this->assertNotSame('', $entry['ts']);
		$this->assertSame([], $entry['bio']);
	}

	/**
	 * THE regression guard for the false attestation: emit() must write a real
	 * hash-chained audit row, not just log. Before the fix `rows` stayed empty.
	 */
	public function testEmitWritesADurableAuditRow(): void {
		$entry = $this->svc->emit([
			'action' => 'tenant.provisioned',
			'actor' => 'alice',
			'resource' => 'tenant:acme',
			'tenantId' => 'tenant-uuid-1',
		]);

		$this->assertTrue($entry['persisted'], 'emit() must report the durable row landed');
		$this->assertCount(1, $this->auditSink->rows, 'exactly one audit row must be written');

		$row = $this->auditSink->rows[0];
		$this->assertSame('procest.tenant.tenant.provisioned', $row['action']);
		$this->assertSame('tenant-uuid-1', $row['object']->uuid, 'row must anchor to the tenant entity');
		$this->assertSame('alice', $row['context']['actor']);
		$this->assertSame('tenant:acme', $row['context']['resource']);
	}

	/**
	 * A failed audit write must never break the caller's mutation, but it must
	 * be reported truthfully rather than silently claimed as success.
	 */
	public function testEmitFailsClosedWhenAuditSinkUnavailable(): void {
		$svc = $this->makeService(orAvailable: false);
		$entry = $svc->emit([
			'action' => 'tenant.provisioned',
			'tenantId' => 'tenant-uuid-1',
		]);

		$this->assertFalse($entry['persisted'], 'no sink means persisted:false, never a silent pass');
		$this->assertCount(0, $this->auditSink->rows);
	}

	public function testEmitWithoutTenantIdDoesNotClaimPersistence(): void {
		$entry = $this->svc->emit(['action' => 'tenant.provisioned']);
		$this->assertFalse($entry['persisted']);
		$this->assertCount(0, $this->auditSink->rows);
	}

	public function testSanitiseBioKeepsWhitelist(): void {
		$bio = $this->svc->sanitiseBio([
			'deviceId' => 'd-1',
			'geoLocation' => 'NL',
			'mfaVerified' => true,
			'sessionDuration' => 3600,
			'evilUnknown' => 'drop-me',
		]);
		$this->assertSame(['deviceId' => 'd-1', 'geoLocation' => 'NL', 'mfaVerified' => true, 'sessionDuration' => 3600], $bio);
	}

	public function testHardeningChecklistShape(): void {
		$items = $this->svc->hardeningChecklist();
		$this->assertGreaterThanOrEqual(7, count($items));
		foreach ($items as $i) {
			$this->assertArrayHasKey('key', $i);
			$this->assertArrayHasKey('description', $i);
			$this->assertArrayHasKey('evidence', $i);
			$this->assertArrayHasKey('status', $i);
			$this->assertContains($i['status'], ['pass', 'unverified'], 'status must be an honest enum');
		}
	}

	/**
	 * The attestation must reflect the LIVE audit state. With no sink the
	 * app cannot back the claim, so it must not make it.
	 */
	public function testAuditClaimFailsClosedWhenSinkUnavailable(): void {
		$items = $this->makeService(orAvailable: false)->hardeningChecklist();
		$byKey = array_column($items, 'status', 'key');
		$this->assertSame(
			'unverified',
			$byKey['audit_logged_mutations'],
			'with no durable audit sink the checklist MUST NOT attest audited mutations'
		);
	}

	public function testAuditClaimPassesWhenSinkAvailable(): void {
		$byKey = array_column($this->svc->hardeningChecklist(), 'status', 'key');
		$this->assertSame('pass', $byKey['audit_logged_mutations']);
	}

	/**
	 * No pen-test executes today; the checklist must say so rather than imply
	 * a verified control.
	 */
	public function testUnexecutedPenTestIsNotAttestedAsPassing(): void {
		$byKey = array_column($this->svc->hardeningChecklist(), 'status', 'key');
		$this->assertSame('unverified', $byKey['isolation_pen_test']);
	}
}
