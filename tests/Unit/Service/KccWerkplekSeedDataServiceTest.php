<?php

/**
 * Unit tests for KccWerkplekSeedDataService.
 *
 * Exercises the seed pipeline against an in-memory ObjectService fake,
 * asserts idempotency, and verifies the documented seed shape (five default
 * quick-actions and two example belplannen).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\KccWerkplekSeedDataService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\KccWerkplekSeedDataService
 */
class KccWerkplekSeedDataServiceTest extends TestCase {
	private FakeKccSeedObjectService $objects;

	private KccWerkplekSeedDataService $service;

	protected function setUp(): void {
		$this->objects = new FakeKccSeedObjectService();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'procest',
					'kcc_quick_action_schema' => 'kccQuickAction',
					'belplan_schema' => 'belplan',
					default => '',
				};
			},
		);

		$this->service = new KccWerkplekSeedDataService(
			$settings,
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * @return void
	 */
	public function testSeedCreatesQuickActionsAndBelplannen(): void {
		$result = $this->service->seed();

		self::assertSame(true, $result['success']);
		self::assertSame(5, $result['quickActions']);
		self::assertSame(2, $result['belplannen']);
		self::assertSame(0, $result['skipped']);
		self::assertCount(5, $this->objects->store['kccQuickAction']);
		self::assertCount(2, $this->objects->store['belplan']);
	}

	/**
	 * @return void
	 */
	public function testSeedIsIdempotent(): void {
		$this->service->seed();
		$second = $this->service->seed();

		self::assertSame(true, $second['success']);
		self::assertSame(0, $second['quickActions']);
		self::assertSame(0, $second['belplannen']);
		self::assertSame(7, $second['skipped']);
		self::assertCount(5, $this->objects->store['kccQuickAction']);
		self::assertCount(2, $this->objects->store['belplan']);
	}

	/**
	 * @return void
	 */
	public function testQuickActionTypesAreValidEnumValues(): void {
		$this->service->seed();

		$allowed = [
			'status_geven',
			'nieuwe_zaak',
			'klacht_registreren',
			'doorverbinden',
			'bel_terug_inplannen',
			'email_sturen',
			'kopie_document_sturen',
		];
		foreach ($this->objects->store['kccQuickAction'] as $row) {
			self::assertContains($row['actieType'], $allowed);
			self::assertNotSame('', (string)$row['naam']);
		}
	}

	/**
	 * @return void
	 */
	public function testAlgemeenBelplanHasKeuzemenuAndOverflow(): void {
		$this->service->seed();

		$belplan = $this->objects->store['belplan']['kcc-belplan-algemeen'];
		$types = array_map(static fn (array $step): string => (string)$step['type'], $belplan['routeringStappen']);
		self::assertContains('keuzemenu', $types);
		self::assertContains('vaardigheid_match', $types);
		self::assertContains('wachtrij_overflow', $types);
		self::assertSame('voicemail', $belplan['terugvalActie']);
	}

	/**
	 * @return void
	 */
	public function testReturnsErrorWhenSchemasUnconfigured(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturn('');

		$service = new KccWerkplekSeedDataService($settings, $this->createMock(LoggerInterface::class));
		$result = $service->seed();

		self::assertSame(false, $result['success']);
	}
}

/**
 * In-memory ObjectService fake supporting only the calls the seed pipeline needs.
 */
class FakeKccSeedObjectService {
	/** @var array<string, array<string, array<string, mixed>>> */
	public array $store = [];

	/**
	 * @param string $register Register id.
	 * @param string $schema Schema id.
	 * @param array<string, mixed> $object Object.
	 * @return array<string, mixed>
	 */
	public function saveObject(string $register, string $schema, array $object): array {
		$id = (string)($object['id'] ?? ('row-' . count($this->store[$schema] ?? [])));
		$object['id'] = $id;
		$this->store[$schema][$id] = $object;
		return $object;
	}

	/**
	 * @param string $register Register id.
	 * @param string $schema Schema id.
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function findObjects(string $register, string $schema, array $filters = []): array {
		return array_values($this->store[$schema] ?? []);
	}

	/**
	 * Slug-aware search bridge mirroring ObjectService::searchObjectsBySlug().
	 *
	 * @param string $registerSlug Register slug.
	 * @param string $schemaSlug Schema slug.
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array {
		return $this->findObjects($registerSlug, $schemaSlug, $filters);
	}

	/**
	 * Numeric-ID search bridge mirroring ObjectService::searchObjects().
	 *
	 * @param array<string, mixed> $query Query carrying `@self` register/schema.
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjects(array $query = []): array {
		$schema = (string)(($query['@self'] ?? [])['schema'] ?? '');
		return $this->findObjects('', $schema);
	}
}
