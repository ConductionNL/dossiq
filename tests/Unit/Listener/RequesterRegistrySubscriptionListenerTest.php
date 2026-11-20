<?php

/**
 * RequesterRegistrySubscriptionListener unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contacts-domain/tasks.md#4-the-contact-reference-on-a-contact-moment
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Dossiq\Kernel\Contracts\RequesterRegistrySubscriptionInterface;
use OCA\Dossiq\Listener\RequesterRegistrySubscriptionListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A case that gets a new requester asks for that requester's registry subscription.
 *
 * @covers \OCA\Dossiq\Listener\RequesterRegistrySubscriptionListener
 * @uses   \OCA\Dossiq\Service\Support\ReferenceId
 */
class RequesterRegistrySubscriptionListenerTest extends TestCase {

	/**
	 * The subscriptions.
	 *
	 * @var RequesterRegistrySubscriptionInterface&MockObject
	 */
	private RequesterRegistrySubscriptionInterface&MockObject $subscriptions;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The listener under test.
	 *
	 * @var RequesterRegistrySubscriptionListener
	 */
	private RequesterRegistrySubscriptionListener $listener;

	/**
	 * Wire the listener; the resolver reads the slug from `@self.schema`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$resolver = $this->createMock(originalClassName: ObjectSchemaSlugResolverInterface::class);
		$resolver->method('resolveFromPayload')->willReturnCallback(
			static fn (array $payload): string => (string)($payload['@self']['schema'] ?? '')
		);
		$this->subscriptions = $this->createMock(originalClassName: RequesterRegistrySubscriptionInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->listener = new RequesterRegistrySubscriptionListener(
			slugResolver: $resolver,
			subscriptions: $this->subscriptions,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * An entity of a schema.
	 *
	 * @param string               $schema Its schema slug.
	 * @param array<string, mixed> $data   Its fields.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('case-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A created case with a requester subscribes that requester.
	 *
	 * @return void
	 */
	public function testACreatedCaseSubscribesItsRequester(): void {
		$this->subscriptions->expects($this->once())->method('subscribe')->with('person-1')->willReturn([]);

		$this->listener->handle(new ObjectCreatedEvent(object: $this->entity(schema: 'case', data: ['requester' => ' person-1 '])));
	}//end testACreatedCaseSubscribesItsRequester()

	/**
	 * A requester stored as an object reference is read by its id.
	 *
	 * @return void
	 */
	public function testAnObjectReferenceIsReadByItsId(): void {
		$this->subscriptions->expects($this->once())->method('subscribe')->with('company-9')->willReturn([]);

		$this->listener->handle(new ObjectCreatedEvent(object: $this->entity(schema: 'case', data: ['requester' => ['id' => 'company-9']])));
	}//end testAnObjectReferenceIsReadByItsId()

	/**
	 * An update that changes the requester subscribes the new one.
	 *
	 * @return void
	 */
	public function testAnUpdateWithANewRequesterSubscribesIt(): void {
		$this->subscriptions->expects($this->once())->method('subscribe')->with('person-2')->willReturn([]);

		$this->listener->handle(
			new ObjectUpdatedEvent(
				$this->entity(schema: 'case', data: ['requester' => 'person-2']),
				$this->entity(schema: 'case', data: ['requester' => 'person-1'])
			)
		);
	}//end testAnUpdateWithANewRequesterSubscribesIt()

	/**
	 * An update that keeps the requester, however stored, does not reach the registry.
	 *
	 * @return void
	 */
	public function testAnUpdateKeepingTheRequesterDoesNothing(): void {
		$this->subscriptions->expects($this->never())->method('subscribe');

		$this->listener->handle(
			new ObjectUpdatedEvent(
				$this->entity(schema: 'case', data: ['requester' => 'person-1', 'title' => 'New']),
				$this->entity(schema: 'case', data: ['requester' => ['uuid' => 'person-1'], 'title' => 'Old'])
			)
		);
	}//end testAnUpdateKeepingTheRequesterDoesNothing()

	/**
	 * An update without a previous object is treated as new.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutAnOldObjectSubscribes(): void {
		$this->subscriptions->expects($this->once())->method('subscribe')->with('person-1')->willReturn([]);

		$this->listener->handle(new ObjectUpdatedEvent(newObject: $this->entity(schema: 'case', data: ['requester' => 'person-1']), oldObject: null));
	}//end testAnUpdateWithoutAnOldObjectSubscribes()

	/**
	 * A case without a requester, an object of another schema and a pre-persist event do nothing.
	 *
	 * @return void
	 */
	public function testNothingHappensWithoutACaseRequester(): void {
		$this->subscriptions->expects($this->never())->method('subscribe');

		$this->listener->handle(new ObjectCreatedEvent(object: $this->entity(schema: 'case', data: ['title' => 'No requester'])));
		$this->listener->handle(new ObjectCreatedEvent(object: $this->entity(schema: 'case', data: ['requester' => 42])));
		$this->listener->handle(new ObjectCreatedEvent(object: $this->entity(schema: 'contactMoment', data: ['requester' => 'person-1'])));
		$this->listener->handle(new ObjectCreatingEvent(object: $this->entity(schema: 'case', data: ['requester' => 'person-1'])));
	}//end testNothingHappensWithoutACaseRequester()

	/**
	 * A failing subscription is logged and does not fail the save.
	 *
	 * @return void
	 */
	public function testAFailingSubscriptionIsLogged(): void {
		$this->subscriptions->method('subscribe')->willThrowException(new RuntimeException('BRP down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq: the requester subscription of a case failed; the case itself is saved',
			['error' => 'BRP down']
		);

		$this->listener->handle(new ObjectCreatedEvent(object: $this->entity(schema: 'case', data: ['requester' => 'person-1'])));
	}//end testAFailingSubscriptionIsLogged()
}//end class
