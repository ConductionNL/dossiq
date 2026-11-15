<?php

/**
 * How the grants gateway finds the case schema and the case it asks about.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\OpenRegisterGrantsGateway;
use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The schema and object lookups behind a provenance question, and their failures.
 *
 * @covers \OCA\Dossiq\Service\Access\OpenRegisterGrantsGateway
 */
class OpenRegisterGrantsGatewayLookupTest extends TestCase {

	/**
	 * The permission handler service id.
	 *
	 * @var string
	 */
	private const HANDLER = 'OCA\OpenRegister\Service\Object\PermissionHandler';

	/**
	 * The schema mapper service id.
	 *
	 * @var string
	 */
	private const MAPPER = 'OCA\OpenRegister\Db\SchemaMapper';

	/**
	 * A permission handler that records what it was asked and answers a dictated value.
	 *
	 * @param mixed $answer What provenanceFor answers.
	 *
	 * @return object The handler, with a public `$asked` log.
	 */
	private function handler(mixed $answer): object {
		return new class($answer) {
			/**
			 * The calls received.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $asked = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $answer The answer.
			 */
			public function __construct(private readonly mixed $answer) {
			}

			/**
			 * Records the question and answers.
			 *
			 * @param mixed $schema  The schema.
			 * @param mixed $actions The actions.
			 * @param mixed $userId  The user.
			 * @param mixed $object  The case object.
			 *
			 * @return mixed The dictated answer.
			 */
			public function provenanceFor($schema = null, $actions = [], $userId = null, $object = null): mixed {
				$this->asked[] = compact('schema', 'actions', 'userId', 'object');
				return $this->answer;
			}
		};
	}//end handler()

	/**
	 * A schema mapper answering, or throwing, for any slug.
	 *
	 * @param mixed $schema What find answers; a Throwable is thrown.
	 *
	 * @return object The mapper, with a public `$slugs` log.
	 */
	private function mapper(mixed $schema): object {
		return new class($schema) {
			/**
			 * The slugs looked up.
			 *
			 * @var array<int, string>
			 */
			public array $slugs = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $schema The answer.
			 */
			public function __construct(private readonly mixed $schema) {
			}

			/**
			 * Look a schema up.
			 *
			 * @param string $slug The slug.
			 *
			 * @return mixed The schema.
			 */
			public function find(string $slug): mixed {
				$this->slugs[] = $slug;
				if ($this->schema instanceof \Throwable) {
					throw $this->schema;
				}

				return $this->schema;
			}
		};
	}//end mapper()

