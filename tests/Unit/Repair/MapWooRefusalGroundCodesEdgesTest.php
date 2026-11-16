<?php

/**
 * The Woo refusal ground mapping on the paths the main test leaves alone:
 * a settled list that cannot be read yet, a target schema that is not
 * configured, and a schema that cannot be read.
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
use OCA\Dossiq\Woo\WooRefusalGrounds;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\MapWooRefusalGroundCodes
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Repair\RefusalGroundMapping
 */
class MapWooRefusalGroundCodesEdgesTest extends TestCase {

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
	 * Build the step.
	 *
	 * @param array<string, string> $config  Dossiq config values.
	 * @param WooRefusalGrounds     $grounds The settled list.
	 * @param LoggerInterface|null  $logger  The logger.
	 *
	 * @return MapWooRefusalGroundCodes The step.
	 */
	private function step(array $config, WooRefusalGrounds $grounds, ?LoggerInterface $logger = null): MapWooRefusalGroundCodes {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new MapWooRefusalGroundCodes(
			settingsService: $settings,
			grounds: $grounds,
			logger: $logger ?? $this->createMock(LoggerInterface::class),
		);
	}//end step()

	/**
	 * A settled list with the given codes.
	 *
	 * @param array<int, string> $codes The codes.
	 *
	 * @return WooRefusalGrounds The list.
	 */
	private function grounds(array $codes): WooRefusalGrounds {
		$grounds = $this->createMock(WooRefusalGrounds::class);
		$grounds->method('listWithRetired')->willReturn(
			array_map(static fn (string $code): array => ['code' => $code], $codes)
		);

		return $grounds;
	}//end grounds()

	/**
	 * Run a step and record its output.
	 *
	 * @param MapWooRefusalGroundCodes $step The step.
	 *
	 * @return void
	 */
	private function runStep(MapWooRefusalGroundCodes $step): void {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->said[] = $line;
		});
		$output->method('warning')->willReturnCallback(function (string $line): void {
			$this->said[] = $line;
		});

		$step->run($output);
	}//end runStep()

	/**
	 * While the settled list cannot be read, nothing is mapped and the step
	 * says it will try again on the next upgrade.
	 *
	 * @return void
	 */
	public function testAnUnreadableSettledListSkipsTheMapping(): void {
		$this->store->seed(schema: 'decision', uuid: 'd-1', row: ['refusalGrounds' => ['5.1.2e']]);
		$grounds = $this->createMock(WooRefusalGrounds::class);
		$grounds->method('listWithRetired')->willThrowException(new RuntimeException('no ground schema'));

		$this->runStep($this->step(['register' => 'dossiq', 'decision_schema' => 'decision'], $grounds));

		$this->assertSame(
			['Woo refusal ground mapping: the settled list cannot be read yet, skipped until the next upgrade.'],
			$this->said
		);
		$this->assertSame(0, $this->store->writes);
	}//end testAnUnreadableSettledListSkipsTheMapping()

	/**
	 * A target schema that is not configured is passed over silently; a
	 * configured one whose rows cannot be read is logged by schema and
	 * reported with an empty tally.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaIsPassedOverAndAnUnreadableOneIsLogged(): void {
		$this->store->missingSchemas = ['decision'];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq: the Woo refusal ground mapping could not read decision',
			$this->callback(static fn (array $context): bool => $context['app'] === 'dossiq' && str_contains($context['error'], 'decision'))
		);

		$this->runStep($this->step(['register' => 'dossiq', 'decision_schema' => 'decision'], $this->grounds(['5.1.2e']), $logger));

		$this->assertSame(
			['Woo refusal ground mapping, decision_schema: 0 mapped, 0 failed, 0 with an unmapped code.'],
			$this->said
		);
	}//end testAnUnconfiguredSchemaIsPassedOverAndAnUnreadableOneIsLogged()

	/**
	 * The step names what it maps.
	 *
	 * @return void
	 */
	public function testTheStepNamesItself(): void {
		$this->assertSame(
			'Map the Woo refusal grounds stored on assessments and decisions onto the settled list',
			$this->step([], $this->grounds([]))->getName()
		);
	}//end testTheStepNamesItself()
}//end class
