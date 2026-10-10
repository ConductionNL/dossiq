<?php

/**
 * Stored grounds are mapped onto the settled list once, never guessed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-stored-codes-are-mapped-never-guessed-req-wrg-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MapWooRefusalGroundCodes;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCA\Dossiq\Tests\Support\RefusalGroundStore;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-WRG-006 through the repair step's run().
 *
 * @covers \OCA\Dossiq\Repair\MapWooRefusalGroundCodes
 *
 * @uses \OCA\Dossiq\Woo\WooRefusalGrounds
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class MapWooRefusalGroundCodesTest extends TestCase {

	/**
	 * The assessments and decisions.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * Output lines the step wrote.
	 *
	 * @var list<string>
	 */
	private array $said = [];

	/**
	 * Reset the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->said = [];
	}//end setUp()

	/**
	 * Run the step once.
	 *
	 * @return void
	 */
	private function runStep(): void {
		$config = ['register' => 'dossiq', 'woo_assessment_schema' => 'wooDocumentAssessment', 'decision_schema' => 'decision'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$groundSettings = $this->createMock(SettingsService::class);
		$groundSettings->method('getObjectService')->willReturn(RefusalGroundStore::seeded());

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->said[] = $line;
		});
		$output->method('warning')->willReturnCallback(function (string $line): void {
			$this->said[] = $line;
		});

		(new MapWooRefusalGroundCodes(
			settingsService: $settings,
			grounds: new WooRefusalGrounds(settingsService: $groundSettings, logger: new NullLogger()),
			logger: new NullLogger(),
		))->run($output);
	}//end runStep()

	/**
	 * Each old code becomes its settled code, and the row is marked.
	 *
	 * @return void
	 */
	public function testAnUnambiguousCodeIsMapped(): void {
		$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'a-1', row: ['classification' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.5', '5.2.1', '5.2.4']]);
		$this->store->seed(schema: 'decision', uuid: 'd-1', row: ['weigeringsgronden' => ['5.1.4', '5.2.4']]);

		$this->runStep();

		$assessment = $this->store->row(schema: 'wooDocumentAssessment', uuid: 'a-1');
		$this->assertSame(['5.1.2.e', '5.1.2.b', '5.2.1'], $assessment['weigeringsgronden']);
		$this->assertSame(WooRefusalGrounds::LIST_VERSION, $assessment['groundsListVersion']);
		$this->assertSame(['5.2.1'], $this->store->row(schema: 'decision', uuid: 'd-1')['weigeringsgronden']);

		$register = new RealSchemaValidator();
		$this->assertSame([], $register->errors(slug: 'wooDocumentAssessment', payload: $assessment, creating: false));
	}//end testAnUnambiguousCodeIsMapped()

	/**
	 * A code that is neither old nor settled is kept, flagged and listed.
	 *
	 * @return void
	 */
	public function testAnAmbiguousCodeIsFlaggedAndKept(): void {
		$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'a-2', row: ['weigeringsgronden' => ['10.2.g', '5.1.1']]);

		$this->runStep();

		$row = $this->store->row(schema: 'wooDocumentAssessment', uuid: 'a-2');
		$this->assertSame(['10.2.g', '5.1.1.a'], $row['weigeringsgronden']);
		$this->assertSame(['10.2.g'], $row['groundsUnmapped']);
		$this->assertNotEmpty(array_filter($this->said, static fn (string $line): bool => str_contains($line, 'a-2') && str_contains($line, '10.2.g')));
	}//end testAnAmbiguousCodeIsFlaggedAndKept()

	/**
	 * A second run writes nothing, though 5.2.1 is an old code and a new one.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'a-3', row: ['weigeringsgronden' => ['5.2.1']]);

		$this->runStep();
		$writes = $this->store->writes;
		$first = $this->store->row(schema: 'wooDocumentAssessment', uuid: 'a-3');
		$this->runStep();

		$this->assertSame(1, $writes);
		$this->assertSame($writes, $this->store->writes);
		$this->assertSame(['5.1.2.b'], $first['weigeringsgronden']);
		$this->assertSame($first, $this->store->row(schema: 'wooDocumentAssessment', uuid: 'a-3'));
	}//end testASecondRunChangesNothing()
}//end class
