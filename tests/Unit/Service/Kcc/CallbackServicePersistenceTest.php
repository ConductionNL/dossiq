<?php

/**
 * CallbackService against the register: schedule, cancel, list and persist.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Kcc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Kcc;

use OCA\Dossiq\Service\Kcc\CallbackRetrySchedule;
use OCA\Dossiq\Service\Kcc\CallbackService;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Writes go to the configured callback schema; an agent only cancels and
 * lists their own callbacks unless privileged; a missing register or schema
 * refuses before anything is read.
 *
 * @covers \OCA\Dossiq\Service\Kcc\CallbackService
 * @uses   \OCA\Dossiq\Service\Support\SerialisedResult
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CallbackServicePersistenceTest extends TestCase {

	/**
	 * The info log lines.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logged = [];

	/**
	 * A register double: stored callbacks by uuid, and every call it took.
	 *
	 * @param array<string, mixed> $stored Callbacks by uuid; a Throwable is thrown on find.
	 *
	 * @return object The object service.
	 */
	private static function register(array $stored = []): object {
		return new class ($stored) {

			/**
			 * Every call: name and arguments.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $stored The callbacks.
			 */
			public function __construct(public array $stored) {
			}//end __construct()

			/**
			 * Save, answering the object with an id.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The uuid on an update.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array {
				$this->calls[] = ['saveObject', $object, $register, $schema, $uuid];
				return ['id' => ($uuid ?? 'new-1')] + $object;
			}//end saveObject()

			/**
			 * Find by uuid.
			 *
			 * @param string $id       The uuid.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return mixed
			 */
			public function find(string $id, string $register, string $schema): mixed {
				$this->calls[] = ['find', $id, $register, $schema];
				$found = ($this->stored[$id] ?? null);
				if ($found instanceof \Throwable) {
					throw $found;
				}

				return $found;
			}//end find()

			/**
			 * Find all matching a query.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config): array {
				$this->calls[] = ['findAll', $config];
				return array_values($this->stored);
			}//end findAll()
		};

	}//end register()

	/**
	 * The service over a register double and a configuration.
	 *
	 * @param object|null           $objectService The register, null when OpenRegister is absent.
	 * @param array<string, string> $config        The app config values.
	 *
	 * @return CallbackService The service.
	 */
	private function service(?object $objectService, array $config = ['register' => '4', 'callback_request_schema' => '9']): CallbackService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);

		return new CallbackService($settings, new CallbackRetrySchedule(), $logger);

	}//end service()

	/**
	 * The message of the OCSBadRequestException a call throws.
	 *
	 * @param callable $call The call.
	 *
	 * @return string The message.
	 */
	private function refusal(callable $call): string {
		try {
			$call();
		} catch (OCSBadRequestException $e) {
			return $e->getMessage();
		}

		$this->fail('The call was not refused.');

	}//end refusal()

	/**
	 * The payload is trimmed, the time normalised to ATOM, a named agent and
	 * a contact moment carried, and an empty contact moment left out.
	 *
	 * @return void
	 */
	public function testThePayloadIsNormalised(): void {
		$service = $this->service(objectService: null);

		$this->assertSame(
			[
				'customerPhone' => '0612345678',
				'reason' => 'Vraag over vergunning',
				'status' => 'scheduled',
				'attemptCount' => 0,
				'preferredAgent' => 'bob',
				'scheduledFor' => '2026-11-20T14:30:00+01:00',
				'contactMomentRef' => '42',
			],
			$service->buildPayload(
				data: [
					'customerPhone' => ' 0612345678 ',
					'reason' => ' Vraag over vergunning ',
					'preferredAgent' => ' bob ',
					'scheduledFor' => ' 2026-11-20T14:30:00+01:00 ',
					'contactMomentRef' => 42,
				],
				agentId: 'alice'
			)
		);
		$this->assertSame(
			['customerPhone' => '06', 'reason' => '', 'status' => 'scheduled', 'attemptCount' => 0, 'preferredAgent' => 'alice'],
			$service->buildPayload(data: ['customerPhone' => '06', 'contactMomentRef' => '', 'scheduledFor' => '  '], agentId: 'alice')
		);
	}//end testThePayloadIsNormalised()

	/**
	 * Scheduling saves the payload to the callback schema and logs the agent.
	 *
	 * @return void
	 */
	public function testScheduleSavesToTheCallbackSchema(): void {
		$register = self::register();

		$saved = $this->service(objectService: $register)->schedule(data: ['customerPhone' => '06'], agentId: 'alice');

		$payload = ['customerPhone' => '06', 'reason' => '', 'status' => 'scheduled', 'attemptCount' => 0, 'preferredAgent' => 'alice'];
		$this->assertSame([['saveObject', $payload, '4', '9', null]], $register->calls);
		$this->assertSame(['id' => 'new-1'] + $payload, $saved);
		$this->assertSame([['Dossiq KCC: callback scheduled', ['agent' => 'alice']]], $this->logged);
	}//end testScheduleSavesToTheCallbackSchema()

	/**
	 * An invalid payload is refused before the register is touched.
	 *
	 * @return void
	 */
	public function testAnInvalidScheduleWritesNothing(): void {
		$register = self::register();

		$this->assertSame('customerPhone is required', $this->refusal(fn () => $this->service(objectService: $register)->schedule(data: ['customerPhone' => ' '], agentId: 'alice')));
		$this->assertSame([], $register->calls);
	}//end testAnInvalidScheduleWritesNothing()

	/**
	 * The owning agent cancels: status cancelled, no next attempt, saved under its uuid.
	 *
	 * @return void
	 */
	public function testTheOwnerCancels(): void {
		$register = self::register(stored: ['cb-1' => ['preferredAgent' => 'alice', 'status' => 'attempted', 'nextAttemptAt' => '2026-11-14T10:00:00+00:00']]);

		$saved = $this->service(objectService: $register)->cancel(id: 'cb-1', agentId: 'alice');

		$cancelled = ['preferredAgent' => 'alice', 'status' => 'cancelled', 'nextAttemptAt' => null];
		$this->assertSame(['id' => 'cb-1'] + $cancelled, $saved);
		$this->assertSame([['find', 'cb-1', '4', '9'], ['saveObject', $cancelled, '4', '9', 'cb-1']], $register->calls);
	}//end testTheOwnerCancels()

	/**
	 * Another agent's callback reads as not found unless the caller is privileged.
	 *
	 * @return void
	 */
	public function testAnotherAgentsCallbackIsNotFoundUnlessPrivileged(): void {
		$register = self::register(stored: ['cb-1' => ['preferredAgent' => 'alice', 'status' => 'scheduled']]);
		$service = $this->service(objectService: $register);

		$this->assertSame('Callback request not found', $this->refusal(static fn () => $service->cancel(id: 'cb-1', agentId: 'bob')));
		$this->assertCount(1, $register->calls);

		$this->assertSame('cancelled', $service->cancel(id: 'cb-1', agentId: 'bob', isPrivileged: true)['status']);
	}//end testAnotherAgentsCallbackIsNotFoundUnlessPrivileged()

	/**
	 * A callback that is missing, empty, or whose lookup throws is not found.
	 *
	 * @return void
	 */
	public function testAMissingCallbackIsNotFound(): void {
		$register = self::register(stored: ['empty' => [], 'boom' => new RuntimeException('db')]);
		$service = $this->service(objectService: $register);

		foreach (['gone', 'empty', 'boom'] as $id) {
			$this->assertSame('Callback request not found', $this->refusal(static fn () => $service->cancel(id: $id, agentId: 'alice', isPrivileged: true)));
		}

		$this->assertSame([], array_filter($register->calls, static fn (array $call): bool => $call[0] === 'saveObject'));
	}//end testAMissingCallbackIsNotFound()

	/**
	 * An agent lists only their own callbacks, with integer register and
	 * schema ids and an optional status filter; a privileged caller sees all.
	 *
	 * @return void
	 */
	public function testListScopesToTheAgentUnlessPrivileged(): void {
		$register = self::register(stored: ['cb-1' => ['id' => 'cb-1'], 'cb-2' => 'not a row']);
		$service = $this->service(objectService: $register);

		$this->assertSame([['id' => 'cb-1'], []], $service->list(filters: ['status' => 'scheduled'], agentId: 'alice'));
		$service->list(filters: ['status' => ''], agentId: 'alice', isPrivileged: true);

		$this->assertSame(
			[
				['findAll', ['filters' => ['register' => 4, 'schema' => 9, 'status' => 'scheduled', 'preferredAgent' => 'alice']]],
				['findAll', ['filters' => ['register' => 4, 'schema' => 9]]],
			],
			$register->calls
		);
	}//end testListScopesToTheAgentUnlessPrivileged()

	/**
	 * Persist saves the callback as given under its uuid.
	 *
	 * @return void
	 */
	public function testPersistSavesUnderTheUuid(): void {
		$register = self::register();

		$saved = $this->service(objectService: $register)->persist(id: 'cb-7', callback: ['status' => 'attempted', 'attemptCount' => 2]);

		$this->assertSame(['id' => 'cb-7', 'status' => 'attempted', 'attemptCount' => 2], $saved);
		$this->assertSame([['saveObject', ['status' => 'attempted', 'attemptCount' => 2], '4', '9', 'cb-7']], $register->calls);
	}//end testPersistSavesUnderTheUuid()

	/**
	 * Without OpenRegister, or without a register or callback schema, every
	 * register call is refused.
	 *
	 * @return void
	 */
	public function testAMissingRegisterOrSchemaIsRefused(): void {
		$this->assertSame('OpenRegister is not available', $this->refusal(fn () => $this->service(objectService: null)->persist(id: 'cb-1', callback: [])));

		$register = self::register();
		foreach ([['register' => '4'], ['callback_request_schema' => '9']] as $config) {
			$service = $this->service(objectService: $register, config: $config);
			$this->assertSame('KCC callback schema is not configured', $this->refusal(static fn () => $service->list(filters: [], agentId: 'alice')));
			$this->assertSame('KCC callback schema is not configured', $this->refusal(static fn () => $service->cancel(id: 'cb-1', agentId: 'alice')));
		}

		$this->assertSame([], $register->calls);
	}//end testAMissingRegisterOrSchemaIsRefused()
}//end class
