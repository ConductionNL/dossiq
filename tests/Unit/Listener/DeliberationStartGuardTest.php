<?php

/**
 * A status write that starts deliberation runs the panel check before OpenRegister saves it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/every-move-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\AppInfo\Registrar\BezwaarListenerRegistrar;
use OCA\Dossiq\Listener\DeliberationStartGuard;
use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService;
use OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail;
use OCA\Dossiq\Service\Bezwaar\PanelIndependenceChecker;
use OCA\Dossiq\Tests\Support\MakesBezwaarAuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Writes to an advice request's status, through the listeners the app registers on OpenRegister's pre-save event.
 *
 * @covers \OCA\Dossiq\Listener\DeliberationStartGuard
 * @covers \OCA\Dossiq\AppInfo\Registrar\BezwaarListenerRegistrar
 *
 * @uses \OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarEntryNotWrittenException
 * @uses \OCA\Dossiq\Service\Transitions\GuardFailedException
 */
class DeliberationStartGuardTest extends TestCase {
	use MakesBezwaarAuditTrail;

	/**
	 * Start every case with an empty store and trail.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->startBezwaarStore();
	}//end setUp()

	/**
	 * A conflicted panel: the write is refused, the status stays, the trail names the member.
	 *
	 * @return void
	 */
	public function testAConflictedPanelDoesNotStartDeliberating(): void {
		$this->seedRequest(row: ['status' => 'assigned', 'panel' => ['a.bakker', 'p.janssen']]);
		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail(), conflict: 'p.janssen');

		$refusal = $this->writeStatus(service: $service, status: 'in-deliberation');

