<?php

/**
 * Unit tests for the repair step that copies shipped publication categories onto stored decision types.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\CopyShippedPublicationCategories;
use OCA\Dossiq\Service\Publication\PublicationCategory;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An existing instance keeps the category its decisions were published under.
 *
 * @covers \OCA\Dossiq\Repair\CopyShippedPublicationCategories
 *
 * @uses \OCA\Dossiq\Service\Publication\PublicationCategory
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CopyShippedPublicationCategoriesTest extends TestCase {

	/**
	 * The seeded Woo decision type.
	 */
	private const WOO_TYPE = '3c0f5a00-0000-4000-a000-00000000d001';

	/**
	 * The decision types.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * An empty store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
	}//end setUp()

	/**
	 * The step over the store and the given fragments.
	 *
	 * @param string $fragmentDir The fragments; '' for the shipped ones.
	 *
	 * @return CopyShippedPublicationCategories
	 */
	private function step(string $fragmentDir = ''): CopyShippedPublicationCategories {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'decision_type_schema' => 'decisionType'][$key] ?? $default)
		);

		return new CopyShippedPublicationCategories(settingsService: $settings, logger: new NullLogger(), fragmentDir: $fragmentDir);
	}//end step()

	/**
	 * The stored Woo decision type from before the property gets the shipped category, once.
	 *
	 * @return void
	 */
	public function testAStoredWooDecisionTypeGetsItsCategoryOnce(): void {
		$this->store->seed(schema: 'decisionType', uuid: self::WOO_TYPE, row: ['name' => 'WOO-besluit', 'publicationRequired' => true]);

		$this->step()->run(output: $this->createMock(IOutput::class));

		$row = $this->store->row(schema: 'decisionType', uuid: self::WOO_TYPE);
		$this->assertSame(PublicationCategory::FALLBACK, $row['publicationCategory']);
		$this->assertSame('WOO-besluit', $row['name']);

		$writes = $this->store->writes;
		$this->step()->run(output: $this->createMock(IOutput::class));
		$this->assertSame($writes, $this->store->writes);
	}//end testAStoredWooDecisionTypeGetsItsCategoryOnce()

	/**
	 * A category an administrator chose is kept.
	 *
	 * @return void
	 */
	public function testAnOwnCategoryIsKept(): void {
		$own = ['code' => 'infocat016', 'label' => 'Beschikkingen', 'uri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_46a81018'];
		$this->store->seed(schema: 'decisionType', uuid: self::WOO_TYPE, row: ['name' => 'WOO-besluit', 'publicationCategory' => $own]);

		$this->step()->run(output: $this->createMock(IOutput::class));

		$this->assertSame($own, $this->store->row(schema: 'decisionType', uuid: self::WOO_TYPE)['publicationCategory']);
		$this->assertSame(0, $this->store->writes);
	}//end testAnOwnCategoryIsKept()

	/**
	 * A row the instance does not hold is not created, and an incomplete shipped category is not copied.
	 *
	 * @return void
	 */
	public function testNothingIsCreatedAndIncompleteCategoriesAreSkipped(): void {
		$dir = sys_get_temp_dir() . '/dq-r8-fragments-' . bin2hex(random_bytes(4));
		mkdir($dir);
		file_put_contents($dir . '/10-a.json', json_encode(['components' => ['objects' => [
			['@self' => ['schema' => 'decisionType'], 'id' => 'held', 'publicationCategory' => ['code' => 'infocat016', 'label' => '', 'uri' => 'x']],
			['@self' => ['schema' => 'decisionType'], 'id' => 'not-held', 'publicationCategory' => PublicationCategory::FALLBACK],
			['@self' => ['schema' => 'statusType'], 'id' => 'status', 'publicationCategory' => PublicationCategory::FALLBACK],
		]]]));
		$this->store->seed(schema: 'decisionType', uuid: 'held', row: ['name' => 'Gehouden']);

		try {
			$this->step(fragmentDir: $dir)->run(output: $this->createMock(IOutput::class));
		} finally {
			unlink($dir . '/10-a.json');
			rmdir($dir);
		}

		$this->assertSame(0, $this->store->writes);
		$this->assertSame(['held'], array_column($this->store->all(schema: 'decisionType'), 'id'));
	}//end testNothingIsCreatedAndIncompleteCategoriesAreSkipped()

	/**
	 * Without OpenRegister the step says so and writes nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheStepSkips(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('skipping'));

		(new CopyShippedPublicationCategories(settingsService: $settings, logger: new NullLogger()))->run(output: $output);
	}//end testWithoutOpenRegisterTheStepSkips()
}//end class
