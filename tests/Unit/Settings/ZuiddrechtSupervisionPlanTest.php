<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Inspection\SupervisionPlanProgress;
use PHPUnit\Framework\TestCase;

/**
 * Zuiddrecht's 2026 supervision plan: 150 inspections in three categories, written only in fields the schemas declare.
 */
class ZuiddrechtSupervisionPlanTest extends TestCase {

	/**
	 * The example-set rows of a schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return list<array<string, mixed>> The rows.
	 */
	private function rows(string $schema): array {
		$file = __DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.d/68-supervision-plan-2026.json';
		$set  = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

		return array_values(array_filter($set['objects'], static fn (array $row): bool => $row['schema'] === $schema));
	}//end rows()

	/**
	 * The properties a schema declares in the supervision plan fragment.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return list<string> The property names.
	 */
	private function properties(string $schema): array {
		$file      = __DIR__.'/../../../lib/Settings/register.d/99-supervision-plan.json';
		$fragment  = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
		$component = $fragment['components'];

		$this->assertContains(needle: $schema, haystack: $component['registers']['dossiq']['schemas'], message: 'an unlisted schema is unreachable in the register');

		return array_keys($component['schemas'][$schema]['properties']);
	}//end properties()

	/**
	 * The plan is 2026's, with 1,600 capacity hours.
	 *
	 * @return void
	 */
	public function testThePlanIsZuiddrechts2026Plan(): void {
		$plans = $this->rows(schema: 'supervisionPlan');

		$this->assertCount(expectedCount: 1, haystack: $plans);
		$this->assertSame(expected: 2026, actual: $plans[0]['data']['year']);
		$this->assertSame(expected: 1600, actual: $plans[0]['data']['capacityHours']);
	}//end testThePlanIsZuiddrechts2026Plan()

	/**
	 * Hospitality 40, construction 60 and environment 50 are planned, each demand equal to its plan and within capacity.
	 *
	 * @return void
	 */
	public function testTheCategoriesPlan150InspectionsTheirRiskAsksFor(): void {
		$categories = array_column($this->rows(schema: 'supervisionPlanCategory'), 'data');
		$hours      = 0;

		$this->assertSame(expected: ['Hospitality' => 40, 'Construction' => 60, 'Environment' => 50], actual: array_column($categories, 'planned', 'name'));
		foreach ($categories as $category) {
			$this->assertSame(expected: '{{object:toezichtplan-2026.id}}', actual: $category['plan']);
			$this->assertSame(expected: $category['planned'], actual: SupervisionPlanProgress::demandOf(category: $category), message: $category['name']);
			$hours += $category['planned'] * $category['hoursPerInspection'];
		}

		$this->assertLessThanOrEqual(expected: 1600, actual: $hours);
	}//end testTheCategoriesPlan150InspectionsTheirRiskAsksFor()

	/**
	 * Every field the set writes is one the schema declares.
	 *
	 * @return void
	 */
	public function testTheSetWritesOnlyDeclaredFields(): void {
		foreach (['supervisionPlan', 'supervisionPlanCategory'] as $schema) {
			$declared = $this->properties(schema: $schema);
			foreach ($this->rows(schema: $schema) as $row) {
				$this->assertSame(expected: [], actual: array_values(array_diff(array_keys($row['data']), $declared)), message: $row['key']);
			}
		}
	}//end testTheSetWritesOnlyDeclaredFields()
}//end class
