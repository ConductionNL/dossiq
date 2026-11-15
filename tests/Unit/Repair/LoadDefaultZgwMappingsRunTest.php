<?php

/**
 * Tests for running the default ZGW mappings repair step.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\LoadDefaultZgwMappings;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwMappingService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What the step writes into a fresh and into an older instance, and how it degrades.
 *
 * @covers \OCA\Dossiq\Repair\LoadDefaultZgwMappings
 * @uses \OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
class LoadDefaultZgwMappingsRunTest extends TestCase {

	/**
	 * The stored mappings, by resource key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $mappings = [];

	/**
	 * What the output and the logger were told, in order.
	 *
	 * @var list<string>
	 */
	private array $said = [];

	/**
	 * The consumers the fake ConsumerMapper holds, by name.
	 *
	 * @var array<string, object>
	 */
	private array $consumers = [];

	/**
	 * The objects the fake ObjectService saved.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The server the test replaced.
	 *
	 * @var object|null
	 */
	private ?object $server = null;

	/**
	 * Keep the server so tearDown can put it back.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->server = \OC::$server;
	}//end setUp()

	/**
	 * Put the server back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		\OC::$server = $this->server;
		parent::tearDown();
	}//end tearDown()

	/**
	 * Without a register nothing is written.
	 *
	 * @return void
	 */
	public function testWithoutARegisterNothingIsLoaded(): void {
		$this->repair(register: '')->run(output: $this->recordingOutput());

		$this->assertSame(
			expected: ['info: Loading default ZGW API mappings...', 'warning: No Dossiq register configured yet. Skipping ZGW mapping defaults.'],
			actual: $this->said
		);
		$this->assertSame(expected: [], actual: $this->mappings);
	}//end testWithoutARegisterNothingIsLoaded()

	/**
	 * A fresh instance gets every mapping, the test applicaties and the channels; a channel that exists is skipped.
	 *
	 * @return void
	 */
	public function testAFreshInstanceGetsEverything(): void {
		$this->consumers = ['procest-admin' => $this->consumer()];
		$this->server(objects: $this->objects(existing: ['zaken'], refused: []));

		$repair = $this->repair(register: 'reg-1');
		$repair->run(output: $this->recordingOutput());

		$this->assertCount(expectedCount: count($repair->getDefaultMappings(registerId: 'reg-1')), haystack: $this->mappings);
		$this->assertContains(needle: 'info: Loaded 26 default ZGW mapping configurations.', haystack: $this->said);
		$this->assertContains(needle: 'info: Created 1, updated 1 default test applicaties.', haystack: $this->said);
		$this->assertContains(needle: 'info: Created 4 default notification channels.', haystack: $this->said);
		$this->assertCount(expectedCount: 4, haystack: $this->saved);
		$this->assertSame(expected: 'Load default ZGW API mapping configurations for Dossiq', actual: $repair->getName());
	}//end testAFreshInstanceGetsEverything()

	/**
	 * An older instance keeps its mappings, gets its empty schemas filled in and its old template patched.
	 *
	 * @return void
	 */
	public function testAnOlderInstanceIsHealedAndPatched(): void {
		$this->mappings = [
			'catalogus' => ['sourceSchema' => ''],
			'zaak' => ['sourceSchema' => 'chosen-on-purpose'],
			'enkelvoudiginformatieobject' => ['sourceSchema' => 'x', 'propertyMapping' => ['indicatieGebruiksrecht' => '{{ usageRightsIndication }}']],
			'kanaal' => ['sourceRegister' => 'reg-1', 'sourceSchema' => 'channel-s'],
		];
		$this->server(objects: $this->objects(existing: [], refused: ['catalogi']));

		$this->repair(register: 'reg-1')->run(output: $this->recordingOutput());

		$this->assertSame(expected: 'catalogus-s', actual: $this->mappings['catalogus']['sourceSchema']);
		$this->assertSame(expected: 'chosen-on-purpose', actual: $this->mappings['zaak']['sourceSchema']);
		$this->assertNotSame(
			expected: '{{ usageRightsIndication }}',
			actual: $this->mappings['enkelvoudiginformatieobject']['propertyMapping']['indicatieGebruiksrecht']
		);
		$this->assertContains(needle: 'info: Filled in the empty sourceSchema on the catalogus ZGW mapping.', haystack: $this->said);
		$this->assertContains(needle: 'info: Patched enkelvoudiginformatieobject mapping: indicatieGebruiksrecht template.', haystack: $this->said);
		$this->assertContains(needle: 'log-info: Dossiq: filled in empty ZGW mapping schemas', haystack: $this->said);
		$this->assertContains(
			needle: 'warning: Created 4 default notification channels; 1 REFUSED (catalogi). ZGW notifications on those channels cannot be delivered.',
			haystack: $this->said
		);
	}//end testAnOlderInstanceIsHealedAndPatched()

	/**
	 * Everything an older instance's run says, in order.
	 *
	 * @return void
	 */
	public function testTheRunSaysEverythingInOrder(): void {
		$this->mappings = [
			'catalogus' => ['sourceSchema' => ''],
			'zaak' => ['sourceSchema' => 'chosen-on-purpose'],
			'enkelvoudiginformatieobject' => ['sourceSchema' => 'x', 'propertyMapping' => ['indicatieGebruiksrecht' => '{{ usageRightsIndication }}']],
			'kanaal' => ['sourceRegister' => 'reg-1', 'sourceSchema' => 'channel-s'],
		];
		$this->server(objects: $this->objects(existing: [], refused: ['catalogi']));

		$this->repair(register: 'reg-1')->run(output: $this->recordingOutput());

		$this->assertSame(
			expected: [
				'info: Loading default ZGW API mappings...',
				'info: Loaded 22 default ZGW mapping configurations.',
				'info: Filled in the empty sourceSchema on the catalogus ZGW mapping.',
				'log-info: Dossiq: filled in empty ZGW mapping schemas',
				'info: Patched enkelvoudiginformatieobject mapping: indicatieGebruiksrecht template.',
				'info: Created 2, updated 0 default test applicaties.',
				'log-error: Dossiq: default notification channel refused',
				'warning: Created 4 default notification channels; 1 REFUSED (catalogi). ZGW notifications on those channels cannot be delivered.',
				'log-info: Dossiq: Default ZGW mappings loaded',
			],
			actual: $this->said
		);
	}//end testTheRunSaysEverythingInOrder()

	/**
	 * Without OpenRegister's services, or with a channel mapping that is not configured, the seeds are skipped.
	 *
	 * @return void
	 */
	public function testTheSeedsAreSkippedWhenTheyCannotRun(): void {
		$this->server(objects: null);
		$this->mappings = ['kanaal' => ['sourceRegister' => 'reg-1', 'sourceSchema' => 'channel-s']];
		$this->repair(register: 'reg-1')->run(output: $this->recordingOutput());
		$this->assertContains(needle: 'info: OpenRegister ConsumerMapper not available. Skipping default applicaties.', haystack: $this->said);
		$this->assertContains(needle: 'info: OpenRegister ObjectService not available. Skipping default channels.', haystack: $this->said);

		$this->said = [];
		$this->mappings = ['kanaal' => ['sourceRegister' => 'reg-1', 'sourceSchema' => '']];
		$this->repair(register: 'reg-1', schemas: false)->run(output: $this->recordingOutput());
		$this->assertContains(needle: 'info: Kanaal mapping has no register/schema configured yet. Skipping default channels.', haystack: $this->said);
	}//end testTheSeedsAreSkippedWhenTheyCannotRun()

	/**
	 * A seed that throws is reported and the step finishes; a missing channel mapping skips the channels.
	 *
	 * @return void
	 */
	public function testAThrowingSeedDoesNotAbortTheStep(): void {
		$this->server(objects: null, consumersThrow: true);
		$repair = $this->repair(register: 'reg-1', mappingsThrowFor: 'kanaal', drop: 'kanaal');
		$repair->run(output: $this->recordingOutput());

		$this->assertContains(needle: 'warning: Could not create default applicaties: consumers down', haystack: $this->said);
		$this->assertContains(needle: 'warning: Could not create default notification channels: mapping store down', haystack: $this->said);
		$this->assertContains(needle: 'log-info: Dossiq: Default ZGW mappings loaded', haystack: $this->said);

		$this->said = [];
		$this->mappings = [];
		$this->repair(register: 'reg-1', schemas: false, drop: 'kanaal')->run(output: $this->recordingOutput());
		$this->assertContains(needle: 'info: Kanaal mapping not configured. Skipping default channels.', haystack: $this->said);
	}//end testAThrowingSeedDoesNotAbortTheStep()

	/**
	 * The repair over an in-memory mapping store.
	 *
	 * @param string      $register         The configured register.
	 * @param bool        $schemas          Whether the settings name every schema.
	 * @param string|null $mappingsThrowFor A resource key whose read throws.
	 * @param string|null $drop             A resource key whose saves are dropped.
	 *
	 * @return LoadDefaultZgwMappings
	 */
	private function repair(string $register, bool $schemas = true, ?string $mappingsThrowFor = null, ?string $drop = null): LoadDefaultZgwMappings {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ['register' => $register][$key] ?? $default
		);
		$settings->method('getSettings')->willReturnCallback(
			static function () use ($schemas): array {
				if ($schemas === false) {
					return [];
				}

				return ['case_schema' => 'case-s', 'catalogus_schema' => 'catalogus-s', 'kanaal_schema' => 'channel-s'];
			}
		);

		$store = $this->createMock(originalClassName: ZgwMappingService::class);
		$store->method('getMapping')->willReturnCallback(
			function (string $resourceKey) use ($mappingsThrowFor): ?array {
				if ($resourceKey === $mappingsThrowFor) {
					throw new RuntimeException('mapping store down');
				}

				return ($this->mappings[$resourceKey] ?? null);
			}
		);
		$store->method('hasMapping')->willReturnCallback(fn (string $resourceKey): bool => isset($this->mappings[$resourceKey]));
		$store->method('saveMapping')->willReturnCallback(
			function (string $resourceKey, array $config) use ($drop): void {
				if ($resourceKey !== $drop) {
					$this->mappings[$resourceKey] = $config;
				}
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->said[] = 'log-' . $level . ': ' . $message;
				}
			);
		}

		return new LoadDefaultZgwMappings(zgwMappingService: $store, settingsService: $settings, logger: $logger);
	}//end repair()

	/**
	 * An output that records what it is told.
	 *
	 * @return IOutput
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(originalClassName: IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->said[] = $level . ': ' . $message;
				}
			);
		}

		return $output;
	}//end recordingOutput()

	/**
	 * Replace the server with one that hands out the fake mappers.
	 *
	 * @param object|null $objects        The fake ObjectService, or null when it is not available.
	 * @param bool        $consumersThrow Whether reading the consumers throws.
	 *
	 * @return void
	 */
	private function server(?object $objects, bool $consumersThrow = false): void {
		$consumers = null;
		if ($objects !== null || $consumersThrow === true) {
			$consumers = $this->consumerMapper(throws: $consumersThrow);
		}

		\OC::$server = new class ($consumers, $objects) {
			/**
			 * Constructor.
			 *
			 * @param object|null $consumers The consumer mapper.
			 * @param object|null $objects   The object service.
			 */
			public function __construct(private readonly ?object $consumers, private readonly ?object $objects) {
			}

			/**
			 * A service, or an exception when this test has none.
			 *
			 * @param string $id The service.
			 *
			 * @return object
			 */
			public function get(string $id): object {
				$services = [
					'OCA\\OpenRegister\\Db\\ConsumerMapper' => $this->consumers,
					'OCA\\OpenRegister\\Service\\ObjectService' => $this->objects,
				];
				$service = ($services[$id] ?? null);
				if ($service === null) {
					throw new RuntimeException('no ' . $id);
				}

				return $service;
			}
		};
	}//end server()

	/**
	 * A consumer the mapper already holds.
	 *
	 * @return object
	 */
	private function consumer(): object {
		return new class {
			/**
			 * The authorization configuration set on it.
			 *
			 * @var array<string, mixed>|null
			 */
			public ?array $config = null;

			/**
			 * Set the authorization configuration.
			 *
			 * @param array<string, mixed> $config The configuration.
			 *
			 * @return void
			 */
			public function setAuthorizationConfiguration(array $config): void {
				$this->config = $config;
			}

			/**
			 * Set the update time.
			 *
			 * @param \DateTime $updated The time.
			 *
			 * @return void
			 */
			public function setUpdated(\DateTime $updated): void {
			}
		};
	}//end consumer()

	/**
	 * A consumer mapper over this test's consumers.
	 *
	 * @param bool $throws Whether reading throws.
	 *
	 * @return object
	 */
	private function consumerMapper(bool $throws): object {
		return new class ($this, $throws) {
			/**
			 * Constructor.
			 *
			 * @param LoadDefaultZgwMappingsRunTest $test   The test holding the consumers.
			 * @param bool                          $throws Whether reading throws.
			 */
			public function __construct(private readonly LoadDefaultZgwMappingsRunTest $test, private readonly bool $throws) {
			}

			/**
			 * The consumers with a name.
			 *
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return list<object>
			 */
			public function findAll(array $filters = []): array {
				if ($this->throws === true) {
					throw new RuntimeException('consumers down');
				}

				return $this->test->consumersNamed(name: (string)$filters['name']);
			}

			/**
			 * Update a consumer.
			 *
			 * @param object $consumer The consumer.
			 *
			 * @return object
			 */
			public function update(object $consumer): object {
				return $consumer;
			}

			/**
			 * Create a consumer.
			 *
			 * @param array<string, mixed> $object The consumer.
			 *
			 * @return object
			 */
			public function createFromArray(array $object): object {
				return (object)$object;
			}
		};
	}//end consumerMapper()

	/**
	 * The consumers with this name.
	 *
	 * @param string $name The name.
	 *
	 * @return list<object>
	 */
	public function consumersNamed(string $name): array {
		if (isset($this->consumers[$name]) === true) {
			return [$this->consumers[$name]];
		}

		return [];
	}//end consumersNamed()

	/**
	 * An object service whose search finds the given channels and whose save refuses others.
	 *
	 * @param list<string> $existing The channel names already stored.
	 * @param list<string> $refused  The channel names a save refuses.
	 *
	 * @return object
	 */
	private function objects(array $existing, array $refused): object {
		return new class ($this, $existing, $refused) {
			/**
			 * Constructor.
			 *
			 * @param LoadDefaultZgwMappingsRunTest $test     The test keeping what was saved.
			 * @param list<string>                  $existing The stored channel names.
			 * @param list<string>                  $refused  The refused channel names.
			 */
			public function __construct(
				private readonly LoadDefaultZgwMappingsRunTest $test,
				private readonly array $existing,
				private readonly array $refused,
			) {
			}

			/**
			 * Build a query.
			 *
			 * @param array<string, mixed> $requestParams The parameters.
			 * @param string               $register      The register.
			 * @param string               $schema        The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function buildSearchQuery(array $requestParams, string $register, string $schema): array {
				return $requestParams;
			}

			/**
			 * Search.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query): array {
				return ['total' => (int)in_array($query['name'], $this->existing, true)];
			}

			/**
			 * Save.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $object   The object.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(string $register, string $schema, array $object): array {
				if (in_array($object['name'], $this->refused, true) === true) {
					throw new RuntimeException('refused');
				}

				$this->test->recordSaved(object: $object);
				return $object;
			}
		};
	}//end objects()

	/**
	 * Keep a saved object.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return void
	 */
	public function recordSaved(array $object): void {
		$this->saved[] = $object;
	}//end recordSaved()
}//end class