	/**
	 * Build the gateway.
	 *
	 * @param array<string, object> $services      Container service id to service.
	 * @param array<string, string> $config        Dossiq config key to value.
	 * @param object|null           $objectService The object service.
	 * @param LoggerInterface|null  $logger        The logger.
	 *
	 * @return OpenRegisterGrantsGateway The gateway.
	 */
	private function gateway(array $services, array $config, ?object $objectService = null, ?LoggerInterface $logger = null): OpenRegisterGrantsGateway {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $app === 'openregister');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new RuntimeException('nothing answers to ' . $id);
				}

				return $services[$id];
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$settings->method('getObjectService')->willReturn($objectService);

		return new OpenRegisterGrantsGateway(
			appManager: $appManager,
			container: $container,
			settingsService: $settings,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);
	}//end gateway()

	/**
	 * The handler is asked with the configured schema, the actions, the user and the case object.
	 *
	 * @return void
	 */
	public function testTheHandlerIsAskedAboutTheCaseObject(): void {
		$schema = new \stdClass();
		$case = new \stdClass();
		$handler = $this->handler(answer: ['read' => ['granted' => true]]);
		$mapper = $this->mapper(schema: $schema);
		$objects = new class($case) {
			/**
			 * The finds asked for.
			 *
			 * @var array<int, array<int|string, mixed>>
			 */
			public array $finds = [];

			/**
			 * Constructor.
			 *
			 * @param object $case The case to answer.
			 */
			public function __construct(private readonly object $case) {
			}

			/**
			 * Find one object.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return object The case.
			 */
			public function find(string $id, string $register = '', string $schema = ''): object {
				$this->finds[] = [$id, $register, $schema];
				return $this->case;
			}
		};

		$gateway = $this->gateway(
			services: [self::HANDLER => $handler, self::MAPPER => $mapper],
			config: ['case_schema' => 'case', 'register' => 'dossiq'],
			objectService: $objects,
		);

		self::assertSame(['read' => ['granted' => true]], $gateway->provenanceForCase(caseId: 'c-1', actions: ['read'], userId: 'ilse'));
		self::assertSame(['case'], $mapper->slugs);
		self::assertSame([['c-1', 'dossiq', 'case']], $objects->finds);
		self::assertCount(1, $handler->asked);
		self::assertSame($schema, $handler->asked[0]['schema']);
		self::assertSame(['read'], $handler->asked[0]['actions']);
		self::assertSame('ilse', $handler->asked[0]['userId']);
		self::assertSame($case, $handler->asked[0]['object']);
	}//end testTheHandlerIsAskedAboutTheCaseObject()

	/**
	 * Without a configured case schema the handler is never asked.
	 *
	 * @return void
	 */
	public function testNoCaseSchemaAsksNothing(): void {
		$handler = $this->handler(answer: ['read' => []]);
		$mapper = $this->mapper(schema: new \stdClass());

		$gateway = $this->gateway(services: [self::HANDLER => $handler, self::MAPPER => $mapper], config: ['register' => 'dossiq']);

		self::assertNull($gateway->provenanceForCase(caseId: 'c-1', actions: ['read']));
		self::assertSame([], $mapper->slugs);
		self::assertSame([], $handler->asked);
	}//end testNoCaseSchemaAsksNothing()

	/**
	 * A schema that cannot be resolved answers null and logs a warning naming the slug.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSchemaAnswersNullAndWarns(): void {
		$handler = $this->handler(answer: ['read' => []]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Dossiq grants gateway: the case schema could not be resolved',
			['exception' => 'no such schema', 'schema' => 'case']
		);

		$gateway = $this->gateway(
			services: [self::HANDLER => $handler, self::MAPPER => $this->mapper(schema: new RuntimeException('no such schema'))],
			config: ['case_schema' => 'case'],
			logger: $logger,
		);

		self::assertNull($gateway->provenanceForCase(caseId: 'c-1', actions: ['read']));
		self::assertSame([], $handler->asked);
	}//end testAnUnresolvableSchemaAnswersNullAndWarns()

	/**
	 * A mapper answering something other than an object answers null.
	 *
	 * @return void
	 */
	public function testANonObjectSchemaAnswersNull(): void {
		$handler = $this->handler(answer: ['read' => []]);

		$gateway = $this->gateway(services: [self::HANDLER => $handler, self::MAPPER => $this->mapper(schema: null)], config: ['case_schema' => 'case']);

		self::assertNull($gateway->provenanceForCase(caseId: 'c-1', actions: ['read']));
		self::assertSame([], $handler->asked);
	}//end testANonObjectSchemaAnswersNull()

	/**
	 * A case that cannot be read is passed on as null, with a warning; the handler still answers.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseIsPassedAsNull(): void {
		$handler = $this->handler(answer: ['update' => ['granted' => false]]);
		$objects = new class {
			/**
			 * Fails to find.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return object Never.
			 */
			public function find(string $id, string $register = '', string $schema = ''): object {
				throw new RuntimeException('gone');
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Dossiq grants gateway: the case could not be read for its own authorization block',
			['exception' => 'gone', 'caseId' => 'c-1']
		);

		$gateway = $this->gateway(
			services: [self::HANDLER => $handler, self::MAPPER => $this->mapper(schema: new \stdClass())],
			config: ['case_schema' => 'case', 'register' => 'dossiq'],
			objectService: $objects,
			logger: $logger,
		);

		self::assertSame(['update' => ['granted' => false]], $gateway->provenanceForCase(caseId: 'c-1', actions: ['update']));
		self::assertNull($handler->asked[0]['object']);
	}//end testAnUnreadableCaseIsPassedAsNull()

	/**
	 * Without a register the case is not looked up, and null is passed.
	 *
	 * @return void
	 */
	public function testNoRegisterPassesNoCase(): void {
		$handler = $this->handler(answer: []);
		$objects = new class {
			/**
			 * How often a case was looked up.
			 *
			 * @var integer
			 */
			public int $finds = 0;

			/**
			 * Records the lookup.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return object A case.
			 */
			public function find(string $id, string $register = '', string $schema = ''): object {
				$this->finds++;
				return new \stdClass();
			}
		};

		$gateway = $this->gateway(
			services: [self::HANDLER => $handler, self::MAPPER => $this->mapper(schema: new \stdClass())],
			config: ['case_schema' => 'case'],
			objectService: $objects,
		);

		self::assertSame([], $gateway->provenanceForCase(caseId: 'c-1', actions: ['read']));
		self::assertNull($handler->asked[0]['object']);
		self::assertSame(0, $objects->finds);
	}//end testNoRegisterPassesNoCase()

	/**
	 * A provenance that is not an array answers null.
	 *
	 * @return void
	 */
	public function testANonArrayProvenanceAnswersNull(): void {
		$gateway = $this->gateway(
			services: [self::HANDLER => $this->handler(answer: 'granted'), self::MAPPER => $this->mapper(schema: new \stdClass())],
			config: ['case_schema' => 'case'],
		);

		self::assertNull($gateway->provenanceForCase(caseId: 'c-1', actions: ['read']));
	}//end testANonArrayProvenanceAnswersNull()

	/**
	 * A refused write is logged at info with the record, and answers true.
	 *
	 * @return void
	 */
	public function testARefusedWriteIsLogged(): void {
		$record = ['action' => 'update', 'granted' => false, 'source' => 'deny'];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with(
			'Dossiq grants gateway: OpenRegister refuses this caller the write on a case',
			['caseId' => 'c-1', 'action' => 'update', 'provenance' => $record]
		);
		$handler = $this->handler(answer: ['update' => $record]);

		$gateway = $this->gateway(
			services: [self::HANDLER => $handler, self::MAPPER => $this->mapper(schema: new \stdClass())],
			config: ['case_schema' => 'case'],
			logger: $logger,
		);

		self::assertTrue($gateway->refusesTheWrite(caseId: 'c-1', userId: 'ilse'));
		self::assertSame(['update'], $handler->asked[0]['actions']);
		self::assertSame('ilse', $handler->asked[0]['userId']);
	}//end testARefusedWriteIsLogged()

	/**
	 * A provenance without an update record, or a non-array one, is no refusal.
	 *
	 * @return void
	 */
	public function testAMissingUpdateRecordIsNoRefusal(): void {
		foreach ([['read' => ['granted' => false]], ['update' => false]] as $answer) {
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects(self::never())->method('info');

			$gateway = $this->gateway(
				services: [self::HANDLER => $this->handler(answer: $answer), self::MAPPER => $this->mapper(schema: new \stdClass())],
				config: ['case_schema' => 'case'],
				logger: $logger,
			);

			self::assertFalse($gateway->refusesTheWrite(caseId: 'c-1'));
		}
	}//end testAMissingUpdateRecordIsNoRefusal()

	/**
	 * A container that cannot build the handler answers null and logs a warning.
	 *
	 * @return void
	 */
	public function testAnUnbuildableHandlerAnswersNullAndWarns(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Dossiq grants gateway: OpenRegister PermissionHandler unavailable',
			['exception' => 'nothing answers to ' . self::HANDLER]
		);

		$gateway = $this->gateway(services: [], config: ['case_schema' => 'case'], logger: $logger);

		self::assertNull($gateway->provenanceForCase(caseId: 'c-1', actions: ['read']));
	}//end testAnUnbuildableHandlerAnswersNullAndWarns()
}//end class