		$this->assertNotNull(actual: $refusal, message: 'a conflicted panel must not start deliberating');
		$this->assertStringStartsWith(prefix: 'Panel member conflict (Awb Art. 7:13 lid 3)', string: $refusal->getMessage());
		$this->assertSame(expected: DeliberationStartGuard::ERROR_CODE, actual: $refusal->getErrors()['code']);
		$this->assertSame(expected: 'assigned', actual: $this->store->row('bacAdviceRequest', 'req-1')['status']);
		$this->assertSame(expected: ['dossiq.bezwaar.independence-check-failed'], actual: $this->trail->actionsOn('req-1'));
		$entry = $this->rowContext(uuid: 'req-1', action: 'dossiq.bezwaar.independence-check-failed');
		$this->assertSame(expected: 'p.janssen', actual: $entry['payload']['conflictingMember']);
	}//end testAConflictedPanelDoesNotStartDeliberating()

	/**
	 * An unverifiable panel: refused, and the trail says so without a member.
	 *
	 * @return void
	 */
	public function testAnUnverifiablePanelDoesNotStartDeliberating(): void {
		$reason = 'Onafhankelijkheid van het panel kon niet worden vastgesteld (Awb Art. 7:13 lid 3)';
		$this->seedRequest(row: ['status' => 'assigned', 'panel' => ['a.bakker']]);
		$this->independenceVerdict = ['ok' => false, 'member' => null, 'reason' => $reason];
		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail());

		$refusal = $this->writeStatus(service: $service, status: 'in-deliberation');

		$this->assertNotNull(actual: $refusal, message: 'an unverifiable panel must not start deliberating');
		$this->assertStringStartsWith(prefix: 'Panel independence could not be verified', string: $refusal->getMessage());
		$this->assertSame(expected: 'assigned', actual: $this->store->row('bacAdviceRequest', 'req-1')['status']);
		$this->assertSame(
			expected: ['conflictingMember' => null, 'reason' => $reason],
			actual: $this->rowContext(uuid: 'req-1', action: 'dossiq.bezwaar.independence-check-unverifiable')['payload']
		);
	}//end testAnUnverifiablePanelDoesNotStartDeliberating()

	/**
	 * Without a panel the write is refused with the panel message.
	 *
	 * @return void
	 */
	public function testARequestWithoutAPanelDoesNotStartDeliberating(): void {
		$this->seedRequest(row: ['status' => 'assigned']);
		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail());

		$refusal = $this->writeStatus(service: $service, status: 'in-deliberation');

		$this->assertNotNull(actual: $refusal, message: 'a request without a panel must not start deliberating');
		$this->assertSame(expected: 'Panel must be set before deliberation can start', actual: $refusal->getMessage());
		$this->assertSame(expected: 'assigned', actual: $this->store->row('bacAdviceRequest', 'req-1')['status']);
	}//end testARequestWithoutAPanelDoesNotStartDeliberating()

	/**
	 * A check that fails unexpectedly refuses the write.
	 *
	 * @return void
	 */
	public function testACheckThatFailsRefusesTheWrite(): void {
		$this->seedRequest(row: ['status' => 'assigned', 'panel' => ['a.bakker']]);
		$independence = $this->createMock(originalClassName: PanelIndependenceChecker::class);
		$independence->method('check')->willThrowException(exception: new \TypeError('checker broke'));
		$service = new AdvisoryCommitteeService(
			settingsService: $this->bezwaarSettings(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			adviceDelegation: $this->createMock(originalClassName: AdviceDelegationService::class),
			auditTrail: $this->bezwaarAuditTrail(),
			independence: $independence,
		);

		$refusal = $this->writeStatus(service: $service, status: 'in-deliberation');

		$this->assertNotNull(actual: $refusal, message: 'a check that fails must not let deliberation start');
		$this->assertSame(expected: DeliberationStartGuard::UNCHECKED_MESSAGE, actual: $refusal->getMessage());
		$this->assertSame(expected: 'assigned', actual: $this->store->row('bacAdviceRequest', 'req-1')['status']);
	}//end testACheckThatFailsRefusesTheWrite()

	/**
	 * An independent panel starts deliberating; edits inside deliberation and other moves are not checked.
	 *
	 * @return void
	 */
	public function testOnlyAMoveIntoDeliberationIsChecked(): void {
		$this->seedRequest(row: ['status' => 'assigned', 'panel' => ['a.bakker']]);
		$before = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail(), conflict: 'a.bakker');
		$this->assertNull(actual: $this->writeStatus(service: $before, status: 'assigned'), message: 'an edit before deliberation is not a move into it');

		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail());
		$this->assertNull(actual: $this->writeStatus(service: $service, status: 'in-deliberation'));
		$this->assertSame(expected: 'in-deliberation', actual: $this->store->row('bacAdviceRequest', 'req-1')['status']);

		$conflicted = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail(), conflict: 'a.bakker');
		$edit = $this->writeStatus(service: $conflicted, status: 'in-deliberation');
		$this->assertNull(actual: $edit, message: 'an edit inside deliberation is not a move into it');
		$this->assertNull(actual: $this->writeStatus(service: $conflicted, status: 'advice-issued'), message: 'a move out of deliberation is not checked');
		$this->assertSame(expected: [], actual: $this->trail->actionsOn('req-1'));
	}//end testOnlyAMoveIntoDeliberationIsChecked()

	/**
	 * The check reads the panel and objection being saved, and the refusal lands on the configured register and schema.
	 *
	 * @return void
	 */
	public function testTheCheckReadsTheRequestAsItWillBeSaved(): void {
		$this->seedRequest(row: ['status' => 'assigned', 'panel' => ['a.bakker']]);
		$asked = [];
		$independence = $this->createMock(originalClassName: PanelIndependenceChecker::class);
		$independence->method('check')->willReturnCallback(
			static function (string $objectionId, array $panel) use (&$asked): array {
				$asked[] = [$objectionId, $panel];
				return ['ok' => false, 'member' => null, 'reason' => 'onverifieerbaar'];
			}
		);
		$recorded = [];
		$trail = $this->createMock(originalClassName: BezwaarAuditTrail::class);
		$trail->method('recordRefusal')->willReturnCallback(
			static function (string $register, string $schema, string $objectUuid, string $event) use (&$recorded): bool {
				$recorded[] = [$register, $schema, $objectUuid, $event];
				return true;
			}
		);
		$service = new AdvisoryCommitteeService(
			settingsService: $this->bezwaarSettings(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			adviceDelegation: $this->createMock(originalClassName: AdviceDelegationService::class),
			auditTrail: $trail,
			independence: $independence,
		);

		$before = $this->store->row('bacAdviceRequest', 'req-1');
		$after = array_merge($before, ['status' => 'in-deliberation', 'panel' => ['p.janssen'], 'bezwaar' => 'bezwaar-2']);
		$event = new ObjectUpdatingEvent(
			$this->entity(schema: 'bacAdviceRequest', row: $after),
			$this->entity(schema: 'bacAdviceRequest', row: $before)
		);
		$this->guard(service: $service)->handle(event: $event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: [['bezwaar-2', ['p.janssen']]], actual: $asked);
		$this->assertSame(expected: [['dossiq', 'bacAdviceRequest', 'req-1', 'independence-check-unverifiable']], actual: $recorded);
	}//end testTheCheckReadsTheRequestAsItWillBeSaved()

	/**
	 * Another schema's status is never checked.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsNotChecked(): void {
		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail(), conflict: 'a.bakker');
		$event = new ObjectUpdatingEvent(
			$this->entity(schema: 'hearingSession', row: ['status' => 'in-deliberation', 'panel' => ['a.bakker']]),
			$this->entity(schema: 'hearingSession', row: ['status' => 'assigned', 'panel' => ['a.bakker']])
		);

		$this->guard(service: $service)->handle(event: $event);

		$this->assertFalse(condition: $event->isPropagationStopped());
	}//end testAnotherSchemaIsNotChecked()

	/**
	 * The guard is bound to OpenRegister's pre-save event.
	 *
	 * @return void
	 */
	public function testTheRegistrarBindsTheGuardBeforeTheSave(): void {
		$this->assertContains(needle: DeliberationStartGuard::class, haystack: $this->boundListeners()[ObjectUpdatingEvent::class] ?? []);
	}//end testTheRegistrarBindsTheGuardBeforeTheSave()

	/**
	 * Seed advice request req-1 on objection bezwaar-1.
	 *
	 * @param array<string, mixed> $row The request's fields.
	 *
	 * @return void
	 */
	private function seedRequest(array $row): void {
		$this->store->seed('bacAdviceRequest', 'req-1', array_merge(['bezwaar' => 'bezwaar-1', 'committee' => 'committee-1'], $row));
	}//end seedRequest()

	/**
	 * Write a status onto req-1 the way OpenRegister saves an update.
	 *
	 * The pre-save event goes to every listener the app binds to it; a stopped
	 * event raises HookStoppedException and nothing is saved, as in
	 * MagicMapper::updateObjectEntity().
	 *
	 * @param AdvisoryCommitteeService $service The service the guard asks.
	 * @param string                   $status  The status to write.
	 *
	 * @return HookStoppedException|null The refusal, or null when the write was saved.
	 */
	private function writeStatus(AdvisoryCommitteeService $service, string $status): ?HookStoppedException {
		$before = $this->store->row('bacAdviceRequest', 'req-1');
		$after = array_merge($before, ['status' => $status]);
		$event = new ObjectUpdatingEvent(
			$this->entity(schema: 'bacAdviceRequest', row: $after),
			$this->entity(schema: 'bacAdviceRequest', row: $before)
		);

		try {
			foreach ($this->boundListeners()[ObjectUpdatingEvent::class] ?? [] as $listener) {
				if ($listener === DeliberationStartGuard::class) {
					$this->guard(service: $service)->handle(event: $event);
				}
			}

			if ($event->isPropagationStopped() === true) {
				throw new HookStoppedException(
					message: (string) ($event->getErrors()['message'] ?? 'Object update rejected by hook'),
					errors: $event->getErrors()
				);
			}
		} catch (HookStoppedException $refused) {
			return $refused;
		}

		$this->store->saveObject(object: $after, register: 'dossiq', schema: 'bacAdviceRequest', uuid: 'req-1');
		return null;
	}//end writeStatus()

	/**
	 * The guard over a service.
	 *
	 * @param AdvisoryCommitteeService $service The service.
	 *
	 * @return DeliberationStartGuard The guard.
	 */
	private function guard(AdvisoryCommitteeService $service): DeliberationStartGuard {
		return new DeliberationStartGuard(
			settingsService: $this->bezwaarSettings(),
			advisoryCommittee: $service,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end guard()

	/**
	 * An OpenRegister entity for req-1.
	 *
	 * @param string               $schema The schema.
	 * @param array<string, mixed> $row    The data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $schema, array $row): ObjectEntity {
		unset($row['id']);
		$entity = new ObjectEntity();
		$entity->setObject($row);
		$entity->setSchema($schema);
		$entity->setUuid('req-1');

		return $entity;
	}//end entity()

	/**
	 * The listeners the bezwaar registrar binds, per event.
	 *
	 * @return array<string, array<int, string>> Listener classes per event class.
	 */
	private function boundListeners(): array {
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$bound = [];
		$context->method('registerEventListener')->willReturnCallback(
			callback: static function (string $event, string $listener) use (&$bound): void {
				$bound[$event][] = $listener;
			}
		);

		(new BezwaarListenerRegistrar())->register(context: $context);

		return $bound;
	}//end boundListeners()
}//end class
