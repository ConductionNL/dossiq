<?php

/**
 * CaseDeleteGuardListener Unit Tests
 *
 * REQ-CM-35: a case is deleted only when nothing holds it, and the refusal
 * names every rule that holds. There is one test per rule, one for two rules
 * meeting on the same case, and one for a case nothing holds, because a guard
 * that refuses everything passes every reject assertion and fails no one until
 * production. The free-case test is that control.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/case-delete-guard/specs/case-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Exception\CaseHeldException;
use OCA\Dossiq\Listener\CaseDeleteGuardListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\CaseDeleteGuardListener
 *
 * @uses \OCA\Dossiq\Exception\CaseHeldException
 */
class CaseDeleteGuardListenerTest extends TestCase {

	/**
	 * The register slug the listener is configured with.
	 */
	private const REGISTER = 'dossiq';

	/**
	 * The schema slug the guard watches.
	 */
	private const CASE_SCHEMA = 'case';

	/**
	 * The schema the statutory terms live in.
	 */
	private const TERM_SCHEMA = 'deadlineInstance';

	/**
	 * The case under test.
	 */
	private const CASE_UUID = '11111111-1111-1111-1111-111111111111';

	/**
	 * Rows the fake object service answers with, keyed by schema slug.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The filters each schema was queried with, keyed by schema slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $queried = [];

	/**
	 * Reset the store between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [self::CASE_SCHEMA => [], self::TERM_SCHEMA => []];
		$this->queried = [];
	}//end setUp()

	/**
	 * A running beslistermijn refuses the delete and says so.
	 *
	 * @return void
	 */
	public function testAnOpenTermBlocksTheDelete(): void {
		$this->rows[self::TERM_SCHEMA] = [['status' => 'lopend']];

		$event = $this->deleteEventFor(payload: $this->closedAndDisposable());
		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped(), 'a running term holds the case');
		$this->assertSame(
			[CaseHeldException::RULE_OPEN_TERM],
			$event->getErrors()['blockedBy'],
		);
		$this->assertStringContainsString(
			'A statutory term on this case is still running.',
			$event->getErrors()['message'],
		);
	}//end testAnOpenTermBlocksTheDelete()

	/**
	 * A term that has stopped does not hold the case.
	 *
	 * Without this, a guard that treated ANY deadlineInstance as a hold would
	 * pass the test above and refuse every case that ever had a term.
	 *
	 * @return void
	 */
	public function testACompletedTermDoesNotBlockTheDelete(): void {
		$this->rows[self::TERM_SCHEMA] = [['status' => 'completed'], ['status' => 'withdrawn']];

		$event = $this->deleteEventFor(payload: $this->closedAndDisposable());
		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped(), 'a finished term holds nothing');
	}//end testACompletedTermDoesNotBlockTheDelete()

	/**
	 * A sub-case refuses the delete of its parent.
	 *
	 * @return void
	 */
	public function testASubCaseBlocksTheDelete(): void {
		$this->rows[self::CASE_SCHEMA] = [['id' => 'child-1']];

		$event = $this->deleteEventFor(payload: $this->closedAndDisposable());
		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			[CaseHeldException::RULE_HAS_SUBCASES],
			$event->getErrors()['blockedBy'],
		);
		$this->assertStringContainsString('This case still has sub-cases.', $event->getErrors()['message']);
		$this->assertSame(
			self::CASE_UUID,
			$this->queried[self::CASE_SCHEMA]['parentCase'],
			'sub-cases are the cases whose parentCase is this one',
		);
	}//end testASubCaseBlocksTheDelete()

	/**
	 * An active legal hold refuses the delete.
	 *
	 * @return void
	 */
	public function testAnActiveLegalHoldBlocksTheDelete(): void {
		$event = $this->deleteEventFor(
			payload: $this->closedAndDisposable(),
			retention: ['legalHold' => ['active' => true, 'reason' => 'bezwaar']],
		);
		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			[CaseHeldException::RULE_LEGAL_HOLD],
			$event->getErrors()['blockedBy'],
		);
		$this->assertStringContainsString('A legal hold is on this case.', $event->getErrors()['message']);
	}//end testAnActiveLegalHoldBlocksTheDelete()

	/**
	 * A RELEASED hold is not a hold.
	 *
	 * @return void
	 */
	public function testAReleasedLegalHoldDoesNotBlockTheDelete(): void {
		$event = $this->deleteEventFor(
			payload: $this->closedAndDisposable(),
			retention: ['legalHold' => ['active' => false, 'reason' => 'bezwaar afgehandeld']],
		);
		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAReleasedLegalHoldDoesNotBlockTheDelete()

	/**
	 * A closed case inside its retention period is refused.
	 *
	 * @return void
	 */
	public function testARunningRetentionPeriodBlocksTheDelete(): void {
		$event = $this->deleteEventFor(
			payload: ['endDate' => '2026-01-31', 'archiveActionDate' => '2046-01-31'],
		);
		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			[CaseHeldException::RULE_IN_RETENTION],
			$event->getErrors()['blockedBy'],
		);
		$this->assertStringContainsString(
			'The retention period of this case has not ended.',
			$event->getErrors()['message'],
		);
	}//end testARunningRetentionPeriodBlocksTheDelete()

	/**
	 * An OPEN case is never held by retention.
	 *
	 * The period counts from the close, so a case still in hand has no
	 * disposal date to be inside of. A guard that read the date alone would
	 * refuse every case whose type sets one in advance.
	 *
	 * @return void
	 */
	public function testAnOpenCaseIsNotHeldByRetention(): void {
		$event = $this->deleteEventFor(
			payload: ['endDate' => '', 'archiveActionDate' => '2046-01-31'],
		);
		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnOpenCaseIsNotHeldByRetention()

	/**
	 * Two rules meeting on one case are named together, in one refusal.
	 *
	 * @return void
	 */
	public function testTwoRulesAreNamedTogether(): void {
		$this->rows[self::TERM_SCHEMA] = [['status' => 'verlengd']];
		$this->rows[self::CASE_SCHEMA] = [['id' => 'child-1']];

		$event = $this->deleteEventFor(payload: $this->closedAndDisposable());
		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			[CaseHeldException::RULE_OPEN_TERM, CaseHeldException::RULE_HAS_SUBCASES],
			$event->getErrors()['blockedBy'],
			'one refusal carries every rule that holds, not the first one hit',
		);

		$message = $event->getErrors()['message'];
		$this->assertStringContainsString('A statutory term on this case is still running.', $message);
		$this->assertStringContainsString('This case still has sub-cases.', $message);
	}//end testTwoRulesAreNamedTogether()

	/**
	 * A case nothing holds is deleted.
	 *
	 * @return void
	 */
	public function testAFreeCaseIsDeleted(): void {
		$event = $this->deleteEventFor(payload: $this->closedAndDisposable());
		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped(), 'nothing holds this case');
		$this->assertSame([], $event->getErrors());
	}//end testAFreeCaseIsDeleted()

	/**
	 * The guard is bound to the case schema and leaves every other one alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsNotGuarded(): void {
		$this->rows[self::TERM_SCHEMA] = [['status' => 'lopend']];

		$event = $this->deleteEventFor(
			payload: $this->closedAndDisposable(),
			schema: 'objection',
		);
		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped(), 'only a case is guarded');
		$this->assertSame([], $this->queried, 'a foreign schema costs no queries');
	}//end testAnotherSchemaIsNotGuarded()

	/**
	 * The refusal body is the one a controller translates to 409.
	 *
	 * @return void
	 */
	public function testTheRefusalBodyRebuildsAsAConflict(): void {
		$this->rows[self::TERM_SCHEMA] = [['status' => 'paused']];

		$event = $this->deleteEventFor(payload: $this->closedAndDisposable());
		$this->listener()->handle($event);

		$held = CaseHeldException::fromHookErrors(errors: $event->getErrors());

		$this->assertNotNull($held, 'the guard body is recognisable to the controller');
		$this->assertSame(409, CaseHeldException::STATUS);
		$this->assertSame([CaseHeldException::RULE_OPEN_TERM], $held->getRules());
		$this->assertSame(CaseHeldException::ERROR_CODE, $event->getErrors()['error']);
	}//end testTheRefusalBodyRebuildsAsAConflict()

	/**
	 * Another hook's refusal is not mistaken for this one.
	 *
	 * @return void
	 */
	public function testAForeignRefusalIsNotRebuilt(): void {
		$this->assertNull(
			CaseHeldException::fromHookErrors(
				errors: ['message' => 'nope', 'code' => 'beschikking.immutable']
			)
		);
		$this->assertNull(
			CaseHeldException::fromHookErrors(
				errors: ['error' => CaseHeldException::ERROR_CODE, 'blockedBy' => ['invented']]
			),
			'a body naming no rule this guard knows is not this guard speaking',
		);
	}//end testAForeignRefusalIsNotRebuilt()

	/**
	 * A case that is closed and past its disposal date, held by nothing.
	 *
	 * @return array<string, mixed> The case payload.
	 */
	private function closedAndDisposable(): array {
		return ['endDate' => '2006-01-31', 'archiveActionDate' => '2016-01-31'];
	}//end closedAndDisposable()

	/**
	 * Build the delete event for a case payload.
	 *
	 * @param array<string, mixed> $payload The case fields.
	 * @param array<string, mixed>|null $retention The OpenRegister retention column.
	 * @param string $schema The schema slug the object carries.
	 *
	 * @return ObjectDeletingEvent
	 */
	private function deleteEventFor(
		array $payload,
		?array $retention = null,
		string $schema = self::CASE_SCHEMA,
	): ObjectDeletingEvent {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		$entity->setSchema($schema);
		$entity->setUuid(self::CASE_UUID);
		$entity->setRetention($retention);

		return new ObjectDeletingEvent($entity);
	}//end deleteEventFor()

	/**
	 * The listener under test, wired to a fake store.
	 *
	 * @return CaseDeleteGuardListener
	 */
	private function listener(): CaseDeleteGuardListener {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return match ($key) {
					'register' => self::REGISTER,
					'case_schema' => self::CASE_SCHEMA,
					'termijn_instance_schema' => self::TERM_SCHEMA,
					default => $default,
				};
			}
		);
		$settingsService->method('getObjectService')->willReturn($this->objectService());

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string
				=> vsprintf($text, (array)$parameters)
		);

		return new CaseDeleteGuardListener(
			$settingsService,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);
	}//end listener()

	/**
	 * A fake OpenRegister object service over {@see self::$rows}.
	 *
	 * Answers the slug search path, which is the one a slug register and slug
	 * schema take, and records the filters so a test can assert WHICH rows the
	 * guard asked for. A guard that asked for the wrong case's sub-cases would
	 * otherwise pass every assertion above.
	 *
	 * @return object
	 */
	private function objectService(): object {
		$rows = &$this->rows;
		$queried = &$this->queried;

		return new class($rows, $queried) {
			/**
			 * Constructor.
			 *
			 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema.
			 * @param array<string, array<string, mixed>> $queried Filters per schema.
			 */
			public function __construct(
				private array &$rows,
				private array &$queried,
			) {
			}

			/**
			 * Search by register and schema slug.
			 *
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 * @param array<string, mixed> $filters Object-field filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->queried[$schema] = $filters;

				return ($this->rows[$schema] ?? []);
			}
		};
	}//end objectService()
}//end class
