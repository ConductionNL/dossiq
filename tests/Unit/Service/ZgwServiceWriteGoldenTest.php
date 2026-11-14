<?php

/**
 * Golden test for the generic ZGW create, update and delete handlers.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use ArrayObject;
use OCA\Dossiq\Service\NotificatieService;
use OCA\Dossiq\Service\ZgwBusinessRulesService;
use OCA\Dossiq\Service\ZgwMappingService;
use OCA\Dossiq\Service\ZgwService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every store call, rule check, mapping, notification, log line and answer of a ZGW write.
 *
 * @covers \OCA\Dossiq\Service\ZgwService
 * @covers \OCA\Dossiq\Service\Zgw\StoreFailureAnswer
 * @covers \OCA\Dossiq\Service\Zgw\ZgwPatchMerger
 * @covers \OCA\Dossiq\Service\Zgw\ResourceRoute
 * @covers \OCA\Dossiq\Service\Zgw\ResourceWrite
 * @covers \OCA\Dossiq\Service\Zgw\RuleFlags
 * @uses \OCA\Dossiq\Service\Zgw\ZgwInboundMapping
 * @uses \OCA\Dossiq\Service\Zgw\ZgwOutboundMapping
 */
class ZgwServiceWriteGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	private const UUID = '0a1b2c3d-0000-4000-a000-000000000001';

	/**
	 * The mapping configuration the tests start from.
	 *
	 * @return array<string, mixed>
	 */
	private static function mapping(): array {
		return [
			'sourceRegister' => '5',
			'sourceSchema' => '50',
			'zgwResource' => 'thing',
			'propertyMapping' => ['naam' => '{{ name }}'],
			'reverseMapping' => ['name' => '{{ naam }}'],
			'inboundNullable' => ['endDate'],
			'nullableFields' => ['einde'],
		];
	}//end mapping()

	/**
	 * Run one handler and answer its response plus every call it made.
	 *
	 * @param string               $handler The handler: create, update or destroy.
	 * @param array<string, mixed> $args    The handler's arguments besides the request.
	 * @param array<string, mixed> $world   The request, the store and the collaborators.
	 *
	 * @return array<string, mixed> The status, data and trace.
	 */
	private function observe(string $handler, array $args, array $world = []): array {
		$trace = new ArrayObject();

		$store = new class($world, $trace) {
			/**
			 * @param array<string, mixed> $world The store's behaviour.
			 * @param ArrayObject          $trace Every call.
			 */
			public function __construct(private array $world, private ArrayObject $trace) {
			}

			/**
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return mixed The object.
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): mixed {
				$this->trace->append(['find', $id, $register, $schema]);
				$existing = (array_key_exists('existing', $this->world) === true ? $this->world['existing'] : ['id' => $id, 'name' => 'Oud', 'kept' => 'ja']);
				if ($existing === 'missing') {
					throw new DoesNotExistException('Object not found');
				}

				if ($existing === 'down') {
					throw new RuntimeException('store down');
				}

				if ($existing === 'object') {
					return new class($id) implements \JsonSerializable {
						public function __construct(private string $id) {
						}

						public function jsonSerialize(): array {
							return ['id' => $this->id, 'name' => 'Als object'];
						}
					};
				}

				return $existing;
			}

			/**
			 * @param mixed       $register The register.
			 * @param mixed       $schema   The schema.
			 * @param array       $object   The object.
			 * @param string|null $uuid     The id to update.
			 *
			 * @return mixed The stored object.
			 */
			public function saveObject(mixed $register = null, mixed $schema = null, array $object = [], ?string $uuid = null): mixed {
				$this->trace->append(['save', $register, $schema, $object, $uuid]);
				$answer = ($this->world['save'] ?? 'array');
				if ($answer === 'throw') {
					throw new RuntimeException('save refused');
				}

				if ($answer === 'self') {
					return new class($object) implements \JsonSerializable {
						public function __construct(private array $object) {
						}

						public function jsonSerialize(): array {
							unset($this->object['id']);
							return array_merge($this->object, ['@self' => ['id' => 'self-id', 'created' => 'c', 'updated' => 'u']]);
						}
					};
				}

				return array_merge(['id' => ($uuid ?? 'new-id')], $object);
			}

			/**
			 * @param string $uuid The id.
			 *
			 * @return bool Whether it was deleted.
			 */
			public function deleteObject(string $uuid): bool {
				$this->trace->append(['delete', $uuid]);
				if (($this->world['delete'] ?? '') === 'throw') {
					throw new RuntimeException('delete refused');
				}

				return true;
			}
		};

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(
			function () use ($world, $trace): array {
				$trace->append(['getParams']);
				return ($world['body'] ?? ['naam' => 'Nieuw', 'zgwApi' => 'zaken', 'resource' => 'zaken', '_directFields' => ['tags' => ['a']]]);
			}
		);
		$request->method('getRequestUri')->willReturn(($world['uri'] ?? '/index.php/apps/dossiq/api/zgw/zaken/v1/zaken/' . self::UUID));
		$request->method('getServerHost')->willReturn('host.example');
		$request->method('getServerProtocol')->willReturn('https');

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level, $trace): void {
					$trace->append(['log', $level, $message, array_map(static fn (mixed $value): mixed => (is_scalar($value) === true ? (str_starts_with((string)$value, '#0 ') === true ? '<trace>' : $value) : get_debug_type($value)), $context)]);
				}
			);
		}

		$mappings = $this->createMock(ZgwMappingService::class);
		$mappings->method('getMapping')->willReturnCallback(
			function (string $resourceKey) use ($world, $trace): ?array {
				$trace->append(['getMapping', $resourceKey]);
				return (($world['mapping'] ?? true) === true ? self::mapping() : null);
			}
		);

		$rules = $this->createMock(ZgwBusinessRulesService::class);
		$rules->method('validate')->willReturnCallback(
			function (mixed ...$values) use ($world, $trace): array {
				$names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), (new \ReflectionMethod(ZgwBusinessRulesService::class, 'validate'))->getParameters());
				$args = array_combine(array_slice($names, 0, count($values)), $values);
				$args['objectService'] = (is_object($args['objectService'] ?? null) === true ? 'store' : null);
				$trace->append(['validate', $args]);
				if (isset($world['refuse']) === true) {
					return array_merge(['valid' => false, 'status' => 400, 'detail' => 'Nee'], $world['refuse']);
				}

				return ['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => array_merge($args['body'], ($world['enrich'] ?? []))];
			}
		);

		$notifications = $this->createMock(NotificatieService::class);
		$notifications->method('publish')->willReturnCallback(
			function (mixed ...$values) use ($world, $trace): void {
				$trace->append(['publish', array_slice($values, 0, 5)]);
				if (($world['notify'] ?? '') === 'throw') {
					throw new RuntimeException('notification channel down');
				}
			}
		);

		$mapper = new class($trace) {
			/**
			 * @param ArrayObject $trace Every call.
			 */
			public function __construct(private ArrayObject $trace) {
			}

			/**
			 * @param object $mapping The mapping.
			 * @param array  $input   The input.
			 *
			 * @return array The input with every string value tagged.
			 */
			public function executeMapping(object $mapping, array $input): array {
				$this->trace->append(['executeMapping', $mapping->name, $input]);
				unset($input['_valueMappings'], $input['_baseUrl']);
				$input['endDate'] = '';
				$input['einde'] = '';
				return $input;
			}
		};

		$service = (new \ReflectionClass(ZgwServiceWithPlainMappings::class))->newInstanceWithoutConstructor();
		\Closure::bind(
			static function () use ($service, $store, $logger, $mappings, $rules, $notifications, $mapper, $world): void {
				$service->logger               = $logger;
				$service->objectService        = (($world['service'] ?? true) === true ? $store : null);
				$service->zgwMappingService    = $mappings;
				$service->businessRulesService = $rules;
				$service->notificationService  = $notifications;
				$service->mappingService       = $mapper;
			},
			null,
			ZgwService::class
		)();

		$response = match ($handler) {
			'create' => $service->handleCreate($request, ...$args),
			'update' => $service->handleUpdate($request, ...$args),
			'destroy' => $service->handleDestroy($request, ...$args),
		};

		return ['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $trace->getArrayCopy()];
	}//end observe()

	/**
	 * Every path through handleCreate, handleUpdate and handleDestroy, recorded on the code before the split.
	 *
	 * @return void
	 */
	public function testGoldenWrites(): void {
		$zaak = ['zgwApi' => 'zaken', 'resource' => 'zaken'];
		$withUuid = array_merge($zaak, ['uuid' => 'arg-uuid']);
		$flags = ['caseClosed' => true, 'hasForceer' => false];
		$observed = [
			'create' => $this->observe('create', $zaak),
			'create with flags' => $this->observe('create', array_merge($zaak, $flags, ['parentCaseTypeDraft' => true])),
			'create without a store' => $this->observe('create', $zaak, ['service' => false]),
			'create of an unknown resource' => $this->observe('create', ['zgwApi' => 'zaken', 'resource' => 'onbekend']),
			'create without a mapping' => $this->observe('create', $zaak, ['mapping' => false]),
			'create refused by a rule' => $this->observe('create', $zaak, ['refuse' => ['status' => 409, 'code' => 'c-1', 'invalidParams' => [['name' => 'naam']]]]),
			'create refused without params' => $this->observe('create', $zaak, ['refuse' => ['invalidParams' => []]]),
			'create without direct fields' => $this->observe('create', $zaak, ['body' => ['naam' => 'Kaal']]),
			'create whose save answers an object' => $this->observe('create', $zaak, ['save' => 'self']),
			'create whose rules enrich the body' => $this->observe('create', $zaak, ['enrich' => ['naam' => 'Verrijkt']]),
			'create whose save fails' => $this->observe('create', $zaak, ['save' => 'throw']),
			'update' => $this->observe('update', $withUuid),
			'update with the uuid from the arguments' => $this->observe('update', $withUuid, ['uri' => '/zaken/geen-uuid']),
			'update with flags' => $this->observe('update', array_merge($withUuid, $flags, ['parentZtDraft' => false])),
			'patch' => $this->observe('update', array_merge($withUuid, ['partial' => true])),
			'patch whose rules add a direct field' => $this->observe('update', array_merge($withUuid, ['partial' => true]), ['enrich' => ['_directFields' => ['tags' => ['b'], 'name' => 'Direct']]]),
			'patch of an object' => $this->observe('update', array_merge($withUuid, ['partial' => true]), ['existing' => 'object']),
			'update without a store' => $this->observe('update', $withUuid, ['service' => false]),
			'update without a mapping' => $this->observe('update', $withUuid, ['mapping' => false]),
			'update of a missing object' => $this->observe('update', $withUuid, ['existing' => 'missing']),
			'update of a null object' => $this->observe('update', $withUuid, ['existing' => null]),
			'update on a store that is down' => $this->observe('update', $withUuid, ['existing' => 'down']),
			'update refused by a rule' => $this->observe('update', $withUuid, ['refuse' => ['status' => 400]]),
			'update whose save fails' => $this->observe('update', $withUuid, ['save' => 'throw']),
			'destroy' => $this->observe('destroy', $withUuid),
			'destroy with flags' => $this->observe('destroy', array_merge($withUuid, $flags, ['parentZtDraft' => true])),
			'destroy without a store' => $this->observe('destroy', $withUuid, ['service' => false]),
			'destroy without a mapping' => $this->observe('destroy', $withUuid, ['mapping' => false]),
			'destroy of a missing object' => $this->observe('destroy', $withUuid, ['existing' => 'missing']),
			'destroy on a store that is down' => $this->observe('destroy', $withUuid, ['existing' => 'down']),
			'destroy refused by a rule' => $this->observe('destroy', $withUuid, ['refuse' => ['status' => 403, 'code' => 'nee']]),
			'destroy whose delete fails' => $this->observe('destroy', $withUuid, ['delete' => 'throw']),
			'destroy whose notification fails' => $this->observe('destroy', $withUuid, ['notify' => 'throw']),
		];

		$this->assertMatchesGolden('zgw-service-writes', $observed);
	}//end testGoldenWrites()
}//end class

/**
 * ZgwService with mapping entities as plain objects, so no OpenRegister Mapping class is needed.
 */
class ZgwServiceWithPlainMappings extends ZgwService {
	/**
	 * @param array $mappingConfig The ZGW mapping configuration.
	 *
	 * @return object The outbound mapping.
	 */
	public function createOutboundMapping(array $mappingConfig): object {
		return (object)['name' => 'out', 'mapping' => $mappingConfig['propertyMapping'] ?? []];
	}

	/**
	 * @param array $mappingConfig The ZGW mapping configuration.
	 *
	 * @return object The inbound mapping.
	 */
	public function createInboundMapping(array $mappingConfig): object {
		return (object)['name' => 'in', 'mapping' => $mappingConfig['reverseMapping'] ?? []];
	}
}//end class
