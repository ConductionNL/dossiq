<?php

/**
 * Repair-step tests for repointing planned field inspections at their template.
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
 * @spec openspec/changes/inspection-checklists-onto-task/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RepointFieldInspectionTemplates;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see RepointFieldInspectionTemplates}.
 *
 * @covers \OCA\Dossiq\Repair\RepointFieldInspectionTemplates
 * @uses   \OCA\Dossiq\Service\Support\ObjectSearch
 */
final class RepointFieldInspectionTemplatesTest extends TestCase {

	/**
	 * Both older schemas repoint to their folded template; a template ref stays; a second run changes nothing.
	 *
	 * @return void
	 */
	public function testOlderReferencesRepointOnceAndTemplateReferencesStay(): void {
		$store = self::store(
			rows: [
				'inspectionChecklistTemplate' => [
					['@self' => ['id' => 't-a'], 'legacyRef' => 'inspectieChecklist/a-1'],
					['@self' => ['id' => 't-c'], 'legacyRef' => 'inspectionChecklist/c-1'],
					['@self' => ['id' => 't-new']],
				],
				'fieldInspection' => [
					['@self' => ['id' => 'f-1'], 'checklistTemplateRef' => 'a-1', 'caseRef' => 'case-1'],
					['@self' => ['id' => 'f-2'], 'checklistTemplateRef' => 'c-1'],
					['@self' => ['id' => 'f-3'], 'checklistTemplateRef' => 't-new'],
					['@self' => ['id' => 'f-4'], 'checklistTemplateRef' => ''],
				],
			]
		);
		$step = new RepointFieldInspectionTemplates(
			settingsService: $this->settings(store: $store),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$first = $this->createMock(originalClassName: IOutput::class);
		$first->expects($this->never())->method('warning');
		$first->expects($this->once())->method('info')->with(
			'Field inspection repoint: 2 repointed, 1 already on a template, 0 left for a person to look at.'
		);
		$step->run($first);

		$this->assertSame(
			expected: [
				['f-1', ['checklistTemplateRef' => 't-a'], 'fieldInspection'],
				['f-2', ['checklistTemplateRef' => 't-c'], 'fieldInspection'],
			],
			actual: $store->patches
		);
		$this->assertSame(expected: 't-a', actual: $store->rows['fieldInspection'][0]['checklistTemplateRef']);
		$this->assertSame(expected: 'case-1', actual: $store->rows['fieldInspection'][0]['caseRef'], message: 'only the reference is written');

		$second = $this->createMock(originalClassName: IOutput::class);
		$second->expects($this->once())->method('info')->with(
			'Field inspection repoint: 0 repointed, 3 already on a template, 0 left for a person to look at.'
		);
		$step->run($second);
		$this->assertCount(expectedCount: 2, haystack: $store->patches);
	}//end testOlderReferencesRepointOnceAndTemplateReferencesStay()

	/**
	 * A reference with no folded template, and a refused write, are both left and reported.
	 *
	 * @return void
	 */
	public function testAnUnfoldedReferenceAndARefusedWriteAreLeft(): void {
		$store = self::store(
			rows: [
				'inspectionChecklistTemplate' => [
					['@self' => ['id' => 't-a'], 'legacyRef' => 'inspectieChecklist/a-1'],
					['@self' => ['id' => 't-b'], 'legacyRef' => 'inspectieChecklist/b-1'],
				],
				'fieldInspection' => [
					['@self' => ['id' => 'f-1'], 'checklistTemplateRef' => 'gone'],
					['@self' => ['id' => 'f-2'], 'checklistTemplateRef' => 'a-1'],
					['@self' => ['id' => 'f-3'], 'checklistTemplateRef' => 'b-1'],
				],
			]
		);
		$store->refuse = 'f-2';
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Field inspection repoint refused',
			['fieldInspection' => 'f-2', 'exception' => 'store said no']
		);
		$output = $this->createMock(originalClassName: IOutput::class);
		$warnings = [];
		$output->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
			$warnings[] = $message;
		});
		$output->expects($this->once())->method('info')->with(
			'Field inspection repoint: 1 repointed, 0 already on a template, 2 left for a person to look at.'
		);

		(new RepointFieldInspectionTemplates(settingsService: $this->settings(store: $store), logger: $logger))->run($output);

		$this->assertSame(
			expected: [
				'Field inspection f-1 names checklist gone, which has no folded template; not repointed.',
				'Field inspection f-2 could not be repointed: store said no',
			],
			actual: $warnings
		);
		$this->assertSame(expected: [['f-3', ['checklistTemplateRef' => 't-b'], 'fieldInspection']], actual: $store->patches);
		$this->assertSame(expected: 'gone', actual: $store->rows['fieldInspection'][0]['checklistTemplateRef']);
	}//end testAnUnfoldedReferenceAndARefusedWriteAreLeft()

	/**
	 * Without OpenRegister the step says so and does nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheStepSkips(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains(string: 'Skipping'));
		$output->expects($this->never())->method('info');

		(
			new RepointFieldInspectionTemplates(settingsService: $settings, logger: $this->createMock(originalClassName: LoggerInterface::class))
		)->run($output);
	}//end testWithoutOpenRegisterTheStepSkips()

	/**
	 * Settings over the store.
	 *
	 * @param object $store The store.
	 *
	 * @return SettingsService The stub.
	 */
	private function settings(object $store): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
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
			 * The patches written, as [id, data, schema].
			 *
			 * @var array<int, array{0: string, 1: array<string, mixed>, 2: string}>
			 */
			public array $patches = [];

			/**
			 * The id of an object whose patch throws, or ''.
			 *
			 * @var string
			 */
			public string $refuse = '';

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
			 * Merge fields onto one stored object.
			 *
			 * @param ObjectPatch $patch         The patch.
			 * @param bool        $_rbac         Ignored.
			 * @param bool        $_multitenancy Ignored.
			 *
			 * @return array<string, mixed> The object.
			 *
			 * @SuppressWarnings(PHPMD.UnusedFormalParameter) OpenRegister's signature.
			 */
			public function patchObject(ObjectPatch $patch, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($patch->objectId === $this->refuse) {
					throw new \RuntimeException('store said no');
				}

				$schema = (string)$patch->target->schema;
				$this->patches[] = [$patch->objectId, $patch->data, $schema];
				foreach ($this->rows[$schema] as $index => $row) {
					if (($row['@self']['id'] ?? '') === $patch->objectId) {
						$this->rows[$schema][$index] = array_merge($row, $patch->data);
						return $this->rows[$schema][$index];
					}
				}

				return [];
			}
		};
	}//end store()
}//end class
