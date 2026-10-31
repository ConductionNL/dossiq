<?php

/**
 * A draft case is not numbered, grouped or planned; a sent draft is, once.
 *
 * Each check starts at the registrars: the listener is taken from what they
 * registered for the event, so a listener that skips a draft and is never told
 * a draft was sent fails here.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Registrar\IntakeListenerRegistrar;
use OCA\Dossiq\AppInfo\Registrar\IntakeObligationListenerRegistrar;
use OCA\Dossiq\AppInfo\Registrar\WorkflowListenerRegistrar;
use OCA\Dossiq\BackgroundJob\AcknowledgementDispatchJob;
use OCA\Dossiq\BackgroundJob\ReportGroupingJob;
use OCA\Dossiq\Listener\AcknowledgementOnCreateListener;
use OCA\Dossiq\Listener\CaseNumberListener;
use OCA\Dossiq\Listener\CasePlanProjectionListener;
use OCA\Dossiq\Listener\DeadlineCaseCreatedListener;
use OCA\Dossiq\Listener\IntakeObligationListener;
use OCA\Dossiq\Listener\ReportGroupingOnCreateListener;
use OCA\Dossiq\Service\CaseNumberService;
use OCA\Dossiq\Service\CasePlanProjectionService;
use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\Intake\ReceiptMoment;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Obligations\IntakeObligations;
use OCA\Dossiq\Service\TermijnService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectActivatedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The create listeners skip a draft and run when it is sent.
 *
 * @covers \OCA\Dossiq\AppInfo\Registrar\IntakeListenerRegistrar
 * @covers \OCA\Dossiq\AppInfo\Registrar\IntakeFormListenerRegistrar
 * @covers \OCA\Dossiq\AppInfo\Registrar\WorkflowListenerRegistrar
 * @covers \OCA\Dossiq\Listener\AcknowledgementOnCreateListener
 * @covers \OCA\Dossiq\Listener\CaseNumberListener
 * @covers \OCA\Dossiq\Listener\CasePlanProjectionListener
 * @covers \OCA\Dossiq\Listener\DeadlineCaseCreatedListener
 * @covers \OCA\Dossiq\Listener\ReportGroupingOnCreateListener
 * @uses   \OCA\Dossiq\Service\Intake\ReceiptMoment
 * @uses   \OCA\Dossiq\AppInfo\Registrar\TaskListenerRegistrar
 */
class DraftIsNotReceivedRegistrationTest extends TestCase {

	private const CASE_ID = '6f1d0c7a-52a4-4b61-9d3e-0c2f8a7b1e10';

