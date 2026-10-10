<?php

/**
 * Tests for the repair step that turns stored channels into slugs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\NormaliseCommunicationChannelValues;
use OCA\Dossiq\Service\CommunicationChannel;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @spec openspec/changes/portal-contact-channel-follows-the-resident/specs/portal-contribution/spec.md
 */
class NormaliseCommunicationChannelValuesTest extends TestCase {

	/**
	 * The stored config the version gate reads and writes.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Run the step over these cases.
	 *
	 * @param array<int, array<string, mixed>> $cases  The stored cases.
	 * @param array<int, string>               $refuse Case ids whose write fails.
	 *
	 * @return object The object service double, holding the writes.
	 */
	private function runStep(array $cases, array $refuse = []): object {
		$objects = new class($cases, $refuse) {
			/** @var array<string, array<string, mixed>> The patches, by case id. */
			public array $patches = [];

			/**
			 * @param array<int, array<string, mixed>> $cases  The cases.
			 * @param array<int, string>               $refuse Ids whose write fails.
			 */
			public function __construct(public array $cases, private array $refuse) {
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> One page.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return ((int)($filters['_page'] ?? 1) > 1) ? [] : $this->cases;
			}

			/**
			 * @param string               $objectId The id.
			 * @param array<string, mixed> $data     The changes.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(string $objectId, array $data, mixed $register, mixed $schema): array {
				if (in_array($objectId, $this->refuse, true) === true) {
					throw new RuntimeException('refused');
				}

				$this->patches[$objectId] = $data;
				return $data;
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? $default)
		);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$step = new NormaliseCommunicationChannelValues(
			settingsService: $settings,
			appConfig: $appConfig,
			channels: new CommunicationChannel(),
			logger: new NullLogger()
		);
		$step->run(output: $this->createMock(IOutput::class));

		return $objects;
	}//end run()

	/**
	 * A URL becomes `zgw-api`, words become their slug, an unknown word an
	 * empty channel; the old value is kept, and a slug or no value is left alone.
	 *
	 * @return void
	 */
	public function testStoredValuesBecomeSlugsAndKeepWhatTheyHeld(): void {
		$url = 'https://zaken.example/api/v1/kanalen/7';
		$objects = $this->runStep(
			cases: [
				['id' => 'c-1', 'communicationChannel' => 'post'],
				['id' => 'c-2', 'communicationChannel' => $url],
				['id' => 'c-3'],
				['@self' => ['id' => 'c-4'], 'communicationChannel' => 'Brief'],
				['id' => 'c-5', 'communicationChannel' => 'balie'],
			]
		);

		$this->assertSame(
			[
				'c-2' => ['communicationChannel' => 'zgw-api', 'communicationChannelSource' => $url],
				'c-4' => ['communicationChannel' => 'post', 'communicationChannelSource' => 'Brief'],
				'c-5' => ['communicationChannel' => null, 'communicationChannelSource' => 'balie'],
			],
			$objects->patches
		);
		$this->assertSame(NormaliseCommunicationChannelValues::DONE_VERSION, $this->config[NormaliseCommunicationChannelValues::DONE_KEY]);

		// Every write the step makes is one the slug enum accepts.
		$register = new RealSchemaValidator();
		foreach ($objects->patches as $id => $patch) {
			$this->assertSame([], $register->errors(slug: 'case', payload: $patch, creating: false), $id);
		}
	}//end testStoredValuesBecomeSlugsAndKeepWhatTheyHeld()

	/**
	 * A case that cannot be written keeps the gate open for the next run, and
	 * a finished run is not repeated.
	 *
	 * @return void
	 */
	public function testAFailedWriteRunsAgainAndAFinishedRunDoesNot(): void {
		$objects = $this->runStep(cases: [['id' => 'c-1', 'communicationChannel' => 'Brief']], refuse: ['c-1']);
		$this->assertSame([], $objects->patches);
		$this->assertArrayNotHasKey(NormaliseCommunicationChannelValues::DONE_KEY, $this->config);

		$this->config[NormaliseCommunicationChannelValues::DONE_KEY] = NormaliseCommunicationChannelValues::DONE_VERSION;
		$this->assertSame([], $this->runStep(cases: [['id' => 'c-9', 'communicationChannel' => 'Brief']])->patches);
	}//end testAFailedWriteRunsAgainAndAFinishedRunDoesNot()
}//end class
