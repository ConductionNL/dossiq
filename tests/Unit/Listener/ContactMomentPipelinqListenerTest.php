<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\ContactMomentPipelinqListener;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Pipelinq\ContactMomentBridge;
use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A contact moment logged on a case reaches pipelinq's record, whichever
 * surface logged it.
 *
 * The bridge, the gateway and the slug check are the real classes. Only
 * pipelinq's leaf is a recorder, with the signature of
 * `OCA\Pipelinq\Integration\ContactMomentLeafProvider::create()`.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02
 */
class ContactMomentPipelinqListenerTest extends TestCase {

	/**
	 * Everything pipelinq's leaf was asked to append.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $appended = [];

	/**
	 * What pipelinq's leaf answers to a create.
	 *
	 * @var array<string, mixed>
	 */
	private array $createAnswer = ['status' => 201];

	/**
	 * The listener over the real bridge and a pipelinq recorder.
	 *
	 * @param bool $withPipelinq Whether pipelinq is installed.
	 *
	 * @return ContactMomentPipelinqListener The listener under test.
	 */
	private function listener(bool $withPipelinq = true): ContactMomentPipelinqListener {
		$leaf = new class($this->appended, $this->createAnswer) {
			/**
			 * @param array<int, array<string, mixed>> $appended Captured payloads.
			 * @param array<string, mixed> $createAnswer What create answers.
			 */
			public function __construct(
				public array &$appended,
				public array $createAnswer,
			) {
			}

			/**
			 * @param string $hostId The host.
			 * @param array<string, mixed> $payload The payload.
			 *
			 * @return array<string, mixed> The answer.
			 */
			public function create(string $hostId, array $payload): array {
				$this->appended[] = ['hostId' => $hostId] + $payload;

				return $this->createAnswer;
			}

			/**
			 * @param string $hostId The host.
			 * @param string $partyId The party.
			 *
			 * @return array<string, mixed> The answer.
			 */
			public function list(string $hostId, string $partyId = ''): array {
				return ['status' => 200, 'contactMoments' => [], 'indicators' => []];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($leaf, $withPipelinq): object {
				if ($withPipelinq === true && $id === PipelinqGateway::CONTACT_MOMENTS) {
					return $leaf;
				}

				throw new RuntimeException("nothing answers to {$id}");
			}
		);

		$bridge = new ContactMomentBridge(
			new PipelinqGateway($container, $this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class),
		);

		$resolver = $this->createMock(ObjectSchemaSlugResolver::class);
		$resolver->method('resolveFromPayload')->willReturnCallback(
			static fn (array $payload): string => (string)($payload['@self']['schema'] ?? '')
		);

		return new ContactMomentPipelinqListener(
			$bridge,
			$resolver,
			$this->createMock(LoggerInterface::class),
		);
	}//end listener()

	/**
	 * A contact moment as OpenRegister hands it to a create listener.
	 *
	 * @param array<string, mixed> $record The stored fields.
	 * @param string               $schema The schema slug.
	 *
	 * @return ObjectCreatedEvent The event.
	 */
	private function created(array $record, string $schema = 'contactmoment'): ObjectCreatedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(
			array_merge(['@self' => ['id' => 'cm-1', 'schema' => $schema]], $record)
		);

		return new ObjectCreatedEvent($entity);
	}//end created()

	/**
	 * A call logged from the case page's Log contact form reaches pipelinq with
	 * its direction and the case as its host. That form saves straight to
	 * OpenRegister and runs no dossiq service, so only the create event sees it.
	 *
	 * @return void
	 */
	public function testACallLoggedOnTheCasePageReachesPipelinq(): void {
		$this->listener()->handle(
			$this->created(
				[
					'case' => 'case-1',
					'direction' => 'inbound',
					'notificationChannel' => 'telefoon',
					'nature' => 'informatieverzoek',
					'summary' => 'Asked about the permit',
				]
			)
		);

		$this->assertCount(1, $this->appended, 'one logged call is one append');
		$this->assertSame('case-1', $this->appended[0]['hostId'], 'the case is the host');
		$this->assertSame('inbound', $this->appended[0]['direction']);
		$this->assertSame('informatieverzoek', $this->appended[0]['title']);
	}//end testACallLoggedOnTheCasePageReachesPipelinq()

	/**
	 * A moment with no case has no host, and a KCC call that opened no case is
	 * an ordinary thing: nothing is appended.
	 *
	 * @return void
	 */
	public function testAMomentWithoutACaseAppendsNothing(): void {
		$this->listener()->handle($this->created(['direction' => 'inbound', 'nature' => 'informatieverzoek']));

		$this->assertSame([], $this->appended);
	}//end testAMomentWithoutACaseAppendsNothing()

	/**
	 * Another schema's create is none of this listener's business.
	 *
	 * @return void
	 */
	public function testAnotherSchemaAppendsNothing(): void {
		$this->listener()->handle($this->created(['case' => 'case-1', 'direction' => 'inbound'], 'caseDocument'));

		$this->assertSame([], $this->appended);
	}//end testAnotherSchemaAppendsNothing()

	/**
	 * An update is not a new contact, so it is not appended a second time.
	 *
	 * @return void
	 */
	public function testAnUpdateAppendsNothing(): void {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(
			['@self' => ['id' => 'cm-1', 'schema' => 'contactmoment'], 'case' => 'case-1', 'direction' => 'inbound']
		);

		$this->listener()->handle(new ObjectUpdatedEvent($entity, $entity));

		$this->assertSame([], $this->appended);
	}//end testAnUpdateAppendsNothing()

	/**
	 * Pipelinq refusing the append does not escape the listener: the dossiq
	 * record is already saved, and a throw here would undo nothing and break
	 * the request that saved it.
	 *
	 * @return void
	 */
	public function testAPipelinqRefusalDoesNotEscape(): void {
		$this->createAnswer = ['status' => 403, 'error' => 'You may not read this object.'];

		$this->listener()->handle($this->created(['case' => 'case-1', 'direction' => 'outbound', 'nature' => 'report']));

		$this->assertCount(1, $this->appended, 'the append was asked for and refused');
	}//end testAPipelinqRefusalDoesNotEscape()

	/**
	 * Without pipelinq the listener does nothing and says nothing.
	 *
	 * @return void
	 */
	public function testWithoutPipelinqNothingHappens(): void {
		$this->listener(false)->handle($this->created(['case' => 'case-1', 'direction' => 'inbound']));

		$this->assertSame([], $this->appended);
	}//end testWithoutPipelinqNothingHappens()
}//end class
