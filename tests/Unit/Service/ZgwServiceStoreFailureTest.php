<?php

/**
 * What a ZGW show, update and delete answer when the store did not do what was asked
 *
 * A store that was down, an object that is not there and a caller who may not
 * touch it each get their own answer (Q-dossiq-L11-2).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\NotificatieService;
use OCA\Dossiq\Service\Zgw\StoreFailureAnswer;
use OCA\Dossiq\Service\Zgw\ZgwRuleResults;
use OCA\Dossiq\Service\ZgwBusinessRulesService;
use OCA\Dossiq\Service\ZgwMappingService;
use OCA\Dossiq\Service\ZgwService;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The generic ZGW handlers sort a store failure into 404, 403 and 503.
 *
 * @covers \OCA\Dossiq\Service\ZgwService
 * @covers \OCA\Dossiq\Service\Zgw\StoreFailureAnswer
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 *
 * @spec openspec/changes/guards-refuse-when-unsure/specs/zgw-business-rules-compliance/spec.md
 */
final class ZgwServiceStoreFailureTest extends TestCase {
	/**
	 * Every log line, as "level: message".
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * How often a notification went out.
	 *
	 * @var int
	 */
	private int $notified = 0;

	/**
	 * Whether publishing a notification throws.
	 *
	 * @var bool
	 */
	private bool $notificationsDown = false;

	/**
	 * A store whose find and delete answer as given.
	 *
	 * @param \Closure $find What find() answers or throws.
	 * @param \Closure|null $delete What deleteObject() throws, if anything.
	 *
	 * @return object
	 */
	private function store(\Closure $find, ?\Closure $delete = null): object {
		return new class($find, $delete) {
			/**
			 * The uuids deleted.
			 *
			 * @var array<int, string>
			 */
			public array $deleted = [];

			public function __construct(
				private \Closure $find,
				private ?\Closure $delete,
			) {
			}//end __construct()

			public function find(string $id, mixed $register = null, mixed $schema = null): mixed {
				return ($this->find)($id);
			}//end find()

			public function deleteObject(string $uuid): bool {
				if ($this->delete !== null) {
					($this->delete)($uuid);
				}

				$this->deleted[] = $uuid;
				return true;
			}//end deleteObject()
		};
	}//end store()

