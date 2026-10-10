<?php

/**
 * VthChecklistSeederTest.
 *
 * Pins the one writer of `inspectionChecklistTemplate` outside the config
 * store, before the inspection cluster moves (inspection-checklists-onto-task
 * step 1): it seeds each shipped template once, binds its case type by slug,
 * steps over a refused write, and seeds nothing when it cannot read what is
 * already there.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair\Vth
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Vth;

use OCA\Dossiq\Repair\Vth\VthChecklistSeeder;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the checklist template seed.
 *
 * @covers \OCA\Dossiq\Repair\Vth\VthChecklistSeeder
 *
 * @spec openspec/changes/inspection-checklists-onto-task/tasks.md
 */
class VthChecklistSeederTest extends TestCase {

	/**
	 * The case type uuid the seed binds by slug.
	 */
	private const CASE_TYPE = '5d0c3a51-8a4e-4c43-9b8e-0d6f8f2b7a10';

	/**
	 * A fake ObjectService that records writes.
	 *
	 * @param array<int, array<string, mixed>> $existing Rows already stored.
	 * @param bool $unreadable Whether the list read throws.
	 * @param array<int, string> $refuse Slugs whose write throws.
	 *
	 * @return object The fake.
	 */
	private function objectService(array $existing = [], bool $unreadable = false, array $refuse = []): object {
		return new class($existing, $unreadable, $refuse) {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $written = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, array<string, mixed>> $existing Rows already stored.
			 * @param bool $unreadable Whether the list read throws.
			 * @param array<int, string> $refuse Slugs whose write throws.
			 */
			public function __construct(
				private array $existing,
				private bool $unreadable,
				private array $refuse,
			) {
			}

			/**
			 * The slug search the seeder's idempotency read goes through.
			 *
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 * @param array<string, mixed> $filters Filters.
			 * @param bool $_rbac RBAC flag.
			 * @param bool $_multitenancy Multitenancy flag.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(
				string $register,
				string $schema,
				array $filters = [],
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				if ($this->unreadable === true) {
					throw new RuntimeException('OpenRegister unreachable');
				}

				return $this->existing;
			}

			/**
			 * Record one write.
			 *
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 * @param array<string, mixed> $object Payload.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(string $register, string $schema, array $object): array {
				if (in_array($object['slug'] ?? '', $this->refuse, true) === true) {
					throw new RuntimeException('schema refused');
				}

				$this->written[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
				return $object;
			}
		};
	}//end objectService()

	/**
	 * Run the seeder with the default schema setting.
	 *
	 * @param object $objectService The fake.
	 * @param array<int, array<string, mixed>> $checklists Shipped templates.
	 * @param string $schemaSetting The configured schema slug.
	 *
	 * @return array{seeded: int, skipped: int}
	 */
	private function seed(object $objectService, array $checklists, string $schemaSetting = ''): array {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturn($schemaSetting);

		return (new VthChecklistSeeder($settings, $this->createMock(LoggerInterface::class)))->seed(
			objectService: $objectService,
			register: 'dossiq',
			data: ['inspectionChecklists' => $checklists],
			caseTypeIds: ['toezicht-bouw' => self::CASE_TYPE],
			output: $this->createMock(IOutput::class)
		);
	}//end seed()

	/**
	 * A shipped template lands in inspectionChecklistTemplate, bound to its
	 * case type by uuid, with the slug-only key gone.
	 *
	 * @return void
	 */
	public function testATemplateIsWrittenBoundToItsCaseType(): void {
		$objectService = $this->objectService();

		$tally = $this->seed($objectService, [['slug' => 'fundering', 'name' => 'Fundering', 'caseTypeSlug' => 'toezicht-bouw']]);

		$this->assertSame(['seeded' => 1, 'skipped' => 0], $tally);
		$this->assertSame('inspectionChecklistTemplate', $objectService->written[0]['schema']);
		$this->assertSame(self::CASE_TYPE, $objectService->written[0]['object']['caseType']);
		$this->assertArrayNotHasKey('caseTypeSlug', $objectService->written[0]['object']);
	}//end testATemplateIsWrittenBoundToItsCaseType()

	/**
	 * A template whose slug is already stored (in `@self`) is skipped.
	 *
	 * @return void
	 */
	public function testAStoredTemplateIsSkipped(): void {
		$objectService = $this->objectService(existing: [['@self' => ['slug' => 'fundering'], 'name' => 'Fundering']]);

		$tally = $this->seed($objectService, [['slug' => 'fundering'], ['slug' => 'dak']]);

		$this->assertSame(['seeded' => 1, 'skipped' => 1], $tally);
		$this->assertSame(['dak'], array_map(static fn (array $w): string => $w['object']['slug'], $objectService->written));
	}//end testAStoredTemplateIsSkipped()

	/**
	 * An unresolvable case type drops the binding, not the template.
	 *
	 * @return void
	 */
	public function testAnUnknownCaseTypeLeavesTheTemplateUnbound(): void {
		$objectService = $this->objectService();

		$this->seed($objectService, [['slug' => 'asbest', 'caseTypeSlug' => 'does-not-exist']]);

		$this->assertArrayNotHasKey('caseType', $objectService->written[0]['object']);
		$this->assertArrayNotHasKey('caseTypeSlug', $objectService->written[0]['object']);
	}//end testAnUnknownCaseTypeLeavesTheTemplateUnbound()

	/**
	 * A refused write costs that template only.
	 *
	 * @return void
	 */
	public function testARefusedWriteCostsOnlyThatTemplate(): void {
		$objectService = $this->objectService(refuse: ['fundering']);

		$tally = $this->seed($objectService, [['slug' => 'fundering'], ['slug' => 'dak'], ['name' => 'no slug']]);

		$this->assertSame(['seeded' => 1, 'skipped' => 0], $tally);
		$this->assertCount(1, $objectService->written);
	}//end testARefusedWriteCostsOnlyThatTemplate()

	/**
	 * An unreadable list seeds nothing, so an upgrade cannot write duplicates.
	 *
	 * @return void
	 */
	public function testAnUnreadableListSeedsNothing(): void {
		$objectService = $this->objectService(unreadable: true);

		$tally = $this->seed($objectService, [['slug' => 'fundering']]);

		$this->assertSame(['seeded' => 0, 'skipped' => 0], $tally);
		$this->assertSame([], $objectService->written);
	}//end testAnUnreadableListSeedsNothing()

	/**
	 * A configured schema slug wins over the default.
	 *
	 * @return void
	 */
	public function testTheConfiguredSchemaWins(): void {
		$objectService = $this->objectService();

		$this->seed($objectService, [['slug' => 'fundering']], 'checklistTemplateV2');

		$this->assertSame('checklistTemplateV2', $objectService->written[0]['schema']);
	}//end testTheConfiguredSchemaWins()
}//end class
