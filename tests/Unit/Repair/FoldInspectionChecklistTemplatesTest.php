<?php

/**
 * Repair-step tests for the checklist template fold.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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
 *
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\FoldInspectionChecklistTemplates;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see FoldInspectionChecklistTemplates}.
 *
 * @covers \OCA\Dossiq\Repair\FoldInspectionChecklistTemplates
 * @uses   \OCA\Dossiq\Service\Inspection\InspectionTemplateMapper
 */
final class FoldInspectionChecklistTemplatesTest extends TestCase {

	/**
	 * Both stacks fold once; a second run folds nothing; a half-readable one is left.
	 *
	 * @return void
	 */
	public function testEachSourceFoldsOnceAndAHalfReadableOneIsLeft(): void {
		$store = self::store(
			[
				'inspectieChecklist' => [['@self' => ['id' => 'a-1'], 'name' => 'Fundering', 'status' => 'active', 'items' => [['label' => 'Wapening']]]],
				'checklistItem' => [['@self' => ['id' => 'i-1'], 'question' => 'Nooduitgang vrij?', 'type' => 'boolean']],
				'inspectionChecklist' => [
					['@self' => ['id' => 'c-1'], 'name' => 'Brand', 'active' => true, 'items' => ['i-1']],
					['@self' => ['id' => 'c-2'], 'name' => 'Kapot', 'active' => true, 'items' => ['gone']],
				],
				'inspectionChecklistTemplate' => [],
			]
		);
		$step = new FoldInspectionChecklistTemplates($this->settings(store: $store), $this->createMock(LoggerInterface::class));

		$first = $this->createMock(IOutput::class);
		$first->expects($this->once())->method('warning')->with($this->stringContains('inspectionChecklist/c-2'));
		$first->expects($this->once())->method('info')->with($this->stringContains('2 folded, 0 already folded, 1 left'));
		$step->run($first);

		$refs = array_map(static fn (array $t): string => $t['legacyRef'], $store->rows['inspectionChecklistTemplate']);
		$this->assertSame(['inspectieChecklist/a-1', 'inspectionChecklist/c-1'], $refs);
		$this->assertSame('Nooduitgang vrij?', $store->rows['inspectionChecklistTemplate'][1]['sections'][0]['items'][0]['label']);

		$second = $this->createMock(IOutput::class);
		$second->expects($this->once())->method('info')->with($this->stringContains('0 folded, 2 already folded, 1 left'));
		$step->run($second);
		$this->assertCount(2, $store->rows['inspectionChecklistTemplate']);
	}//end testEachSourceFoldsOnceAndAHalfReadableOneIsLeft()

	/**
	 * Without OpenRegister the step says so and does nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheStepSkips(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('Skipping'));

		(new FoldInspectionChecklistTemplates($settings, $this->createMock(LoggerInterface::class)))->run($output);
	}//end testWithoutOpenRegisterTheStepSkips()

	/**
	 * Settings over the store.
	 *
	 * @param object $store The store.
	 *
	 * @return SettingsService The stub.
	 */
	private function settings(object $store): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnMap([['register', '', '7']]);

		return $settings;
	}//end settings()

	/**
	 * An in-memory ObjectService keyed by schema slug.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows Objects per schema.
	 *
	 * @return object The store.
	 */
	private static function store(array $rows): object {
		return new class($rows) {
			/**
			 * Hold the rows.
			 *
			 * @param array<string, array<int, array<string, mixed>>> $rows Objects per schema.
			 */
			public function __construct(public array $rows) {
			}//end __construct()

			/**
			 * Run the work.
			 *
			 * @param callable $operation The work.
			 *
			 * @return mixed Its result.
			 */
			public function runAsSystem(callable $operation): mixed {
				return $operation();
			}

			/**
			 * Every object of one schema.
			 *
			 * @param string               $register      The register.
			 * @param string               $schema        The schema slug.
			 * @param array<string, mixed> $filters       Ignored.
			 * @param bool                 $_rbac         Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>> The objects.
			 *
			 * @SuppressWarnings(PHPMD.UnusedFormalParameter) OpenRegister's signature.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return ($this->rows[$schema] ?? []);
			}

			/**
			 * Append one object.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param int|string           $register The register.
			 * @param int|string           $schema   The schema slug.
			 * @param string|null          $uuid     Ignored.
			 *
			 * @return array<string, mixed> The object.
			 */
			public function saveObject(array $object, int|string $register, int|string $schema, ?string $uuid = null): array {
				unset($register, $uuid);
				$this->rows[(string)$schema][] = $object;

				return $object;
			}
		};
	}//end store()
}//end class