	/**
	 * A ZgwService on the given store, without its container-reading constructor.
	 *
	 * @param object $store The store.
	 *
	 * @return ZgwService
	 */
	private function service(object $store): ZgwService {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->log[] = $level . ': ' . $message;
				}
			);
		}

		$mappings = $this->createMock(ZgwMappingService::class);
		$mappings->method('getMapping')->willReturn(['sourceRegister' => '5', 'sourceSchema' => '50']);

		$rules = $this->createMock(ZgwBusinessRulesService::class);
		$rules->method('validate')->willReturnCallback(
			static fn (string $zgwApi, string $resource, string $action, array $body): array => ['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => $body]
		);

		$notifications = $this->createMock(NotificatieService::class);
		$notifications->method('publish')->willReturnCallback(
			function (): void {
				if ($this->notificationsDown === true) {
					throw new RuntimeException('notification channel down');
				}

				$this->notified++;
			}
		);

		$service = (new \ReflectionClass(ZgwService::class))->newInstanceWithoutConstructor();
		\Closure::bind(
			static function () use ($service, $store, $logger, $mappings, $rules, $notifications): void {
				$service->logger               = $logger;
				$service->objectService        = $store;
				$service->zgwMappingService    = $mappings;
				$service->businessRulesService = $rules;
				$service->notificationService  = $notifications;
			},
			null,
			ZgwService::class
		)();

		return $service;
	}//end service()

	/**
	 * A failure that is neither "not found" nor "not allowed".
	 *
	 * @return never
	 */
	private static function down(): never {
		throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
	}//end down()

	/**
	 * The delete of a stored object, and its answer.
	 *
	 * @param object $store The store.
	 *
	 * @return array{0: int, 1: array}
	 */
	private function destroy(object $store): array {
		$response = $this->service(store: $store)->handleDestroy(
			request: $this->createMock(IRequest::class),
			zgwApi: 'zaken',
			resource: 'zaken',
			uuid: 'z-1'
		);

		return [$response->getStatus(), $response->getData()];
	}//end destroy()

	/**
	 * A delete answers 404 only for an object that is not there, in either way the store says so.
	 *
	 * @return void
	 */
	public function testADeleteAnswersNotFoundOnlyForAnAbsentObject(): void {
		$thrown = $this->store(find: static fn (): never => throw new DoesNotExistException('Object not found'));
		$this->assertSame([404, ['detail' => 'Not found']], $this->destroy(store: $thrown));

		$null = $this->store(find: static fn (): mixed => null);
		$this->assertSame([404, ['detail' => 'Not found']], $this->destroy(store: $null));

		$this->assertSame([], $thrown->deleted);
		$this->assertSame([], $null->deleted);
		$this->assertSame(0, $this->notified);
	}//end testADeleteAnswersNotFoundOnlyForAnAbsentObject()

	/**
	 * A delete the store could not complete answers 503 and is logged at error, before the
	 * delete and during it; nothing is announced.
	 *
	 * @return void
	 */
	public function testADeleteTheStoreCouldNotCompleteIsNotANotFound(): void {
		[$status, $data] = $this->destroy(store: $this->store(find: static fn (): never => self::down()));
		$this->assertSame(ZgwRuleResults::NOT_VERIFIED_STATUS, $status);
		$this->assertSame(StoreFailureAnswer::NOT_COMPLETED_CODE, $data['code']);

		$failing = $this->store(find: static fn (string $id): array => ['id' => $id], delete: static fn (): never => self::down());
		$this->assertSame(503, $this->destroy(store: $failing)[0]);

		$this->assertCount(2, array_filter($this->log, static fn (string $line): bool => str_starts_with($line, 'error: ')));
		$this->assertSame(0, $this->notified);
	}//end testADeleteTheStoreCouldNotCompleteIsNotANotFound()

	/**
	 * A delete the store refuses to the caller answers 403, as OpenRegister's refusal or as any
	 * failure carrying code 403, and never 503.
	 *
	 * @return void
	 */
	public function testARefusedDeleteAnswersForbidden(): void {
		$found = static fn (string $id): array => ['id' => $id];

		[$status, $data] = $this->destroy(store: $this->store(find: $found, delete: static fn (): never => throw new NotAuthorizedException()));
		$this->assertSame(403, $status);
		$this->assertSame('permission_denied', $data['code']);

		$plain = $this->store(find: $found, delete: static fn (): never => throw new \Exception('User may not delete', 403));
		$this->assertSame(403, $this->destroy(store: $plain)[0]);

		$this->assertSame(403, $this->destroy(store: $this->store(find: static fn (): never => throw new NotAuthorizedException()))[0]);
		$this->assertSame([], array_filter($this->log, static fn (string $line): bool => str_starts_with($line, 'error: ')));
	}//end testARefusedDeleteAnswersForbidden()

	/**
	 * Control: a delete that happened answers 204 and is announced, also when the announcement fails.
	 *
	 * @return void
	 */
	public function testADeleteThatHappenedAnswersNoContent(): void {
		$store = $this->store(find: static fn (string $id): array => ['id' => $id]);
		$this->assertSame(204, $this->destroy(store: $store)[0]);
		$this->assertSame(['z-1'], $store->deleted);
		$this->assertSame(1, $this->notified);

		$this->notificationsDown = true;
		$this->assertSame(204, $this->destroy(store: $store)[0], 'the object is gone; a failed announcement does not undo that');
		$this->assertCount(1, array_filter($this->log, static fn (string $line): bool => str_contains($line, 'notification failed')));
	}//end testADeleteThatHappenedAnswersNoContent()

	/**
	 * A show and an update read the object the same way: 404 when absent, 503 when the store was down.
	 *
	 * @return void
	 */
	public function testAShowAndAnUpdateSortTheirReadTheSameWay(): void {
		$request = $this->createMock(IRequest::class);
		$absent  = $this->service(store: $this->store(find: static fn (): never => throw new DoesNotExistException('gone')));
		$down    = $this->service(store: $this->store(find: static fn (): never => self::down()));

		$this->assertSame(404, $absent->handleShow(request: $request, zgwApi: 'zaken', resource: 'zaken', uuid: 'z-1')->getStatus());
		$this->assertSame(503, $down->handleShow(request: $request, zgwApi: 'zaken', resource: 'zaken', uuid: 'z-1')->getStatus());
		$this->assertSame(404, $absent->handleUpdate(request: $request, zgwApi: 'zaken', resource: 'zaken', uuid: 'z-1')->getStatus());
		$this->assertSame(503, $down->handleUpdate(request: $request, zgwApi: 'zaken', resource: 'zaken', uuid: 'z-1')->getStatus());
	}//end testAShowAndAnUpdateSortTheirReadTheSameWay()
}//end class
