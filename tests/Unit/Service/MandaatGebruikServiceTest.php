<?php

/**
 * MandaatGebruikService — mandate uses are read only where OpenRegister and the schema are there.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\MandaatGebruikService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The audit trail and the log, with and without a configured register.
 *
 * @covers \OCA\Dossiq\Service\MandaatGebruikService
 */
final class MandaatGebruikServiceTest extends TestCase {

	/**
	 * A service over the given object service and schema.
	 *
	 * @param object|null $objectService The object service, or null.
	 * @param string      $schema        The configured mandate-use schema.
	 *
	 * @return MandaatGebruikService
	 */
	private function service(?object $objectService, string $schema): MandaatGebruikService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ['register' => '5', 'mandaat_gebruik_schema' => $schema][$key] ?? '');

		return new MandaatGebruikService(settingsService: $settings, logger: $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * Without OpenRegister or the schema nothing is read or logged.
	 *
	 * @return void
	 */
	public function testNothingWithoutTheRegister(): void {
		self::assertSame([], $this->service(objectService: null, schema: 'use')->getDecisionAuditTrail(caseId: 'c1'));
		self::assertSame([], $this->service(objectService: new \stdClass(), schema: '')->getDecisionByMandaat(mandateId: 'm1'));
		self::assertSame([], $this->service(objectService: null, schema: 'use')->logMandaatGebruik(caseId: 'c1', decisionId: 'd1', mandateId: 'm1', userId: 'u1'));
	}//end testNothingWithoutTheRegister()

	/**
	 * With the register, the audit trail searches the case's uses.
	 *
	 * @return void
	 */
	public function testTheAuditTrailSearchesTheCase(): void {
		$objectService = new class {
			/**
			 * The last query.
			 *
			 * @var array<string, mixed>
			 */
			public array $query = [];

			/**
			 * Record the search.
			 *
			 * @param array<string, mixed> $query The query.
			 * @param mixed                ...$scope The scope.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjects(array $query, ...$scope): array {
				$this->query = $query;
				return [['id' => 'use-1']];
			}//end searchObjects()
		};

		self::assertSame([['id' => 'use-1']], $this->service(objectService: $objectService, schema: '30')->getDecisionAuditTrail(caseId: 'c1'));
		self::assertSame('c1', $objectService->query['caseId'] ?? null);
	}//end testTheAuditTrailSearchesTheCase()
}//end class
