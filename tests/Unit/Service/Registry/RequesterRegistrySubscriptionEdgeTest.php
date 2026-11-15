<?php

/**
 * Edge cases of subscribing a case's requester at its source register.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Registry
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

namespace OCA\Dossiq\Tests\Unit\Service\Registry;

use OCA\Dossiq\Service\Registry\RequesterRegistrySubscription;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What is answered before anything is asked of OpenRegister, and when the requester cannot be read.
 *
 * @covers \OCA\Dossiq\Service\Registry\RequesterRegistrySubscription
 * @uses \OCA\Dossiq\Service\Registry\SubscriptionRun
 */
class RequesterRegistrySubscriptionEdgeTest extends TestCase {

	/**
	 * Build the subscription over the given OpenRegister services.
	 *
	 * @param object|null          $registry The registry subscription service.
	 * @param object|null          $schemas  The schema mapper.
	 * @param object|null          $objects  The object service.
	 * @param LoggerInterface|null $logger   The logger.
	 *
	 * @return RequesterRegistrySubscription The subscription.
	 */
	private function subscription(?object $registry, ?object $schemas, ?object $objects, ?LoggerInterface $logger = null): RequesterRegistrySubscription {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			static fn (string $class): ?object => match ($class) {
				RequesterRegistrySubscription::REGISTRY_SERVICE => $registry,
				RequesterRegistrySubscription::SCHEMA_MAPPER => $schemas,
				default => null,
			}
		);
		$settings->method('getObjectService')->willReturn($objects);

		return new RequesterRegistrySubscription(settings: $settings, logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end subscription()

	/**
	 * An object service answering a fixed value for any id.
	 *
	 * @param mixed $found What find answers; a Throwable is thrown.
	 *
	 * @return object The stand-in, with a public `$ids` log.
	 */
	private function objects(mixed $found): object {
		return new class($found) {
			/**
			 * The ids asked for.
			 *
			 * @var array<int, string>
			 */
			public array $ids = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $found The answer.
			 */
			public function __construct(private readonly mixed $found) {
			}

			/**
			 * Find one object.
			 *
			 * @param string $id The id.
			 *
			 * @return mixed The answer.
			 */
			public function find(string $id): mixed {
				$this->ids[] = $id;
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}

				return $this->found;
			}
		};
	}//end objects()

	/**
	 * A registry that must not be asked anything.
	 *
	 * @return object The registry.
	 */
	private function silentRegistry(): object {
		return new class {
			/**
			 * Must not be called.
			 *
			 * @return void
			 */
			public function requestSubscription(): void {
				throw new \LogicException('nothing may be requested');
			}
		};
	}//end silentRegistry()

	/**
	 * A blank requester is answered without asking OpenRegister.
	 *
	 * @return void
	 */
	public function testABlankRequesterIsAnsweredWithoutAsking(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects(self::never())->method('getOpenRegisterClass');
		$subscription = new RequesterRegistrySubscription(settings: $settings, logger: $this->createMock(LoggerInterface::class));

		self::assertSame(['requested' => false, 'reason' => 'the case names no requester'], $subscription->subscribe(objectUuid: '  '));
	}//end testABlankRequesterIsAnsweredWithoutAsking()

	/**
	 * Any one missing OpenRegister service means no registry subscriptions.
	 *
	 * @return void
	 */
	public function testAnyMissingServiceMeansNoSubscriptions(): void {
		$expected = ['requested' => false, 'reason' => 'this OpenRegister has no registry subscriptions'];
		$present = new \stdClass();

		self::assertSame($expected, $this->subscription(registry: null, schemas: $present, objects: $present)->subscribe(objectUuid: 'p-1'));
		self::assertSame($expected, $this->subscription(registry: $present, schemas: null, objects: $present)->subscribe(objectUuid: 'p-1'));
		self::assertSame($expected, $this->subscription(registry: $present, schemas: $present, objects: null)->subscribe(objectUuid: 'p-1'));
	}//end testAnyMissingServiceMeansNoSubscriptions()

	/**
	 * A requester that is not an object is said to be unreadable, and nothing is requested.
	 *
	 * @return void
	 */
	public function testAnUnreadableRequesterIsSaid(): void {
		$objects = $this->objects(found: null);

		$answer = $this->subscription(registry: $this->silentRegistry(), schemas: new \stdClass(), objects: $objects)->subscribe(objectUuid: ' p-1 ');

		self::assertSame(['requested' => false, 'reason' => 'the requester could not be read'], $answer);
		self::assertSame(['p-1'], $objects->ids);
	}//end testAnUnreadableRequesterIsSaid()

	/**
	 * A failing read is logged at info with the object, and answered with its message.
	 *
	 * @return void
	 */
	public function testAFailingReadIsLoggedAndAnswered(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with(
			'Dossiq: the requester of a case was not subscribed at its source register: object store offline',
			['object' => 'p-1']
		);

		$answer = $this->subscription(
			registry: $this->silentRegistry(),
			schemas: new \stdClass(),
			objects: $this->objects(found: new RuntimeException('object store offline')),
			logger: $logger,
		)->subscribe(objectUuid: 'p-1');

		self::assertSame(['requested' => false, 'reason' => 'object store offline'], $answer);
	}//end testAFailingReadIsLoggedAndAnswered()

	/**
	 * A state that is not live, or not an array, leads to a request.
	 *
	 * @return void
	 */
	public function testOnlyALiveStateSkipsTheRequest(): void {
		foreach ([['state' => 'ended'], 'active', null, ['state' => 'requested']] as $state) {
			$registry = new class($state) {
				/**
				 * The requests made.
				 *
				 * @var integer
				 */
				public int $requests = 0;

				/**
				 * Constructor.
				 *
				 * @param mixed $state The state to answer.
				 */
				public function __construct(private readonly mixed $state) {
				}

				/**
				 * Every schema is registry-backed.
				 *
				 * @param mixed $schema The schema.
				 *
				 * @return array<string, string> The annotation.
				 */
				public function annotationFor(mixed $schema): array {
					return ['source' => 'brp'];
				}

				/**
				 * The dictated state.
				 *
				 * @param string $uuid The object.
				 *
				 * @return mixed The state.
				 */
				public function stateFor(string $uuid): mixed {
					return $this->state;
				}

				/**
				 * Record a request.
				 *
				 * @param mixed $object The object.
				 * @param mixed $schema The schema.
				 *
				 * @return void
				 */
				public function requestSubscription(mixed $object, mixed $schema): void {
					$this->requests++;
				}
			};
			$schemas = new class {
				/**
				 * Find a schema.
				 *
				 * @param mixed $id The id.
				 *
				 * @return object The schema.
				 */
				public function find(mixed $id): object {
					return new \stdClass();
				}
			};
			$person = new class {
				/**
				 * The schema id.
				 *
				 * @return int The id.
				 */
				public function getSchema(): int {
					return 5;
				}
			};

			$answer = $this->subscription(registry: $registry, schemas: $schemas, objects: $this->objects(found: $person))->subscribe(objectUuid: 'p-1');

			$live = ($state === ['state' => 'requested']);
			self::assertSame($live ? ['requested' => false, 'reason' => 'already subscribed'] : ['requested' => true, 'reason' => ''], $answer, var_export($state, true));
			self::assertSame($live ? 0 : 1, $registry->requests);
		}
	}//end testOnlyALiveStateSkipsTheRequest()
}//end class