	/**
	 * Whether both registrars together name this listener for this event, exactly once.
	 *
	 * @param string $listener The listener class.
	 * @param string $event    The event class.
	 *
	 * @return bool True when it is registered once.
	 */
	private function registeredOnce(string $listener, string $event): bool {
		$registered = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$registered): void {
				$registered[$listener][$event][] = $priority;
			}
		);

		(new WorkflowListenerRegistrar())->register(context: $context);
		(new IntakeListenerRegistrar())->register(context: $context);
		(new IntakeObligationListenerRegistrar())->register(context: $context);

		return count($registered[$listener][$event] ?? []) === 1;
	}//end registeredOnce()

	/**
	 * The three moments a case can reach a create listener in.
	 *
	 * @return array<string, Event> A draft create, a received create and a sent draft.
	 */
	private static function moments(): array {
		$case = static function (?string $status): ObjectEntity {
			$entity = new ObjectEntity();
			$entity->setUuid(self::CASE_ID);
			$entity->setSchema('3');
			$entity->setStatus($status);
			$entity->setObject(['title' => 'Verhuizing', 'caseType' => 'type-1', 'intakeObligations' => [['kind' => 'cosign', 'state' => 'awaiting']]]);

			return $entity;
		};

		return [
			'draft' => new ObjectCreatedEvent($case(ReceiptMoment::STATUS_DRAFT)),
			'received' => new ObjectCreatedEvent($case(null)),
			'sent' => new ObjectActivatedEvent($case(null), new DateTimeImmutable('2026-10-11T21:40:00+02:00')),
		];
	}//end moments()

	/**
	 * A resolver that calls every payload a case.
	 *
	 * @return ObjectSchemaSlugResolver The resolver.
	 */
	private function caseResolver(): ObjectSchemaSlugResolver {
		$resolver = $this->createMock(ObjectSchemaSlugResolver::class);
		$resolver->method('resolveFromPayload')->willReturn('case');

		return $resolver;
	}//end caseResolver()

	/**
	 * The event names the registrars use are the one the receipt moment reads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-draft-case-must-not-be-treated-as-received
	 */
	public function testTheDraftSentEventIsOneEvent(): void {
		self::assertSame(ReceiptMoment::ACTIVATED_EVENT, IntakeListenerRegistrar::DRAFT_SENT_EVENT);
		self::assertSame(ReceiptMoment::ACTIVATED_EVENT, ObjectActivatedEvent::class);
	}//end testTheDraftSentEventIsOneEvent()

	/**
	 * A draft gets no case number; a received case and a sent draft each get one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-draft-case-must-not-be-treated-as-received
	 */
	public function testADraftIsNumberedWhenItIsSent(): void {
		self::assertTrue($this->registeredOnce(CaseNumberListener::class, ObjectCreatedEvent::class));
		self::assertTrue($this->registeredOnce(CaseNumberListener::class, ObjectActivatedEvent::class), 'Nothing tells the listener a draft was sent.');

		foreach (['draft' => 0, 'received' => 1, 'sent' => 1] as $moment => $times) {
			$numbers = $this->createMock(CaseNumberService::class);
			$numbers->expects(self::exactly($times))->method('assign')
				->with(self::callback(static fn (array $case): bool => ($case['id'] ?? '') === self::CASE_ID))
				->willReturn('Z-2026-000001');

			(new CaseNumberListener($numbers, $this->caseResolver()))->handle(self::moments()[$moment]);
		}
	}//end testADraftIsNumberedWhenItIsSent()

	/**
	 * A draft is not placed in a group of similar reports; a received case and a sent draft are.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-draft-case-must-not-be-treated-as-received
	 */
	public function testADraftIsGroupedWhenItIsSent(): void {
		self::assertTrue($this->registeredOnce(ReportGroupingOnCreateListener::class, ObjectCreatedEvent::class));
		self::assertTrue($this->registeredOnce(ReportGroupingOnCreateListener::class, ObjectActivatedEvent::class), 'Nothing tells the listener a draft was sent.');

		foreach (['draft' => 0, 'received' => 1, 'sent' => 1] as $moment => $times) {
			$jobs = $this->createMock(IJobList::class);
			$jobs->expects(self::exactly($times))->method('add')->with(ReportGroupingJob::class, ['caseId' => self::CASE_ID]);

			(new ReportGroupingOnCreateListener($jobs, $this->caseResolver()))->handle(self::moments()[$moment]);
		}
	}//end testADraftIsGroupedWhenItIsSent()

	/**
	 * A draft has no case plan; a received case and a sent draft get theirs projected.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-draft-case-must-not-be-treated-as-received
	 */
	public function testADraftIsPlannedWhenItIsSent(): void {
		self::assertTrue($this->registeredOnce(CasePlanProjectionListener::class, ObjectCreatedEvent::class));
		self::assertTrue($this->registeredOnce(CasePlanProjectionListener::class, ObjectActivatedEvent::class), 'Nothing tells the listener a draft was sent.');

		foreach (['draft' => 0, 'received' => 1, 'sent' => 1] as $moment => $times) {
			$projection = $this->createMock(CasePlanProjectionService::class);
			$projection->expects(self::exactly($times))->method('projectForCase')
				->with(self::CASE_ID, 'type-1')
				->willReturn(['projected' => true, 'reason' => '']);

			(new CasePlanProjectionListener($projection, $this->caseResolver(), $this->createMock(LoggerInterface::class)))
				->handle(self::moments()[$moment]);
		}
	}//end testADraftIsPlannedWhenItIsSent()

	/**
	 * Every listener both registrars put on the created event and on the draft sent event.
	 *
	 * @return array<int, string> The listener classes, sorted.
	 */
	private function listenersOnBothEvents(): array {
		$registered = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$registered): void {
				$registered[$event][$listener] = true;
			}
		);

		(new WorkflowListenerRegistrar())->register(context: $context);
		(new IntakeListenerRegistrar())->register(context: $context);
		(new IntakeObligationListenerRegistrar())->register(context: $context);

		$both = array_keys(
			array_intersect_key(
				($registered[ObjectCreatedEvent::class] ?? []),
				($registered[ObjectActivatedEvent::class] ?? [])
			)
		);
		sort($both);

		return $both;
	}//end listenersOnBothEvents()

	/**
	 * How often each listener on both events did its creation work, per way a case can arrive.
	 *
	 * One listener instance takes the whole sequence, as the container hands
	 * one instance to both registrations.
	 *
	 * @param array<int, Event> $sequence The events of one arrival, in order.
	 *
	 * @return array<string, int> Listener class => times its work ran.
	 */
	private function workDoneOver(array $sequence): array {
		$count = [];
		$counter = static function (string $listener) use (&$count): callable {
			$count[$listener] = 0;

			return static function () use (&$count, $listener): void {
				$count[$listener]++;
			};
		};

		$numbers = $this->createMock(CaseNumberService::class);
		$count[CaseNumberListener::class] = 0;
		$numbers->method('assign')->willReturnCallback(
			static function () use (&$count): string {
				$count[CaseNumberListener::class]++;

				return 'Z-2026-000001';
			}
		);

		$groupingJobs = $this->createMock(IJobList::class);
		$groupingJobs->method('add')->willReturnCallback($counter(ReportGroupingOnCreateListener::class));

		$projection = $this->createMock(CasePlanProjectionService::class);
		$count[CasePlanProjectionListener::class] = 0;
		$projection->method('projectForCase')->willReturnCallback(
			static function () use (&$count): array {
				$count[CasePlanProjectionListener::class]++;

				return ['projected' => true, 'reason' => ''];
			}
		);

		$acknowledgementJobs = $this->createMock(IJobList::class);
		$acknowledgementJobs->method('add')->with(AcknowledgementDispatchJob::class, self::anything())
			->willReturnCallback($counter(AcknowledgementOnCreateListener::class));

		$terms = $this->createMock(TermijnService::class);
		$count[DeadlineCaseCreatedListener::class] = 0;
		$terms->method('createTermijnInstance')->willReturnCallback(
			static function () use (&$count): array {
				$count[DeadlineCaseCreatedListener::class]++;

				return ['id' => 'term-1'];
			}
		);
		$intakeObligations = $this->createMock(IntakeObligations::class);
		$intakeObligations->method('placeFor')->willReturnCallback(
			static function () use (&$count): int {
				$count[IntakeObligationListener::class]++;

				return 1;
			}
		);
		$count[IntakeObligationListener::class] = 0;

		$caseTypes = $this->createMock(CaseTypeSlugResolver::class);
		$caseTypes->method('toSlug')->willReturn('verhuizing');

		$logger = $this->createMock(LoggerInterface::class);
		// One ReceiptMoment for all of them: the container shares it too, and
		// one listener's receipt must not use up another's.
		$receipt = new ReceiptMoment();
		$listeners = [
			new CaseNumberListener($numbers, $this->caseResolver(), $receipt),
			new ReportGroupingOnCreateListener($groupingJobs, $this->caseResolver(), $receipt),
			new CasePlanProjectionListener($projection, $this->caseResolver(), $logger, $receipt),
			new AcknowledgementOnCreateListener($acknowledgementJobs, $this->caseResolver(), $logger, $receipt),
			new DeadlineCaseCreatedListener($terms, $this->caseResolver(), $caseTypes, $logger, null, null, null, $receipt),
			new IntakeObligationListener($receipt, $this->caseResolver(), $intakeObligations),
		];

		foreach ($sequence as $event) {
			foreach ($listeners as $listener) {
				$listener->handle($event);
			}
		}

		ksort($count);

		return $count;
	}//end workDoneOver()

	/**
	 * A sent draft does its creation work once, whichever way OpenRegister announces it.
	 *
	 * OpenRegister on development today: the draft's create is an
	 * `ObjectCreatedEvent` for a draft, sending it is an `ObjectActivatedEvent`.
	 * OpenRegister with decision 227: the draft's create reaches no listener
	 * here, sending it is an `ObjectCreatedEvent` and then an
	 * `ObjectActivatedEvent` for the same object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-draft-case-must-not-be-treated-as-received
	 */
	public function testASentDraftDoesItsCreationWorkOnceUnderBothEventModels(): void {
		$onBoth = $this->listenersOnBothEvents();
		self::assertSame(
			[
				AcknowledgementOnCreateListener::class,
				CaseNumberListener::class,
				CasePlanProjectionListener::class,
				DeadlineCaseCreatedListener::class,
				IntakeObligationListener::class,
				ReportGroupingOnCreateListener::class,
			],
			$onBoth,
			'A listener on both the created and the draft sent event must be in this test: it runs twice for a sent draft unless it asks ReceiptMoment.'
		);
		$once = array_fill_keys($onBoth, 1);
		$never = array_fill_keys($onBoth, 0);
		$moments = self::moments();

		self::assertSame($never, $this->workDoneOver([$moments['draft']]), 'A draft that is never sent.');
		self::assertSame($once, $this->workDoneOver([$moments['received']]), 'A case created outright.');
		self::assertSame($once, $this->workDoneOver([$moments['draft'], $moments['sent']]), 'A sent draft on OpenRegister today.');
		self::assertSame($once, $this->workDoneOver([$moments['received'], $moments['sent']]), 'A sent draft on OpenRegister with decision 227.');
	}//end testASentDraftDoesItsCreationWorkOnceUnderBothEventModels()
}//end class
