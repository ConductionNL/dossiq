<?php

/**
 * Unit tests for DeadlineEscalationService after the two priorities were
 * pulled apart.
 *
 * 🔴 THE FAILURE THIS GUARDS IS TWO FIELDS CALLED PRIORITY. The escalation
 * matrix carried `low`, `medium`, `high`, `critical` under the key `priority`,
 * and the case carries `low`, `normal`, `high`, `urgent` under the same word.
 * Two vocabularies, one name, and nothing anywhere to say they disagree: a
 * reader of an escalation payload could reasonably conclude a case was `high`
 * when the case itself said `normal`. So these tests assert on the KEYS as well
 * as on the values, because the rename is the fix and a rename is exactly the
 * kind of change a value assertion does not notice.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CasePriorityRaiseService;
use OCA\Dossiq\Service\CasePriorityService;
use OCA\Dossiq\Service\DeadlineEscalationService;
use OCA\Dossiq\Service\TermijnService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;

class DeadlineEscalationServiceTest extends TestCase {

	/**
	 * A logger that keeps every context it was handed.
	 *
	 * @return LoggerInterface&object{records: array<int, array<string, mixed>>}
	 */
	private function recordingLogger(): LoggerInterface {
		return new class extends AbstractLogger {
			/**
			 * Every context logged.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $records = [];

			/**
			 * Record one entry.
			 *
			 * @param mixed $level The level.
			 * @param string|Stringable $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->records[] = $context;
			}
		};
	}//end recordingLogger()

	/**
	 * An escalation service whose raise rule answers this priority.
	 *
	 * @param LoggerInterface $logger       The logger to record against.
	 * @param string          $casePriority What the case reads after the rule ran.
	 *
	 * @return DeadlineEscalationService The service.
	 */
	private function service(LoggerInterface $logger, string $casePriority = 'normal'): DeadlineEscalationService {
		$raiser = $this->createMock(originalClassName: CasePriorityRaiseService::class);
		$raiser->method('raiseForThreshold')->willReturn($casePriority);

		return new DeadlineEscalationService(
			termService: $this->createMock(originalClassName: TermijnService::class),
			priorityRaiseService: $raiser,
			logger: $logger
		);
	}//end service()

	/**
	 * Nothing the escalation reports is called a priority except the case's own
	 * (REQ-PRI-06).
	 *
	 * @return void
	 */
	public function testTheEscalationPayloadCallsOnlyTheCasePriorityAPriority(): void {
		$logger = $this->recordingLogger();
		$this->service(logger: $logger, casePriority: 'urgent')->notifyThreshold(
			['id' => 'ti-1', 'case' => 'case-1', 'endDateCurrent' => '2026-10-01'],
			2
		);

		self::assertCount(expectedCount: 1, haystack: $logger->records);
		$payload = $logger->records[0];

		self::assertArrayNotHasKey(key: 'priority', array: $payload, message: 'the ambiguous key must be gone');
		self::assertSame(expected: 'high', actual: $payload['notificationUrgency']);
		self::assertSame(expected: 'urgent', actual: $payload['casePriority']);
	}//end testTheEscalationPayloadCallsOnlyTheCasePriorityAPriority()

	/**
	 * The escalation reports the case's priority, not its own vocabulary
	 * (REQ-PRI-06). The two differ here on purpose: a notification urgency of
	 * `high` beside a case priority of `urgent` is the exact pair that used to
	 * be indistinguishable.
	 *
	 * @return void
	 */
	public function testTheEscalationReportsTheCasePriority(): void {
		$logger = $this->recordingLogger();
		$this->service(logger: $logger, casePriority: 'urgent')->notifyThreshold(
			['id' => 'ti-1', 'case' => 'case-1'],
			2
		);

		$payload = $logger->records[0];
		self::assertNotSame(expected: $payload['notificationUrgency'], actual: $payload['casePriority']);
		self::assertContains(needle: $payload['casePriority'], haystack: CasePriorityService::PRIORITY_VALUES);
	}//end testTheEscalationReportsTheCasePriority()

	/**
	 * The rule is asked for the case that fired, at the threshold that fired.
	 * Passing the termijn instance id, or a fixed threshold, would raise the
	 * wrong case or raise it at the wrong moment, and neither would error.
	 *
	 * @return void
	 */
	public function testTheRuleIsAskedForTheCaseAndThresholdThatFired(): void {
		$raiser = $this->createMock(originalClassName: CasePriorityRaiseService::class);
		$raiser->expects(self::once())
			->method('raiseForThreshold')
			->with('case-42', 0)
			->willReturn('urgent');

		$service = new DeadlineEscalationService(
			termService: $this->createMock(originalClassName: TermijnService::class),
			priorityRaiseService: $raiser,
			logger: $this->recordingLogger()
		);

		$service->notifyThreshold(['id' => 'ti-9', 'case' => 'case-42'], 0);
	}//end testTheRuleIsAskedForTheCaseAndThresholdThatFired()

	/**
	 * A duplicate threshold neither notifies nor raises. The rule must not run
	 * on a catch-up fire for a threshold already handled, or a case could be
	 * lifted twice by the same rung after a migration repair step.
	 *
	 * @return void
	 */
	public function testADuplicateThresholdDoesNotRunTheRule(): void {
		$raiser = $this->createMock(originalClassName: CasePriorityRaiseService::class);
		$raiser->expects(self::never())->method('raiseForThreshold');

		$service = new DeadlineEscalationService(
			termService: $this->createMock(originalClassName: TermijnService::class),
			priorityRaiseService: $raiser,
			logger: $this->recordingLogger()
		);

		self::assertFalse(
			condition: $service->notifyThreshold(
				['id' => 'ti-1', 'case' => 'c-1', 'notificatiesVerstuurd' => [2]],
				2
			)
		);
	}//end testADuplicateThresholdDoesNotRunTheRule()

	/**
	 * Every row of the matrix names its urgency as a notification urgency, and
	 * none of them is called a priority (REQ-PRI-06).
	 *
	 * @return void
	 */
	public function testEveryMatrixRowNamesANotificationUrgency(): void {
		$matrix = $this->service(logger: $this->recordingLogger())->matrix();

		self::assertSame(expected: [14, 7, 2, 0], actual: array_keys($matrix));
		foreach ($matrix as $threshold => $row) {
			self::assertArrayHasKey(key: 'notificationUrgency', array: $row, message: (string)$threshold);
			self::assertArrayNotHasKey(key: 'priority', array: $row, message: (string)$threshold);
		}
	}//end testEveryMatrixRowNamesANotificationUrgency()

	/**
	 * The notification urgency vocabulary is deliberately NOT the case
	 * priority vocabulary. If the two ever became the same four words, the
	 * rename would have bought nothing and a reader would be back to assuming
	 * they agree.
	 *
	 * @return void
	 */
	public function testTheTwoVocabulariesStayDistinct(): void {
		$urgencies = array_column(
			$this->service(logger: $this->recordingLogger())->matrix(),
			'notificationUrgency'
		);

		self::assertSame(expected: ['low', 'medium', 'high', 'critical'], actual: $urgencies);
		self::assertNotSame(expected: CasePriorityService::PRIORITY_VALUES, actual: $urgencies);
	}//end testTheTwoVocabulariesStayDistinct()

	/**
	 * A termijn notification goes out even when the case cannot be read.
	 *
	 * 🔴 THE TRADE THIS PINS. A statutory deadline warning must not depend on a
	 * priority lookup succeeding. The read failing leaves the priority unknown
	 * and logged; it must not swallow the notification, and it must not report
	 * a priority nobody actually asked the case for.
	 *
	 * @return void
	 */
	public function testTheNotificationStillGoesOutWhenTheCaseCannotBeRead(): void {
		$logger = $this->recordingLogger();
		$raiser = $this->createMock(originalClassName: CasePriorityRaiseService::class);
		$raiser->method('raiseForThreshold')->willThrowException(new RuntimeException('store down'));

		$service = new DeadlineEscalationService(
			termService: $this->createMock(originalClassName: TermijnService::class),
			priorityRaiseService: $raiser,
			logger: $logger
		);

		self::assertTrue(condition: $service->notifyThreshold(['id' => 'ti-1', 'case' => 'c-1'], 0));

		$dispatched = end($logger->records);
		self::assertSame(expected: 'critical', actual: $dispatched['notificationUrgency']);
		self::assertSame(expected: '', actual: $dispatched['casePriority'], message: 'unknown, not guessed');
	}//end testTheNotificationStillGoesOutWhenTheCaseCannotBeRead()

	/**
	 * An instance with no id is refused before anything is written.
	 *
	 * @return void
	 */
	public function testAnInstanceWithNoIdIsRefused(): void {
		self::assertFalse(
			condition: $this->service(logger: $this->recordingLogger())->notifyThreshold(['case' => 'c-1'], 2)
		);
	}//end testAnInstanceWithNoIdIsRefused()

	/**
	 * The threshold buckets are unchanged by this change.
	 *
	 * @return void
	 */
	public function testTheThresholdBucketsAreUnchanged(): void {
		$service = $this->service(logger: $this->recordingLogger());

		self::assertSame(expected: [14, 7, 2, 0], actual: $service->thresholds());
		self::assertSame(expected: 7, actual: $service->bucketFor(daysToDeadline: 7));
		self::assertSame(expected: 0, actual: $service->bucketFor(daysToDeadline: -3));
		self::assertNull(actual: $service->bucketFor(daysToDeadline: 30));
	}//end testTheThresholdBucketsAreUnchanged()
}//end class
