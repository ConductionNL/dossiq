<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Inspection;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Inspection\SupervisionPlanProgress;
use OCA\Dossiq\Service\Inspection\SupervisionPlanReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The plan, its categories and their inspections are searched in the reader's own scope.
 */
class SupervisionPlanReaderTest extends TestCase {

	/**
	 * A register double: one plan, two categories, two inspections in the first; it records every call.
	 *
	 * @param bool $broken Whether every read throws.
	 *
	 * @return object The double.
	 */
	private function objectService(bool $broken=false): object {
		return new class($broken) {
			/**
			 * The calls, as [method, schema or id, filters, scope].
			 *
			 * @var list<array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param bool $broken Whether every read throws.
			 */
			public function __construct(private readonly bool $broken) {
			}

			/**
			 * Find one object.
			 *
			 * @param string $id      The uuid.
			 * @param mixed  $options The options.
			 *
			 * @return array<string, mixed>|null The plan.
			 */
			public function find(string $id, mixed $options=null): ?array {
				$this->calls[] = ['find', $id, $options];
				if ($this->broken === true) {
					throw new RuntimeException('down');
				}

				if ($id !== 'plan-2026') {
					return null;
				}

				return ['id' => 'plan-2026', 'year' => 2026];
			}

			/**
			 * Search a schema by slug.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 * @param mixed                ...$scope The scope flags.
			 *
			 * @return list<array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters=[], mixed ...$scope): array {
				$this->calls[] = ['search', $schema, $filters, $scope];
				if ($schema === 'supervisionPlanCategory') {
					return [['id' => 'hos', 'name' => 'Hospitality'], ['@self' => ['id' => 'con'], 'name' => 'Construction'], ['name' => 'No id']];
				}

				if (($filters['planCategory'] ?? '') === 'hos') {
					return [['planCategory' => 'hos', 'completedAt' => '2026-03-02'], ['planCategory' => 'hos']];
				}

				return [];
			}
		};
	}//end objectService()

	/**
	 * The reader over a double.
	 *
	 * @param object|null $objectService The register double, or null when OpenRegister is absent.
	 * @param bool        $configured    Whether the inspection schema is configured.
	 *
	 * @return SupervisionPlanReader The reader.
	 */
	private function reader(?object $objectService, bool $configured=true): SupervisionPlanReader {
		$config = [
			'register'                         => 'dossiq',
			'supervision_plan_schema'          => 'supervisionPlan',
			'supervision_plan_category_schema' => 'supervisionPlanCategory',
			'field_inspection_schema'          => '',
		];
		if ($configured === true) {
			$config['field_inspection_schema'] = 'fieldInspection';
		}

		$settings = $this->createMock(originalClassName: SettingsServiceInterface::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		return new SupervisionPlanReader(
			settings: $settings,
			progress: new SupervisionPlanProgress(),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end reader()

	/**
	 * The categories are the plan's, the inspections are searched per category, all without widening the scope.
	 *
	 * @return void
	 */
	public function testTheReadSearchesPerCategoryInTheReadersScope(): void {
		$objects = $this->objectService();
		$read    = $this->reader(objectService: $objects)->read(id: 'plan-2026', withInspections: true);

		$this->assertSame(expected: SupervisionPlanReader::FOUND, actual: $read['status']);
		$this->assertSame(expected: 2026, actual: $read['plan']['year']);
		$this->assertCount(expectedCount: 3, haystack: $read['categories']);
		$this->assertCount(expectedCount: 2, haystack: $read['inspections']);

		$searches = array_values(array_filter($objects->calls, static fn (array $call): bool => $call[0] === 'search'));
		$this->assertSame(
			expected: [
				['search', 'supervisionPlanCategory', ['plan' => 'plan-2026'], []],
				['search', 'fieldInspection', ['planCategory' => 'hos'], []],
				['search', 'fieldInspection', ['planCategory' => 'con'], []],
			],
			actual: $searches
		);
	}//end testTheReadSearchesPerCategoryInTheReadersScope()

	/**
	 * Without inspections asked, none are searched.
	 *
	 * @return void
	 */
	public function testInspectionsAreReadOnlyWhenAsked(): void {
		$objects = $this->objectService();
		$read    = $this->reader(objectService: $objects)->read(id: 'plan-2026', withInspections: false);

		$this->assertArrayNotHasKey(key: 'inspections', array: $read);
		$this->assertCount(expectedCount: 2, haystack: $objects->calls);
	}//end testInspectionsAreReadOnlyWhenAsked()

	/**
	 * An unknown plan, no register, an unmapped schema and a broken read each answer their status.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$unknown = $this->reader(objectService: $this->objectService());
		$this->assertSame(expected: ['status' => 404], actual: $unknown->read(id: 'nope', withInspections: true));
		$this->assertSame(expected: ['status' => 503], actual: $this->reader(objectService: null)->read(id: 'plan-2026', withInspections: true));

		$unmapped = $this->reader(objectService: $this->objectService(), configured: false);
		$this->assertSame(expected: ['status' => 503], actual: $unmapped->read(id: 'plan-2026', withInspections: false));

		$broken = $this->reader(objectService: $this->objectService(broken: true));
		$this->assertSame(expected: ['status' => 500], actual: $broken->read(id: 'plan-2026', withInspections: true));
	}//end testRefusals()
}//end class
