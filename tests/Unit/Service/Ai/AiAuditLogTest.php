<?php

/**
 * AiAuditLog Unit Tests
 *
 * Guards the AI oversight trail's storage bridge: where an entry is written,
 * how a listing is queried and paged, and that a missing configuration or a
 * failing OpenRegister never throws out of the audit log.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Ai
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

namespace OCA\Dossiq\Tests\Unit\Service\Ai;

use OCA\Dossiq\Service\Ai\AiAuditLog;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for AiAuditLog.
 *
 * @covers \OCA\Dossiq\Service\Ai\AiAuditLog
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class AiAuditLogTest extends TestCase {

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Records the calls OpenRegister's ObjectService receives.
	 *
	 * @var object
	 */
	private object $objectService;

	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = new class {
			/** @var list<array<string, mixed>> */
			public array $saved = [];

			/** @var list<array<int, mixed>> */
			public array $searches = [];

			/** @var array<int, mixed> */
			public array $rows = [];

			public ?\Throwable $failure = null;

			public function saveObject(string $register, string $schema, array $object): void {
				if ($this->failure !== null) {
					throw $this->failure;
				}

				$this->saved[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
			}

			public function searchObjects(array $query): array {
				if ($this->failure !== null) {
					throw $this->failure;
				}

				$this->searches[] = ['numeric', $query];
				return $this->rows;
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->searches[] = ['slug', $register, $schema, $filters];
				return $this->rows;
			}
		};
		$this->container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->objectService);
	}//end setUp()

	/**
	 * Configure the register and audit-entry schema the trail lives in.
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The schema id or slug.
	 *
	 * @return void
	 */
	private function configure(string $register, string $schema): void {
		$this->appConfig->method('getValueString')->willReturnMap([
			['dossiq', 'register', '', false, $register],
			['dossiq', 'ai_audit_entry_schema', '', false, $schema],
		]);
	}//end configure()

	private function log(): AiAuditLog {
		return new AiAuditLog($this->appConfig, $this->container, $this->logger);
	}//end log()

	public function testRecordSavesTheEntryInTheConfiguredRegisterAndSchema(): void {
		$this->configure('7', '12');

		$this->log()->record(['type' => 'summary', 'caseId' => 'c-1']);

		$this->assertSame(
			[['register' => '7', 'schema' => '12', 'object' => ['type' => 'summary', 'caseId' => 'c-1']]],
			$this->objectService->saved
		);
	}//end testRecordSavesTheEntryInTheConfiguredRegisterAndSchema()

	public function testRecordWithoutASchemaWritesNothingAndWarns(): void {
		$this->configure('7', '');
		$this->logger->expects($this->once())->method('warning')->with('AI audit: register or schema ID not configured');
		$this->logger->expects($this->never())->method('error');

		$this->log()->record(['type' => 'summary']);

		$this->assertSame([], $this->objectService->saved);
	}//end testRecordWithoutASchemaWritesNothingAndWarns()

	public function testAFailingSaveIsLoggedNotThrown(): void {
		$this->configure('7', '12');
		$this->objectService->failure = new RuntimeException('OR down');
		$this->logger->expects($this->once())->method('error')
			->with('Failed to record AI audit entry', ['error' => 'OR down']);

		$this->log()->record(['type' => 'summary']);
	}//end testAFailingSaveIsLoggedNotThrown()

	public function testListQueriesNewestFirstWithTheFiltersThatAreSet(): void {
		$this->configure('7', '12');
		$this->objectService->rows = [['id' => 'a'], ['id' => 'b']];

		$result = $this->log()->list(['caseId' => 'c-1', 'type' => '', 'other' => 'x'], 20, 40);

		$this->assertSame(
			['entries' => [['id' => 'a'], ['id' => 'b']], 'total' => null, 'limit' => 20, 'offset' => 40],
			$result
		);
		$this->assertSame(
			[[
				'numeric',
				[
					'_limit' => 20,
					'_offset' => 40,
					'_order' => ['timestamp' => 'DESC'],
					'caseId' => 'c-1',
					'@self' => ['register' => 7, 'schema' => 12],
				],
			]],
			$this->objectService->searches
		);
	}//end testListQueriesNewestFirstWithTheFiltersThatAreSet()

	public function testListWithSlugsGoesThroughTheSlugBridge(): void {
		$this->configure('ai-register', 'ai-audit-entry');

		$this->log()->list(['type' => 'summary']);

		$this->assertSame(
			[[
				'slug',
				'ai-register',
				'ai-audit-entry',
				['_limit' => 50, '_offset' => 0, '_order' => ['timestamp' => 'DESC'], 'type' => 'summary'],
			]],
			$this->objectService->searches
		);
	}//end testListWithSlugsGoesThroughTheSlugBridge()

	public function testTheLimitIsClampedAndTheOffsetFloored(): void {
		$this->configure('7', '12');

		$this->assertSame(200, $this->log()->list([], 5000)['limit']);
		$this->assertSame(50, $this->log()->list([], 0)['limit']);
		$this->assertSame(50, $this->log()->list([], -3)['limit']);
		$this->assertSame(1, $this->log()->list([], 1)['limit']);
		$this->assertSame(0, $this->log()->list([], 10, -5)['offset']);
	}//end testTheLimitIsClampedAndTheOffsetFloored()

	public function testListWithoutConfigurationIsEmpty(): void {
		$this->configure('', '12');

		$this->assertSame(
			['entries' => [], 'total' => null, 'limit' => 10, 'offset' => 5],
			$this->log()->list([], 10, 5)
		);
		$this->assertSame([], $this->objectService->searches);
	}//end testListWithoutConfigurationIsEmpty()

	public function testAFailingListingIsLoggedAndEmpty(): void {
		$this->configure('7', '12');
		$this->objectService->failure = new RuntimeException('timeout');
		$this->logger->expects($this->once())->method('error')
			->with('Failed to list AI audit entries', ['error' => 'timeout']);

		$this->assertSame(
			['entries' => [], 'total' => null, 'limit' => 50, 'offset' => 0],
			$this->log()->list()
		);
	}//end testAFailingListingIsLoggedAndEmpty()
}//end class
