<?php

/**
 * Golden test for the tenant membership, role and mandate lookups.
 *
 * Pins, for each lookup, the query it sends, what it answers on every row
 * shape, and what it logs and throws when the store cannot be read: the
 * membership list, the role inside a tenant, the active mandate window and
 * the mandate decision built on top of them.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\TenantAuthenticationService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden tests for TenantAuthenticationService's lookups.
 *
 * @covers \OCA\Dossiq\Service\TenantAuthenticationService
 * @uses \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
class TenantAuthenticationLookupsGoldenTest extends TestCase {

	/**
	 * The findAll() configs sent, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The log lines written, as [level, message, context].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logged = [];

	/**
	 * A service whose store answers per schema, or throws when the answer is an exception.
	 *
	 * @param array<string, mixed> $bySchema The rows (or exception) per schema.
	 *
	 * @return TenantAuthenticationService The service.
	 */
	private function auth(array $bySchema): TenantAuthenticationService {
		$queries = &$this->queries;
		$objectService = new class($bySchema, $queries) {
			/**
			 * @param array<string, mixed>             $bySchema The answers.
			 * @param array<int, array<string, mixed>> $queries  The shared query log.
			 */
			public function __construct(private array $bySchema, private array &$queries) {
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function findAll(array $config=[]): array {
				$this->queries[] = $config;
				$answer = ($this->bySchema[$config['filters']['schema'] ?? ''] ?? []);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		};

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$logged = &$this->logged;
		$logger = new class($logged) extends AbstractLogger {
			/**
			 * @param array<int, array<int, mixed>> $logged The shared log.
			 */
			public function __construct(private array &$logged) {
			}

			/**
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->logged[] = [$level, (string)$message, $context];
			}
		};

		return new TenantAuthenticationService($appManager, $container, $logger);
	}//end auth()

	/**
	 * The membership list: trimmed, de-duplicated, empty refs skipped, one scoped query.
	 *
	 * @return void
	 */
	public function testTheMembershipListAndItsQuery(): void {
		$rows = [
			['tenantRef' => ' tenant-a '],
			['tenantRef' => ''],
			['userRef' => 'alice'],
			['tenantRef' => 'tenant-b'],
			['tenantRef' => 'tenant-a'],
		];

		self::assertSame(['tenant-a', 'tenant-b'], $this->auth(['tenantUser' => $rows])->listTenantsForUser('alice'));
		self::assertSame(
			[
				[
					'filters' => ['register' => 'dossiq', 'schema' => 'tenantUser', 'userRef' => 'alice'],
					'limit' => 100,
					'offset' => 0,
				],
			],
			$this->queries
		);

		$this->queries = [];
		self::assertSame([], $this->auth(['tenantUser' => $rows])->listTenantsForUser(''));
		self::assertSame([], $this->queries);
		self::assertSame([], $this->auth(['tenantUser' => []])->listTenantsForUser('alice'));
	}//end testTheMembershipListAndItsQuery()

	/**
	 * A membership lookup that fails is logged and thrown, never read as "member of nothing".
	 *
	 * @return void
	 */
	public function testAFailedMembershipLookupIsThrown(): void {
		$failure = new RuntimeException('store down');
		try {
			$this->auth(['tenantUser' => $failure])->listTenantsForUser('alice');
			self::fail('The lookup failure must be thrown.');
		} catch (RuntimeException $e) {
			self::assertSame($failure, $e);
		}

		self::assertSame(
			[['error', 'Dossiq: listTenantsForUser lookup failed (fail-closed)', ['userId' => 'alice', 'exception' => 'store down']]],
			$this->logged
		);
	}//end testAFailedMembershipLookupIsThrown()

	/**
	 * The role inside a tenant: the first row's role, null for none or an empty one.
	 *
	 * @return void
	 */
	public function testTheRoleAndItsQuery(): void {
		$auth = $this->auth(['tenantUser' => [['role' => 'case_handler'], ['role' => 'tenant_admin']]]);
		self::assertSame('case_handler', $auth->resolveUserRole('tenant-a', 'alice'));
		self::assertSame(
			[
				[
					'filters' => ['register' => 'dossiq', 'schema' => 'tenantUser', 'tenantRef' => 'tenant-a', 'userRef' => 'alice'],
					'limit' => 1,
					'offset' => 0,
				],
			],
			$this->queries
		);

		self::assertNull($this->auth(['tenantUser' => [['role' => '']]])->resolveUserRole('tenant-a', 'alice'));
		self::assertNull($this->auth(['tenantUser' => [['userRef' => 'alice']]])->resolveUserRole('tenant-a', 'alice'));
		self::assertNull($this->auth(['tenantUser' => []])->resolveUserRole('tenant-a', 'alice'));
	}//end testTheRoleAndItsQuery()

	/**
	 * A role lookup that fails is logged and thrown, never read as "no role".
	 *
	 * @return void
	 */
	public function testAFailedRoleLookupIsThrown(): void {
		$failure = new RuntimeException('store down');
		try {
			$this->auth(['tenantUser' => $failure])->resolveUserRole('tenant-a', 'alice');
			self::fail('The lookup failure must be thrown.');
		} catch (RuntimeException $e) {
			self::assertSame($failure, $e);
		}

		self::assertSame(
			[['error', 'Dossiq: resolveUserRole lookup failed (fail-closed)', ['tenantId' => 'tenant-a', 'userId' => 'alice', 'exception' => 'store down']]],
			$this->logged
		);
	}//end testAFailedRoleLookupIsThrown()

	/**
	 * The mandate row in force now is the one whose window holds today; bad dates count for nothing.
	 *
	 * @return void
	 */
	public function testTheActiveMandateWindow(): void {
		$matrix = ['viewer' => ['read' => true]];
		$past = ['effectiveFrom' => '2001-01-01', 'effectiveTo' => '2002-01-01', 'matrix' => ['past' => []]];
		$future = ['effectiveFrom' => '2098-01-01', 'matrix' => ['future' => []]];
		$garbled = ['effectiveFrom' => 'not a date', 'matrix' => ['garbled' => []]];
		$open = ['matrix' => $matrix];

		self::assertSame($matrix, $this->auth(['tenantMandate' => [$past, $future, $garbled, $open]])->loadActiveMatrix('tenant-a'));
		self::assertSame(
			[
				[
					'filters' => ['register' => 'dossiq', 'schema' => 'tenantMandate', 'tenantRef' => 'tenant-a'],
					'limit' => 50,
					'offset' => 0,
				],
			],
			$this->queries
		);
		self::assertNull($this->auth(['tenantMandate' => [$past, $future, $garbled]])->loadActiveMatrix('tenant-a'));
		self::assertNull($this->auth(['tenantMandate' => []])->loadActiveMatrix('tenant-a'));
		self::assertSame(
			['x' => ['y' => true]],
			$this->auth(['tenantMandate' => [['effectiveTo' => '2099-12-31', 'matrix' => '{"x":{"y":true}}']]])->loadActiveMatrix('tenant-a')
		);
		self::assertSame([], $this->auth(['tenantMandate' => [['matrix' => 'not json']]])->loadActiveMatrix('tenant-a'));
		self::assertSame(
			[
				'tenant_admin' => ['*' => true],
				'case_handler' => ['create' => true, 'edit' => true, 'status_update' => true],
				'viewer' => [],
			],
			$this->auth(['tenantMandate' => [['effectiveFrom' => '2001-01-01']]])->loadActiveMatrix('tenant-a')
		);
	}//end testTheActiveMandateWindow()

	/**
	 * The mandate decision allows what the matrix grants and refuses the rest, with its reason.
	 *
	 * @return void
	 */
	public function testTheMandateDecisionAllowsAndRefuses(): void {
		$stores = [
			'tenantUser' => [['role' => 'case_handler']],
			'tenantMandate' => [['matrix' => ['case_handler' => ['edit' => true]]]],
		];

		self::assertSame(
			['allowed' => true, 'reason' => 'Authorised by mandate matrix'],
			$this->auth($stores)->validateMandateMatrix('tenant-a', 'alice', 'edit')
		);
		self::assertSame(
			['allowed' => false, 'reason' => 'Role case_handler is not authorised for action delete'],
			$this->auth($stores)->validateMandateMatrix('tenant-a', 'alice', 'delete')
		);
		self::assertSame(
			['allowed' => false, 'reason' => 'User has no role inside tenant'],
			$this->auth(['tenantUser' => []] + $stores)->validateMandateMatrix('tenant-a', 'alice', 'edit')
		);
		self::assertSame(
			['allowed' => false, 'reason' => 'No active mandate matrix for tenant'],
			$this->auth(['tenantMandate' => [['effectiveTo' => '2001-01-01']]] + $stores)->validateMandateMatrix('tenant-a', 'alice', 'edit')
		);

		$this->logged = [];
		self::assertSame(
			['allowed' => false, 'reason' => 'Mandate validation error'],
			$this->auth(['tenantUser' => new RuntimeException('down')] + $stores)->validateMandateMatrix('tenant-a', 'alice', 'edit')
		);
		self::assertSame(
			[
				'Dossiq: resolveUserRole lookup failed (fail-closed)',
				'Dossiq: mandate matrix validation failed (fail-closed)',
			],
			array_column($this->logged, 1)
		);
	}//end testTheMandateDecisionAllowsAndRefuses()
}//end class
