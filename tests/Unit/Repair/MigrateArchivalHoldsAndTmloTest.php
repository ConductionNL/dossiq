<?php

/**
 * What the archival migration does to the register's TMLO flag and to suspended cases' legal holds.
 *
 * The step only resolves OpenRegister classes that exist, and a unit run has
 * none of LegalHoldService, MagicMapper or RegisterMapper. Each test declares
 * them inside its own process (so no other test sees them) and hands the step
 * recording fakes through the container.
 *
 * Characterisation written against the unsplit methods and run green on them
 * before they were split.
 *
 * @category Tests
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

use OCA\Dossiq\Repair\MigrateArchivalToOpenRegister;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Repair\MigrateArchivalToOpenRegister
 */
class MigrateArchivalHoldsAndTmloTest extends TestCase {

	/**
	 * Every call the fakes saw, and every log line, in order.
	 *
	 * @var array<int, string>
	 */
	public array $calls = [];

	/**
	 * Declare the OpenRegister classes the step checks for, in this process only.
	 *
	 * @return void
	 */
	private function declareOpenRegisterClasses(): void {
		foreach ([
			'OCA\\OpenRegister\\Service\\Archival' => 'LegalHoldService',
			'OCA\\OpenRegister\\Db' => 'MagicMapper',
		] as $namespace => $class) {
			if (class_exists($namespace . '\\' . $class) === false) {
				eval('namespace ' . $namespace . '; class ' . $class . ' {}');
			}
		}

		if (class_exists('OCA\\OpenRegister\\Db\\RegisterMapper') === false) {
			eval('namespace OCA\\OpenRegister\\Db; class RegisterMapper {}');
		}
	}//end declareOpenRegisterClasses()

