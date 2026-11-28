<?php

/**
 * CaseRoleSource Unit Tests
 *
 * The role records of a case are read from the configured register and role
 * schema by the case id; without an object service, register or role schema
 * there is nothing to read; a failing read is logged and refused.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Routing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Routing\CaseRoleSource;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for CaseRoleSource.
 *
 * @covers \OCA\Dossiq\Service\Routing\CaseRoleSource
 */
final class CaseRoleSourceTest extends TestCase {

	/**
	 * Settings answering the given object service and configuration.
	 *
	 * @param object|null           $objectService The object service, or null without OpenRegister.
	 * @param array<string, string> $config        The configured values.
	 *
	 * @return SettingsService
	 */
	private function settings(?object $objectService, array $config): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => $config[$key] ?? $default
		);
		return $settings;
	}//end settings()

	/**
	 * A store that records each findAll() config and answers the given rows, or throws.
	 *
	 * @param array<int, mixed> $rows   The rows to answer.
	 * @param bool              $throws Whether the read throws.
	 *
	 * @return object
	 */
	private function store(array $rows, bool $throws=false): object {
		return new class($rows, $throws) {
			/**
			 * The findAll() configs, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $reads = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, mixed> $rows   The rows.
			 * @param bool              $throws Whether the read throws.
			 */
			public function __construct(private array $rows, private bool $throws) {
			}

			/**
			 * Record the read and answer the rows.
			 *
			 * @param array<string, mixed> $config The read.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config): array {
				$this->reads[] = $config;
				if ($this->throws === true) {
					throw new RuntimeException('register down');
				}
				return $this->rows;
			}
		};
	}//end store()

	/**
	 * The roles are read by case id from the configured register and role schema.
	 *
	 * @return void
	 */
	public function testTheRolesOfTheCaseAreReadFromTheRoleSchema(): void {
		$store = $this->store(rows: [['id' => 'r1']]);
		$source = new CaseRoleSource(
			settingsService: $this->settings(objectService: $store, config: ['register' => 'dossiq', 'role_schema' => 'caseRole']),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->assertSame(expected: [['id' => 'r1']], actual: $source->recordsOf(caseId: 'case-1'));
		$this->assertSame(
			expected: [['filters' => ['register' => 'dossiq', 'schema' => 'caseRole', 'case' => 'case-1']]],
			actual: $store->reads
		);
	}//end testTheRolesOfTheCaseAreReadFromTheRoleSchema()

	/**
	 * Without an object service, a register or a role schema there is nothing to read.
	 *
	 * @return void
	 */
	public function testNothingIsReadWithoutASource(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$store = $this->store(rows: [['id' => 'r1']]);

		$withoutService = $this->settings(objectService: null, config: ['register' => 'dossiq', 'role_schema' => 'caseRole']);
		$withoutRegister = $this->settings(objectService: $store, config: ['role_schema' => 'caseRole']);
		$withoutSchema = $this->settings(objectService: $store, config: ['register' => 'dossiq']);

		foreach ([$withoutService, $withoutRegister, $withoutSchema] as $settings) {
			$source = new CaseRoleSource(settingsService: $settings, logger: $logger);
			$this->assertNull(actual: $source->recordsOf(caseId: 'case-1'));
		}

		$this->assertSame(expected: [], actual: $store->reads);
	}//end testNothingIsReadWithoutASource()

	/**
	 * A failing read is logged and refused as indeterminate, never answered as no roles.
	 *
	 * @return void
	 */
	public function testAFailingReadIsLoggedAndRefused(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains(string: 'the roles on case case-1 could not be read'));
		$source = new CaseRoleSource(
			settingsService: $this->settings(
				objectService: $this->store(rows: [], throws: true),
				config: ['register' => 'dossiq', 'role_schema' => 'caseRole']
			),
			logger: $logger,
		);

		try {
			$source->recordsOf(caseId: 'case-1');
			$this->fail(message: 'a failing read must be refused');
		} catch (RefusedException $e) {
			$this->assertSame(expected: 'case-roles-unreadable', actual: $e->getRule());
			$this->assertSame(expected: RefusedException::STATUS_INDETERMINATE, actual: $e->getStatus());
		}
	}//end testAFailingReadIsLoggedAndRefused()
}//end class
