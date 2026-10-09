<?php

/**
 * CaseCompletedSatisfactionListener unit tests.
 *
 * REQ-PLQ-07: a case moving onto a terminal status is handed to pipelinq's
 * satisfaction loop, once, after the save, and never blocks it. The gateway,
 * the consumer and the status reader are the REAL classes; only pipelinq's
 * `SurveyDispatchService` is a recorder with its real signature
 * (`onInteractionCompleted(string $entityType, array $entity, array $contact)`,
 * pipelinq origin/development), because a dynamic call compiles even when the
 * other side renamed it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CaseCompletedSatisfactionListener;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use OCA\Dossiq\Service\Pipelinq\ProgrammeConsumer;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Listener\CaseCompletedSatisfactionListener
 * @uses   \OCA\Dossiq\Service\ObjectSchemaSlugResolver
 * @uses   \OCA\Dossiq\Service\Pipelinq\PipelinqGateway
 * @uses   \OCA\Dossiq\Service\Pipelinq\ProgrammeConsumer
 * @uses   \OCA\Dossiq\Service\Transitions\CaseTypeReader
 */
class CaseCompletedSatisfactionListenerTest extends TestCase {

	/**
	 * What pipelinq's dispatch service was told, call by call.
	 *
	 * @var object
	 */
	private object $survey;

	/**
	 * Build the listener; pipelinq present unless told otherwise.
	 *
	 * @param bool $pipelinq Whether pipelinq serves the dispatch service.
	 * @param bool $throws   Whether that service throws.
	 * @param CaseTypeReader|null $reader A status reader to use instead of the real one.
	 *
	 * @return CaseCompletedSatisfactionListener The listener.
	 */
	private function listener(bool $pipelinq = true, bool $throws = false, ?CaseTypeReader $reader = null): CaseCompletedSatisfactionListener {
		$this->survey = new class($throws) {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * @param bool $throws Whether the call throws.
			 */
			public function __construct(private bool $throws) {
			}

			/**
			 * pipelinq's signature.
			 *
			 * @param string               $entityType The completed record's type.
			 * @param array<string, mixed> $entity     The record.
			 * @param array<string, mixed> $contact    The recipient.
			 *
			 * @return array<int, array<string, mixed>> The invitations written.
			 */
			public function onInteractionCompleted(string $entityType, array $entity, array $contact): array {
				if ($this->throws === true) {
					throw new RuntimeException('pipelinq broke');
				}

				$this->calls[] = ['entityType' => $entityType, 'entity' => $entity, 'contact' => $contact];
				return [];
			}
		};

		$survey = $this->survey;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($pipelinq, $survey): object {
				if ($pipelinq === true && $id === PipelinqGateway::SURVEY_DISPATCH) {
					return $survey;
				}

				throw new RuntimeException("nothing answers to {$id}");
			}
		);