	/**
	 * The step over recording fakes.
	 *
	 * @param array<string, object> $services Fakes by class name suffix.
	 * @param object|null           $store    The object service.
	 * @param array<string, string> $config   Config values.
	 *
	 * @return MigrateArchivalToOpenRegister
	 */
	private function step(array $services, ?object $store, array $config): MigrateArchivalToOpenRegister {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				foreach ($services as $suffix => $service) {
					if (str_ends_with($id, $suffix) === true) {
						return $service;
					}
				}

				throw new \RuntimeException('no ' . $id);
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = 'warning ' . $message . ' ' . json_encode($context);
			}
		);

		return new MigrateArchivalToOpenRegister($settings, $container, $this->createMock(IAppConfig::class), $logger);
	}//end step()

	/**
	 * Call a private method of the step.
	 *
	 * @param MigrateArchivalToOpenRegister $step   The step.
	 * @param string                        $method The method.
	 * @param array<int, mixed>             $args   Positional arguments.
	 *
	 * @return mixed
	 */
	private function call(MigrateArchivalToOpenRegister $step, string $method, array $args): mixed {
		return (new \ReflectionMethod($step, $method))->invokeArgs($step, $args);
	}//end call()

	/**
	 * A register entity that records its configuration.
	 *
	 * @param mixed $configuration What getConfiguration() answers.
	 *
	 * @return object
	 */
	private function registerEntity(mixed $configuration): object {
		$test = $this;
		return new class($test, $configuration) {
			/**
			 * @param MigrateArchivalHoldsAndTmloTest $test          The test.
			 * @param mixed                           $configuration The configuration.
			 */
			public function __construct(private readonly MigrateArchivalHoldsAndTmloTest $test, private mixed $configuration) {
			}//end __construct()

			/**
			 * @return mixed
			 */
			public function getConfiguration(): mixed {
				return $this->configuration;
			}

			/**
			 * @param array<string, mixed> $configuration The configuration.
			 *
			 * @return void
			 */
			public function setConfiguration(array $configuration): void {
				$this->test->calls[] = 'set ' . json_encode($configuration);
				$this->configuration = $configuration;
			}
		};
	}//end registerEntity()

	/**
	 * TMLO is switched on once, keeping the rest of the configuration; anything unreadable is left alone or logged.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testTmloIsEnabledOnceAndUnreadableRegistersAreLeftAlone(): void {
		$this->declareOpenRegisterClasses();
		$test = $this;
		$entities = [
			'r-null' => $this->registerEntity(null),
			'r-kept' => $this->registerEntity(['other' => 1]),
			'r-on' => $this->registerEntity(['tmloEnabled' => true]),
			'r-text' => $this->registerEntity('text'),
			'r-plain' => new \stdClass(),
		];
		$mapper = new class($test, $entities) {
			/**
			 * @param MigrateArchivalHoldsAndTmloTest $test     The test.
			 * @param array<string, object>           $entities The registers.
			 */
			public function __construct(private readonly MigrateArchivalHoldsAndTmloTest $test, private readonly array $entities) {
			}//end __construct()

			/**
			 * @param string $register The register.
			 *
			 * @return mixed
			 */
			public function find(string $register): mixed {
				$this->test->calls[] = 'find ' . $register;
				if ($register === 'r-boom') {
					throw new \RuntimeException('mapper down');
				}

				return ($this->entities[$register] ?? 'not an entity');
			}

			/**
			 * @param object $entity The register.
			 *
			 * @return void
			 */
			public function update(object $entity): void {
				$this->test->calls[] = 'update';
			}
		};
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->calls[] = 'info ' . $message;
			}
		);
		$step = $this->step(services: ['RegisterMapper' => $mapper], store: null, config: []);

		foreach (['r-null', 'r-kept', 'r-on', 'r-text', 'r-plain', 'r-none', 'r-boom'] as $register) {
			$this->call($step, 'enableTmlo', [$register, $output]);
		}

		$this->assertSame(
			[
				'find r-null',
				'set {"tmloEnabled":true}',
				'update',
				'info TMLO auto-population enabled on the dossiq register.',
				'find r-kept',
				'set {"other":1,"tmloEnabled":true}',
				'update',
				'info TMLO auto-population enabled on the dossiq register.',
				'find r-on',
				'find r-text',
				'set {"tmloEnabled":true}',
				'update',
				'info TMLO auto-population enabled on the dossiq register.',
				'find r-plain',
				'find r-none',
				'find r-boom',
				'warning Archival migration: could not enable TMLO on register {"error":"mapper down"}',
			],
			$this->calls
		);
	}//end testTmloIsEnabledOnceAndUnreadableRegistersAreLeftAlone()

	/**
	 * Every suspended trigger's case gets a hold unless it has one; the count is of new holds only.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testSuspendedTriggersPlaceAHoldOnTheirCase(): void {
		$this->declareOpenRegisterClasses();
		$test = $this;
		$store = new class($test) {
			/**
			 * Rows to answer, or a Throwable.
			 *
			 * @var mixed
			 */
			public mixed $rows = [];

			/**
			 * @param MigrateArchivalHoldsAndTmloTest $test The test.
			 */
			public function __construct(private readonly MigrateArchivalHoldsAndTmloTest $test) {
			}//end __construct()

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, mixed>
			 */
			public function searchObjects(array $query): array {
				$this->test->calls[] = 'search ' . json_encode($query);
				if ($this->rows instanceof \Throwable) {
					throw $this->rows;
				}

				return $this->rows;
			}
		};
		$mapper = new class($test) {
			/**
			 * @param MigrateArchivalHoldsAndTmloTest $test The test.
			 */
			public function __construct(private readonly MigrateArchivalHoldsAndTmloTest $test) {
			}//end __construct()

			/**
			 * @param string $uuid The case.
			 *
			 * @return object|null
			 */
			public function findByUuid(string $uuid): ?object {
				$this->test->calls[] = 'find ' . $uuid;
				if ($uuid === 'case-missing') {
					return null;
				}

				$case = new \stdClass();
				$case->id = $uuid;
				return $case;
			}
		};
		$holds = new class($test) {
			/**
			 * @param MigrateArchivalHoldsAndTmloTest $test The test.
			 */
			public function __construct(private readonly MigrateArchivalHoldsAndTmloTest $test) {
			}//end __construct()

			/**
			 * @param object $object The case.
			 *
			 * @return bool
			 */
			public function hasActiveHold(object $object): bool {
				if ($object->id === 'case-boom') {
					throw new \RuntimeException('hold store down');
				}

				return $object->id === 'case-held';
			}

			/**
			 * @param object $object The case.
			 * @param object $order  The order.
			 *
			 * @return void
			 */
			public function placeHold(object $object, object $order): void {
				$this->test->calls[] = 'place ' . $object->id . ': ' . $order->reason;
			}
		};
		$services = ['LegalHoldService' => $holds, 'MagicMapper' => $mapper];
		$config = ['overdracht_trigger_schema' => '5'];

		$store->rows = [['caseId' => 'case-1'], ['caseId' => ''], [], ['caseId' => 'case-held'], ['caseId' => 'case-missing'], ['caseId' => 'case-boom'], ['caseId' => 'case-2']];
		$placed = $this->call($this->step(services: $services, store: $store, config: $config), 'placeHoldsForSuspendedTriggers', ['3']);
		$this->assertSame(2, $placed);

		$store->rows = new \RuntimeException('search down');
		$this->assertSame(0, $this->call($this->step(services: $services, store: $store, config: $config), 'placeHoldsForSuspendedTriggers', ['3']));
		$this->assertSame(0, $this->call($this->step(services: $services, store: $store, config: []), 'placeHoldsForSuspendedTriggers', ['3']));
		$this->assertSame(0, $this->call($this->step(services: $services, store: null, config: $config), 'placeHoldsForSuspendedTriggers', ['3']));
		$this->assertSame(0, $this->call($this->step(services: ['MagicMapper' => $mapper], store: $store, config: $config), 'placeHoldsForSuspendedTriggers', ['3']));

		$reason = 'Awb-procedure — gemigreerd uit OverdrachtTrigger (opgeschort-juridische-procedure)';
		$this->assertSame(
			[
				'search {"status":"opgeschort-juridische-procedure","@self":{"register":3,"schema":5}}',
				'find case-1',
				'place case-1: ' . $reason,
				'find case-held',
				'find case-missing',
				'find case-boom',
				'warning Archival migration: hold placement failed {"caseId":"case-boom","error":"hold store down"}',
				'find case-2',
				'place case-2: ' . $reason,
				'search {"status":"opgeschort-juridische-procedure","@self":{"register":3,"schema":5}}',
				'warning Archival migration: could not read suspended triggers {"error":"search down"}',
			],
			$this->calls
		);
	}//end testSuspendedTriggersPlaceAHoldOnTheirCase()
}//end class
