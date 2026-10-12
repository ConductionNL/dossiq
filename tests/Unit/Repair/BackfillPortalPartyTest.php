<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/site-business-and-authorisation/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\BackfillPortalParty;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Task 2.4: a case filed before portalParty existed gets subject:<portalSubject>, once.
 */
class BackfillPortalPartyTest extends TestCase {
	/**
	 * @return void
	 */
	public function testACaseWithASubjectAndNoPartyGetsOneAndNothingElseChanges(): void {
		$objects = new class {
			/** @var array<string, array<string, mixed>> The cases by id. */
			public array $cases = [];

			/** @var int How many writes ran. */
			public int $writes = 0;

			/** @var bool Whether the run is under the system identity. */
			public bool $asSystem = false;

			/** @var bool Whether a write ran outside it. */
			public bool $wroteAsCaller = false;

			/**
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> One page.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				if ((int)($filters['_page'] ?? 1) > 1) {
					return [];
				}

				return array_values($this->cases);
			}

			/**
			 * @param string $objectId The case.
			 * @param array<string, mixed> $data The fields.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return array<string, mixed> The case.
			 */
			public function patchObject(string $objectId, array $data, string $register, string $schema): array {
				$this->writes++;
				if ($this->asSystem === false) {
					$this->wroteAsCaller = true;
				}

				$this->cases[$objectId] = array_merge($this->cases[$objectId], $data);
				return $this->cases[$objectId];
			}

			/**
			 * @param callable $work The work.
			 *
			 * @return void
			 */
			public function runAsSystem(callable $work): void {
				$this->asSystem = true;
				$work();
				$this->asSystem = false;
			}
		};
		$objects->cases = [
			'c-1' => ['id' => 'c-1', 'portalSubject' => 'subj-anna'],
			'c-2' => ['id' => 'c-2', 'portalSubject' => 'subj-bob', 'portalParty' => 'kvk:12345678'],
			'c-3' => ['id' => 'c-3'],
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
		$step = new BackfillPortalParty(settingsService: $settings, appConfig: $appConfig, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains(string: '1 case(s)'));

		$step->run(output: $output);
		$step->run(output: $output);

		$this->assertSame('subject:subj-anna', $objects->cases['c-1']['portalParty']);
		$this->assertSame('kvk:12345678', $objects->cases['c-2']['portalParty'], 'a party already set stays');
		$this->assertArrayNotHasKey('portalParty', $objects->cases['c-3']);
		$this->assertSame(1, $objects->writes, 'the second run is stopped by the version key');
		$this->assertFalse($objects->wroteAsCaller);

		$register = new RealSchemaValidator();
		$this->assertSame([], $register->errors(slug: 'case', payload: ['portalParty' => 'subject:subj-anna'], creating: false));
		$this->assertNotSame([], $register->errors(slug: 'case', payload: ['portalParty' => '123456782'], creating: false), 'a bare number, such as a BSN, is refused');
	}//end testACaseWithASubjectAndNoPartyGetsOneAndNothingElseChanges()
}//end class