		$statusTypes = new class {
			/**
			 * Read a statusType.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array<string, mixed> The row.
			 */
			public function find(string $id, string $register, string $schema): array {
				return match ($id) {
					'st-afgehandeld' => ['id' => $id, 'name' => 'Afgehandeld', 'isFinal' => true],
					'st-in-behandeling' => ['id' => $id, 'name' => 'In behandeling', 'isFinal' => false],
					default => [],
				};
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($statusTypes);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'status_type_schema' => 'statusType',
				default => $default,
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		return new CaseCompletedSatisfactionListener(
			new ObjectSchemaSlugResolver($container, $logger),
			($reader ?? new CaseTypeReader($settings)),
			new ProgrammeConsumer(new PipelinqGateway($container, $logger), $logger),
			$logger,
		);
	}//end listener()

	/**
	 * A case entity.
	 *
	 * @param string $status The statusType it is in.
	 * @param string $schema Its schema.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function caseIn(string $status, string $schema = 'case'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject(
			[
				'id' => 'case-1',
				'title' => 'Melding openbare ruimte',
				'status' => $status,
				'initiatorSourceId' => 'contact-42',
				'initiatorDisplayName' => 'Sanne de Vries',
				'initiatorType' => 'person',
			]
		);
		$entity->setSchema($schema);
		$entity->setUuid('case-1');

		return $entity;
	}//end caseIn()

	/**
	 * Closing a case hands off: the case, `closed`, the uuid kept, and the party.
	 *
	 * @return void
	 */
	public function testAClosingCaseHandsOff(): void {
		$this->listener()->handle(
			new ObjectUpdatedEvent($this->caseIn('st-afgehandeld'), $this->caseIn('st-in-behandeling'))
		);

		$this->assertCount(1, $this->survey->calls);
		$call = $this->survey->calls[0];
		$this->assertSame('case', $call['entityType']);
		$this->assertSame(CaseCompletedSatisfactionListener::COMPLETED_STATUS, $call['entity']['status']);
		$this->assertSame('st-afgehandeld', $call['entity']['statusType']);
		$this->assertSame('case-1', $call['entity']['id']);
		$this->assertSame('contact-42', $call['contact']['id']);
	}//end testAClosingCaseHandsOff()

	/**
	 * A move between open statuses hands nothing off.
	 *
	 * @return void
	 */
	public function testAnOpenMoveHandsNothingOff(): void {
		$this->listener()->handle(
			new ObjectUpdatedEvent($this->caseIn('st-in-behandeling'), $this->caseIn('st-nieuw'))
		);

		$this->assertSame([], $this->survey->calls);
	}//end testAnOpenMoveHandsNothingOff()

	/**
	 * An edit of a case that was already closed is not a second completion.
	 *
	 * @return void
	 */
	public function testAnEditOfAClosedCaseIsNotASecondCompletion(): void {
		$this->listener()->handle(
			new ObjectUpdatedEvent($this->caseIn('st-afgehandeld'), $this->caseIn('st-afgehandeld'))
		);

		$this->assertSame([], $this->survey->calls);
	}//end testAnEditOfAClosedCaseIsNotASecondCompletion()

	/**
	 * Another schema's object in a final-looking status is not a case.
	 *
	 * @return void
	 */
	public function testOnlyCasesAreHandedOff(): void {
		$this->listener()->handle(
			new ObjectUpdatedEvent($this->caseIn('st-afgehandeld', 'task'), $this->caseIn('st-in-behandeling', 'task'))
		);

		$this->assertSame([], $this->survey->calls);
	}//end testOnlyCasesAreHandedOff()

	/**
	 * Without pipelinq, or with a pipelinq that throws, the save is untouched.
	 *
	 * @return void
	 */
	public function testTheHandoffNeverBlocksTheSave(): void {
		$event = new ObjectUpdatedEvent($this->caseIn('st-afgehandeld'), $this->caseIn('st-in-behandeling'));

		$this->listener(pipelinq: false)->handle($event);
		$this->listener(pipelinq: true, throws: true)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testTheHandoffNeverBlocksTheSave()

	/**
	 * An event that is not an update is none of this listener's business.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$this->listener()->handle(new \OCP\EventDispatcher\Event());

		$this->assertSame([], $this->survey->calls);
	}//end testAnotherEventIsIgnored()

	/**
	 * A case without a named initiator is still handed off, with no party.
	 *
	 * @return void
	 */
	public function testACaseWithoutAnInitiatorIsHandedOffWithNoParty(): void {
		$after = $this->caseIn('st-afgehandeld');
		$after->setObject(['id' => 'case-1', 'status' => 'st-afgehandeld']);

		$this->listener()->handle(new ObjectUpdatedEvent($after, $this->caseIn('st-in-behandeling')));

		$this->assertCount(1, $this->survey->calls);
		$this->assertSame([], $this->survey->calls[0]['contact']);
	}//end testACaseWithoutAnInitiatorIsHandedOffWithNoParty()

	/**
	 * A status held as a reference row (or as junk) reads the same as an id.
	 *
	 * @return void
	 */
	public function testAStatusReferenceRowIsReadAndJunkIsNot(): void {
		$row = $this->caseIn('x');
		$row->setObject(['id' => 'case-1', 'status' => ['id' => 'st-afgehandeld']]);
		$junk = $this->caseIn('x');
		$junk->setObject(['id' => 'case-1', 'status' => 42]);
		$open = $this->caseIn('st-in-behandeling');

		$this->listener()->handle(new ObjectUpdatedEvent($junk, $open));
		$this->assertSame([], $this->survey->calls);

		$this->listener()->handle(new ObjectUpdatedEvent($row, $open));
		$this->assertCount(1, $this->survey->calls);
	}//end testAStatusReferenceRowIsReadAndJunkIsNot()

	/**
	 * An entity that cannot be read is skipped, not fatal.
	 *
	 * @return void
	 */
	public function testAnUnreadableEntityIsSkipped(): void {
		$broken = new class extends ObjectEntity {
			/**
			 * Always fails.
			 *
			 * @return array<string, mixed> Never returns.
			 */
			public function jsonSerialize(): array {
				throw new RuntimeException('unreadable');
			}
		};

		$this->listener()->handle(new ObjectUpdatedEvent($broken, $this->caseIn('st-in-behandeling')));

		$this->assertSame([], $this->survey->calls);
	}//end testAnUnreadableEntityIsSkipped()

	/**
	 * A failure inside the hand-off is logged and swallowed.
	 *
	 * @return void
	 */
	public function testAFailureInTheHandoffIsSwallowed(): void {
		$reader = $this->createMock(CaseTypeReader::class);
		$reader->method('isFinalStatus')->willThrowException(new RuntimeException('register down'));
		$event = new ObjectUpdatedEvent($this->caseIn('st-afgehandeld'), $this->caseIn('st-in-behandeling'));

		$this->listener(reader: $reader)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAFailureInTheHandoffIsSwallowed()
}//end class
