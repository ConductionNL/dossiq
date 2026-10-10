<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/portal-contact-channel-follows-the-resident/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\ReportCommunicationChannelValues;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Task 1.1: the channel property takes a slug, and the cases that hold anything else are named.
 */
class ReportCommunicationChannelValuesTest extends TestCase {
	/**
	 * @return void
	 */
	public function testCasesOffTheSlugsAreNamedAndNothingIsWritten(): void {
		$objects = new class {
			/** @var array<int, array<string, mixed>> The cases. */
			public array $cases = [];

			/** @var int How many writes were attempted. */
			public int $writes = 0;

			/**
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> One page.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return ((int)($filters['_page'] ?? 1) > 1) ? [] : $this->cases;
			}

			/**
			 * @return array<string, mixed> Nothing useful.
			 */
			public function saveObject(): array {
				$this->writes++;
				return [];
			}
		};
		$objects->cases = [
			['id' => 'c-1', 'communicationChannel' => 'post'],
			['id' => 'c-2', 'communicationChannel' => 'https://zaken.example/api/v1/kanalen/7'],
			['id' => 'c-3'],
			['@self' => ['id' => 'c-4'], 'communicationChannel' => 'balie'],
		];
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? $default)
		);
		$config = [];
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$config): string {
				return ($config[$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$config): bool {
				$config[$key] = $value;
				return true;
			}
		);
		$warnings = [];
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains(string: '2 case(s)'));

		$step = new ReportCommunicationChannelValues(settingsService: $settings, appConfig: $appConfig, logger: $logger);
		$step->run(output: $output);
		$step->run(output: $output);

		$this->assertCount(2, $warnings, 'the second run is stopped by the version key');
		$this->assertStringContainsString('c-2', $warnings[0]);
		$this->assertStringContainsString('c-4', $warnings[1]);
		$this->assertSame(0, $objects->writes, 'a person decides; the step writes nothing');
	}//end testCasesOffTheSlugsAreNamedAndNothingIsWritten()

	/**
	 * The case schema takes every slug and still takes a ZGW channel URL.
	 *
	 * @return void
	 */
	public function testTheCaseSchemaTakesASlugAndAZgwUrl(): void {
		$register = new RealSchemaValidator();
		$property = $register->schemas['case']['properties']['communicationChannel'];
		$this->assertArrayNotHasKey('format', $property, 'a slug is no URI');
		foreach ([...ReportCommunicationChannelValues::SLUGS, 'https://zaken.example/api/v1/kanalen/7'] as $value) {
			$this->assertSame([], $register->errors(slug: 'case', payload: ['communicationChannel' => $value], creating: false), $value);
		}
	}//end testTheCaseSchemaTakesASlugAndAZgwUrl()
}//end class
