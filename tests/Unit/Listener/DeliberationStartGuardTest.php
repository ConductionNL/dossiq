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
 * @spec openspec/changes/creating-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md
 * @spec openspec/changes/only-the-advice-transition-sets-the-outcome/specs/bezwaar-advisory-committee/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\AppInfo\Registrar\BezwaarListenerRegistrar;
use OCA\Dossiq\Listener\DeliberationStartGuard;
use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService;
use OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail;
use OCA\Dossiq\Service\Bezwaar\PanelIndependenceChecker;
use OCA\Dossiq\Service\InternalWriteMarker;
use OCA\Dossiq\Tests\Support\MakesBezwaarAuditTrail;
use OCA\Dossiq\Tests\Support\RefusableRegister;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Writes and creates of an advice request, through the listeners the app registers on OpenRegister's pre-save events.
 *
 * @covers \OCA\Dossiq\Listener\DeliberationStartGuard
 * @covers \OCA\Dossiq\AppInfo\Registrar\BezwaarListenerRegistrar
 *
 * @uses \OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarEntryNotWrittenException
 * @uses \OCA\Dossiq\Service\Bezwaar\PanelIndependenceChecker
 * @uses \OCA\Dossiq\Service\InternalWriteMarker
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
		$issued = $this->internalWrites->runInternal(
			objectId: 'req-1',
			write: fn (): ?HookStoppedException => $this->writeStatus(service: $conflicted, status: 'advice-issued')
		);
		$this->assertNull(actual: $issued, message: 'the advice transition out of deliberation does not run the panel check');
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
		$this->assertContains(needle: DeliberationStartGuard::class, haystack: $this->boundListeners()[ObjectCreatingEvent::class] ?? []);
	}//end testTheRegistrarBindsTheGuardBeforeTheSave()

	/**
	 * Creating a request in in-deliberation with a conflicted panel is refused and nothing is saved.
	 *
	 * No request exists to carry the trail entry, so the full entry goes to the error log.
	 *
	 * @return void
	 */
	public function testCreatingIntoDeliberationWithAConflictedPanelIsRefused(): void {
		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail(), conflict: 'p.janssen');

		$refusal = $this->createRequest(service: $service, row: ['status' => 'in-deliberation', 'panel' => ['a.bakker', 'p.janssen']]);

		$this->assertNotNull(actual: $refusal, message: 'a request created in deliberation with a conflicted panel must be refused');
		$this->assertStringStartsWith(prefix: 'Panel member conflict (Awb Art. 7:13 lid 3)', string: $refusal->getMessage());
		$this->assertSame(expected: DeliberationStartGuard::ERROR_CODE, actual: $refusal->getErrors()['code']);
		$this->assertSame(expected: [], actual: $this->store->row('bacAdviceRequest', 'req-1'), message: 'nothing is saved');
		$this->assertSame(expected: 0, actual: $this->store->writes);
		$this->assertSame(expected: [], actual: $this->trail->actionsOn('req-1'));
		$entry = $this->loggedEntry(action: 'dossiq.bezwaar.independence-check-failed');
		$this->assertSame(expected: 'p.janssen', actual: $entry['payload']['conflictingMember']);
	}//end testCreatingIntoDeliberationWithAConflictedPanelIsRefused()

	/**
	 * Creating a request in in-deliberation whose panel cannot be verified is refused, and the entry is logged.
	 *
	 * @return void
	 */
	public function testCreatingIntoDeliberationWithAnUnverifiablePanelIsRefused(): void {
		$reason = 'Onafhankelijkheid van het panel kon niet worden vastgesteld (Awb Art. 7:13 lid 3)';
		$this->independenceVerdict = ['ok' => false, 'member' => null, 'reason' => $reason];
		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail());

		$refusal = $this->createRequest(service: $service, row: ['status' => 'in-deliberation', 'panel' => ['a.bakker']]);

		$this->assertNotNull(actual: $refusal, message: 'a request created in deliberation with an unverifiable panel must be refused');
		$this->assertStringStartsWith(prefix: 'Panel independence could not be verified', string: $refusal->getMessage());
		$this->assertSame(expected: [], actual: $this->store->row('bacAdviceRequest', 'req-1'), message: 'nothing is saved');
		$this->assertSame(
			expected: ['conflictingMember' => null, 'reason' => $reason],
			actual: $this->loggedEntry(action: 'dossiq.bezwaar.independence-check-unverifiable')['payload']
		);
	}//end testCreatingIntoDeliberationWithAnUnverifiablePanelIsRefused()

	/**
	 * Creating a request in in-deliberation without a panel, or when the check fails, is refused.
	 *
	 * @return void
	 */
	public function testCreatingIntoDeliberationWithoutAPanelOrAWorkingCheckIsRefused(): void {
		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail());
		$refusal = $this->createRequest(service: $service, row: ['status' => 'in-deliberation']);
		$this->assertSame(expected: 'Panel must be set before deliberation can start', actual: $refusal?->getMessage());

		$independence = $this->createMock(originalClassName: PanelIndependenceChecker::class);
		$independence->method('check')->willThrowException(exception: new \TypeError('checker broke'));
		$broken = new AdvisoryCommitteeService(
			settingsService: $this->bezwaarSettings(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			adviceDelegation: $this->createMock(originalClassName: AdviceDelegationService::class),
			auditTrail: $this->bezwaarAuditTrail(),
			independence: $independence,
		);
		$refusal = $this->createRequest(service: $broken, row: ['status' => 'in-deliberation', 'panel' => ['a.bakker']]);
		$this->assertSame(expected: DeliberationStartGuard::UNCHECKED_MESSAGE, actual: $refusal?->getMessage());
		$this->assertSame(expected: [], actual: $this->store->row('bacAdviceRequest', 'req-1'), message: 'nothing is saved');
	}//end testCreatingIntoDeliberationWithoutAPanelOrAWorkingCheckIsRefused()

	/**
	 * A panel of blank members is no panel.
	 *
	 * @return void
	 */
	public function testABlankOnlyPanelDoesNotStartDeliberating(): void {
		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail());

		foreach ([[''], [' '], ['', "\t"]] as $panel) {
			$refusal = $this->createRequest(service: $service, row: ['status' => 'in-deliberation', 'panel' => $panel]);
			$this->assertSame(expected: 'Panel must be set before deliberation can start', actual: $refusal?->getMessage(), message: json_encode($panel));
		}

		$this->assertSame(expected: [], actual: $this->store->row('bacAdviceRequest', 'req-1'), message: 'nothing is saved');
	}//end testABlankOnlyPanelDoesNotStartDeliberating()

	/**
	 * Creating a request in in-deliberation with an independent panel, or in any other status, is saved unchecked.
	 *
	 * @return void
	 */
	public function testCreatingInAnotherStatusOrWithAnIndependentPanelIsSaved(): void {
		$conflicted = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail(), conflict: 'a.bakker');
		$created = $this->createRequest(service: $conflicted, row: ['status' => 'assigned', 'panel' => ['a.bakker']]);
		$this->assertNull(actual: $created, message: 'a create in assigned is not checked');
		$this->assertSame(expected: 'assigned', actual: $this->store->row('bacAdviceRequest', 'req-1')['status']);
		$this->assertSame(expected: [], actual: $this->trail->actionsOn('req-1'));

		$this->startBezwaarStore();
		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail());
		$this->assertNull(actual: $this->createRequest(service: $service, row: ['status' => 'in-deliberation', 'panel' => ['a.bakker']]));
		$this->assertSame(expected: 'in-deliberation', actual: $this->store->row('bacAdviceRequest', 'req-1')['status']);
	}//end testCreatingInAnotherStatusOrWithAnIndependentPanelIsSaved()

	/**
	 * Creating another schema's object in in-deliberation is not checked.
	 *
	 * @return void
	 */
	public function testCreatingAnotherSchemaIsNotChecked(): void {
		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail(), conflict: 'a.bakker');
		$event = new ObjectCreatingEvent($this->entity(schema: 'hearingSession', row: ['status' => 'in-deliberation', 'panel' => ['a.bakker']]));

		$this->guard(service: $service)->handle(event: $event);

		$this->assertFalse(condition: $event->isPropagationStopped());
	}//end testCreatingAnotherSchemaIsNotChecked()

	/**
	 * A create in deliberation without an objection is unverifiable, with the real panel check.
	 *
	 * @return void
	 */
	public function testCreatingIntoDeliberationWithoutAnObjectionIsRefused(): void {
		$service = $this->advisoryServiceWithTheRealCheck();

		$refusal = $this->createRequest(service: $service, row: ['status' => 'in-deliberation', 'panel' => ['a.bakker'], 'bezwaar' => '']);

		$this->assertNotNull(actual: $refusal, message: 'a request without an objection must not start deliberating');
		$this->assertStringStartsWith(prefix: 'Panel independence could not be verified', string: $refusal->getMessage());
		$this->assertSame(expected: [], actual: $this->store->row('bacAdviceRequest', 'req-1'), message: 'nothing is saved');
	}//end testCreatingIntoDeliberationWithoutAnObjectionIsRefused()

	/**
	 * A panel change during deliberation runs the check again; a refusal keeps the old panel.
	 *
	 * @return void
	 */
	public function testAPanelChangeDuringDeliberationRunsTheCheckAgain(): void {
		$this->seedRequest(row: ['status' => 'in-deliberation', 'panel' => ['a.bakker']]);
		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail(), conflict: 'p.janssen');

		$refusal = $this->writeRequest(service: $service, changes: ['panel' => ['a.bakker', 'p.janssen']]);

		$this->assertNotNull(actual: $refusal, message: 'a conflicted panel must not take over a deliberation');
		$this->assertSame(expected: DeliberationStartGuard::ERROR_CODE, actual: $refusal->getErrors()['code']);
		$this->assertSame(expected: ['a.bakker'], actual: $this->store->row('bacAdviceRequest', 'req-1')['panel']);
		$this->assertSame(expected: ['dossiq.bezwaar.independence-check-failed'], actual: $this->trail->actionsOn('req-1'));
	}//end testAPanelChangeDuringDeliberationRunsTheCheckAgain()

	/**
	 * An objection change during deliberation runs the check on the new objection; an emptied one is unverifiable.
	 *
	 * @return void
	 */
	public function testAnObjectionChangeDuringDeliberationRunsTheCheckAgain(): void {
		$this->seedRequest(row: ['status' => 'in-deliberation', 'panel' => ['a.bakker']]);
		$asked = [];
		$independence = $this->createMock(originalClassName: PanelIndependenceChecker::class);
		$independence->method('check')->willReturnCallback(
			static function (string $objectionId) use (&$asked): array {
				$asked[] = $objectionId;
				return ['ok' => false, 'member' => 'a.bakker', 'reason' => 'betrokken'];
			}
		);
		$service = $this->advisoryService(independence: $independence);

		$refusal = $this->writeRequest(service: $service, changes: ['bezwaar' => 'bezwaar-2']);

		$this->assertSame(expected: ['bezwaar-2'], actual: $asked);
		$this->assertNotNull(actual: $refusal, message: 'a new objection must be checked against the panel');
		$this->assertSame(expected: 'bezwaar-1', actual: $this->store->row('bacAdviceRequest', 'req-1')['bezwaar']);

		$emptied = $this->writeRequest(service: $this->advisoryServiceWithTheRealCheck(), changes: ['bezwaar' => '']);
		$this->assertNotNull(actual: $emptied, message: 'an emptied objection must not keep a deliberation going');
		$this->assertStringStartsWith(prefix: 'Panel independence could not be verified', string: $emptied->getMessage());
	}//end testAnObjectionChangeDuringDeliberationRunsTheCheckAgain()

	/**
	 * A panel change during deliberation whose check fails is refused.
	 *
	 * @return void
	 */
	public function testAPanelChangeWhoseCheckFailsIsRefused(): void {
		$this->seedRequest(row: ['status' => 'in-deliberation', 'panel' => ['a.bakker']]);
		$independence = $this->createMock(originalClassName: PanelIndependenceChecker::class);
		$independence->method('check')->willThrowException(exception: new \TypeError('checker broke'));

		$refusal = $this->writeRequest(service: $this->advisoryService(independence: $independence), changes: ['panel' => ['p.janssen']]);

		$this->assertSame(expected: DeliberationStartGuard::UNCHECKED_MESSAGE, actual: $refusal?->getMessage());
		$this->assertSame(expected: ['a.bakker'], actual: $this->store->row('bacAdviceRequest', 'req-1')['panel']);
	}//end testAPanelChangeWhoseCheckFailsIsRefused()

	/**
	 * The objection backfill and an advice text edit during deliberation are not checked.
	 *
	 * @return void
	 */
	public function testTheBackfillAndOtherEditsDuringDeliberationAreNotChecked(): void {
		$legacy = ['status' => 'in-deliberation', 'panel' => ['a.bakker'], 'objectionProceeding' => 'bezwaar-1', 'committee' => 'committee-1'];
		$this->store->seed('bacAdviceRequest', 'req-1', $legacy);
		$conflicted = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail(), conflict: 'a.bakker');

		$backfill = $this->writeRequest(service: $conflicted, changes: ['bezwaar' => 'bezwaar-1']);
		$this->assertNull(actual: $backfill, message: 'the backfill names the same objection');
		$edit = $this->writeRequest(service: $conflicted, changes: ['adviceText' => 'concept']);
		$this->assertNull(actual: $edit, message: 'an advice text edit is not checked');
		$this->assertSame(expected: [], actual: $this->trail->actionsOn('req-1'));
	}//end testTheBackfillAndOtherEditsDuringDeliberationAreNotChecked()

	/**
	 * A raw write that skips deliberation is refused, and the status stays.
	 *
	 * @return void
	 */
	public function testAWriteThatSkipsDeliberationIsRefused(): void {
		$this->seedRequest(row: ['status' => 'assigned', 'panel' => ['a.bakker']]);
		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail());

		foreach (['advice-issued', 'inadmissible'] as $status) {
			$refusal = $this->writeStatus(service: $service, status: $status);
			$this->assertNotNull(actual: $refusal, message: 'assigned to '.$status.' skips deliberation');
			$this->assertSame(expected: DeliberationStartGuard::SKIP_ERROR_CODE, actual: $refusal->getErrors()['code']);
			$this->assertSame(expected: DeliberationStartGuard::SKIP_MESSAGE, actual: $refusal->getMessage());
			$this->assertSame(expected: 'assigned', actual: $this->store->row('bacAdviceRequest', 'req-1')['status']);
		}
	}//end testAWriteThatSkipsDeliberationIsRefused()

	/**
	 * A create straight into a post-deliberation status is refused; edits after deliberation are not.
	 *
	 * @return void
	 */
	public function testACreateThatSkipsDeliberationIsRefused(): void {
		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail());

		foreach (['advice-issued', 'inadmissible'] as $status) {
			$refusal = $this->createRequest(service: $service, row: ['status' => $status, 'panel' => ['a.bakker']]);
			$this->assertSame(expected: DeliberationStartGuard::SKIP_ERROR_CODE, actual: $refusal?->getErrors()['code'], message: $status);
			$this->assertSame(expected: [], actual: $this->store->row('bacAdviceRequest', 'req-1'), message: 'nothing is saved');
		}

		$this->seedRequest(row: ['status' => 'advice-issued', 'panel' => ['a.bakker']]);
		$edit = $this->writeRequest(service: $service, changes: ['adviceText' => 'gecorrigeerd']);
		$this->assertNull(actual: $edit, message: 'an edit after the advice is not a skip');
		$move = $this->writeStatus(service: $service, status: 'inadmissible');
		$this->assertSame(
			expected: DeliberationStartGuard::OUTCOME_ERROR_CODE,
			actual: $move?->getErrors()['code'],
			message: 'a raw move between outcomes'
		);
	}//end testACreateThatSkipsDeliberationIsRefused()

	/**
	 * The committee's own move out of deliberation still saves, with the guard on the store's writes.
	 *
	 * @return void
	 */
	public function testTransitionAdviceStatusStillIssuesTheAdvice(): void {
		$this->assertTheTransitionIssuesTheAdvice(requestId: 'req-1');
	}//end testTransitionAdviceStatusStillIssuesTheAdvice()

	/**
	 * The transition called with a slug the store resolves is still marked: the marker is keyed on the stored id.
	 *
	 * @return void
	 */
	public function testTheTransitionByAnotherIdFormIsStillMarked(): void {
		$this->assertTheTransitionIssuesTheAdvice(requestId: 'bacadvicerequest-req-1');
	}//end testTheTransitionByAnotherIdFormIsStillMarked()

	/**
	 * Issue req-1's advice through transitionAdviceStatus(), with every save passing the guard first.
	 *
	 * The store resolves `bacadvicerequest-req-1` to req-1, as OpenRegister's find() resolves a slug.
	 *
	 * @param string $requestId The id form the caller uses.
	 *
	 * @return void
	 */
	private function assertTheTransitionIssuesTheAdvice(string $requestId): void {
		$guarded = new class() extends RefusableRegister {
			/**
			 * Runs before each save with the row as stored and as it will be saved.
			 *
			 * @var \Closure|null
			 */
			public ?\Closure $beforeSave = null;

			/**
			 * Find one row by its uuid or by its slug.
			 *
			 * @param int|string $id            The uuid or the slug.
			 * @param mixed      $_extend       Ignored.
			 * @param bool       $files         Ignored.
			 * @param int|string $register      Ignored.
			 * @param int|string $schema        The schema.
			 * @param bool       $_rbac         Passed on.
			 * @param bool       $_multitenancy Passed on.
			 *
			 * @return array<string, mixed>|null The row.
			 */
			public function find(
				int|string $id,
				mixed $_extend = null,
				bool $files = false,
				int|string $register = '',
				int|string $schema = '',
				bool $_rbac = true,
				bool $_multitenancy = true,
			): ?array {
				$uuid = ['bacadvicerequest-req-1' => 'req-1'][(string) $id] ?? $id;

				return parent::find(
					id: $uuid,
					_extend: $_extend,
					files: $files,
					register: $register,
					schema: $schema,
					_rbac: $_rbac,
					_multitenancy: $_multitenancy
				);
			}

			/**
			 * Save one row after the pre-save hook.
			 *
			 * @param array<string, mixed> $object        The row.
			 * @param int|string           $register      The register.
			 * @param int|string           $schema        The schema.
			 * @param string|null          $uuid          The uuid.
			 * @param bool                 $_rbac         Unused.
			 * @param bool                 $_multitenancy Unused.
			 *
			 * @return array<string, mixed> The saved row.
			 */
			public function saveObject(
				array $object,
				int|string $register = '',
				int|string $schema = '',
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				if ($this->beforeSave !== null) {
					($this->beforeSave)($this->row(schema: (string) $schema, uuid: (string) $uuid), $object);
				}

				return parent::saveObject(object: $object, register: $register, schema: $schema, uuid: $uuid, _rbac: $_rbac, _multitenancy: $_multitenancy);
			}
		};
		$this->store = $guarded;
		$this->seedRequest(row: ['status' => 'in-deliberation', 'panel' => ['a.bakker']]);
		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail());
		$saves = 0;
		$guarded->beforeSave = function (array $before, array $after) use ($service, &$saves): void {
			$saves++;
			$event = new ObjectUpdatingEvent($this->entity(schema: 'bacAdviceRequest', row: $after), $this->entity(schema: 'bacAdviceRequest', row: $before));
			$this->guard(service: $service)->handle(event: $event);
			$this->assertFalse(condition: $event->isPropagationStopped(), message: (string) ($event->getErrors()['message'] ?? ''));
		};

		$advice = ['conclusion' => 'gegrond', 'recommendation' => 'herroepen'];
		$service->transitionAdviceStatus(requestId: $requestId, newStatus: 'advice-issued', payload: $advice);

		$this->assertSame(expected: 1, actual: $saves);
		$this->assertSame(expected: 'advice-issued', actual: $this->store->row('bacAdviceRequest', 'req-1')['status']);
		$this->assertSame(expected: [], actual: $this->store->row('bacAdviceRequest', 'bacadvicerequest-req-1'), message: 'no second row under the slug');
	}//end assertTheTransitionIssuesTheAdvice()

	/**
	 * A raw write cannot issue the advice: refused, and the request stays in deliberation.
	 *
	 * @return void
	 */
	public function testARawWriteCannotIssueTheAdvice(): void {
		$this->seedRequest(row: ['status' => 'in-deliberation', 'panel' => ['a.bakker'], 'conclusion' => 'gegrond', 'recommendation' => 'herroepen']);

		$refusal = $this->writeStatus(service: $this->realAdvisoryService(trail: $this->bezwaarAuditTrail()), status: 'advice-issued');

		$this->assertNotNull(actual: $refusal, message: 'only the advice transition may issue the advice');
		$this->assertSame(expected: DeliberationStartGuard::OUTCOME_ERROR_CODE, actual: $refusal->getErrors()['code']);
		$this->assertSame(expected: DeliberationStartGuard::OUTCOME_MESSAGE, actual: $refusal->getMessage());
		$this->assertSame(expected: 'in-deliberation', actual: $this->store->row('bacAdviceRequest', 'req-1')['status']);
	}//end testARawWriteCannotIssueTheAdvice()

	/**
	 * A raw write cannot declare the request inadmissible.
	 *
	 * @return void
	 */
	public function testARawWriteCannotDeclareTheRequestInadmissible(): void {
		$this->seedRequest(row: ['status' => 'in-deliberation', 'panel' => ['a.bakker']]);

		$refusal = $this->writeStatus(service: $this->realAdvisoryService(trail: $this->bezwaarAuditTrail()), status: 'inadmissible');

		$this->assertSame(expected: DeliberationStartGuard::OUTCOME_ERROR_CODE, actual: $refusal?->getErrors()['code']);
		$this->assertSame(expected: 'in-deliberation', actual: $this->store->row('bacAdviceRequest', 'req-1')['status']);
	}//end testARawWriteCannotDeclareTheRequestInadmissible()

	/**
	 * The marker of another advice request does not let a raw write issue this one's advice.
	 *
	 * @return void
	 */
	public function testAnotherRequestsMarkerDoesNotCount(): void {
		$this->seedRequest(row: ['status' => 'in-deliberation', 'panel' => ['a.bakker']]);
		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail());

		$refusal = $this->internalWrites->runInternal(
			objectId: 'req-2',
			write: fn (): ?HookStoppedException => $this->writeStatus(service: $service, status: 'advice-issued')
		);

		$this->assertSame(expected: DeliberationStartGuard::OUTCOME_ERROR_CODE, actual: $refusal?->getErrors()['code']);
	}//end testAnotherRequestsMarkerDoesNotCount()

	/**
	 * A panel member that is a list or an object is refused with a message that says so.
	 *
	 * @return void
	 */
	public function testAPanelMemberThatIsNotAUserIdIsNamed(): void {
		$service = $this->realAdvisoryService(trail: $this->bezwaarAuditTrail());

		$refusal = $this->createRequest(service: $service, row: ['status' => 'in-deliberation', 'panel' => [['a.bakker'], 'p.janssen']]);

		$this->assertSame(expected: 'Panel members must be user ids, not lists or objects', actual: $refusal?->getMessage());
		$this->assertSame(expected: [], actual: $this->store->row('bacAdviceRequest', 'req-1'), message: 'nothing is saved');
	}//end testAPanelMemberThatIsNotAUserIdIsNamed()

	/**
	 * An edit after the advice keeps working: a save that stays in advice-issued is not a move into it.
	 *
	 * @return void
	 */
	public function testAnEditAfterTheAdviceIsSaved(): void {
		$this->seedRequest(row: ['status' => 'advice-issued', 'panel' => ['a.bakker']]);

		$edit = $this->writeRequest(service: $this->realAdvisoryService(trail: $this->bezwaarAuditTrail()), changes: ['adviceText' => 'gecorrigeerd']);

		$this->assertNull(actual: $edit, message: 'an edit inside advice-issued is unchanged');
		$this->assertFalse(condition: $this->internalWrites->isInternal(objectId: 'req-1'), message: 'the test wrote without the marker');
	}//end testAnEditAfterTheAdviceIsSaved()

	/**
	 * The bezwaar registrar shares one write marker between the service and the guard.
	 *
	 * @return void
	 */
	public function testTheRegistrarSharesOneWriteMarker(): void {
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$services = [];
		$context->method('registerService')->willReturnCallback(
			callback: static function (string $name, \Closure $factory, bool $shared = true) use (&$services): void {
				$services[$name] = [$factory, $shared];
			}
		);

		(new BezwaarListenerRegistrar())->register(context: $context);

		$this->assertArrayHasKey(key: InternalWriteMarker::class, array: $services);
		[$factory, $shared] = $services[InternalWriteMarker::class];
		$this->assertTrue(condition: $shared, message: 'the service and the guard must see the same marker');
		$this->assertInstanceOf(expected: InternalWriteMarker::class, actual: $factory());
	}//end testTheRegistrarSharesOneWriteMarker()

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
	 * As in MagicMapper::updateObjectEntity(): a stopped ObjectUpdatingEvent
	 * raises HookStoppedException and nothing is saved.
	 *
	 * @param AdvisoryCommitteeService $service The service the guard asks.
	 * @param string                   $status  The status to write.
	 *
	 * @return HookStoppedException|null The refusal, or null when the write was saved.
	 */
	private function writeStatus(AdvisoryCommitteeService $service, string $status): ?HookStoppedException {
		return $this->writeRequest(service: $service, changes: ['status' => $status]);
	}//end writeStatus()

	/**
	 * Write changes onto req-1 the way OpenRegister saves an update.
	 *
	 * @param AdvisoryCommitteeService $service The service the guard asks.
	 * @param array<string, mixed>     $changes The fields to write.
	 *
	 * @return HookStoppedException|null The refusal, or null when the write was saved.
	 */
	private function writeRequest(AdvisoryCommitteeService $service, array $changes): ?HookStoppedException {
		$before = $this->store->row('bacAdviceRequest', 'req-1');
		$after = array_merge($before, $changes);
		$event = new ObjectUpdatingEvent(
			$this->entity(schema: 'bacAdviceRequest', row: $after),
			$this->entity(schema: 'bacAdviceRequest', row: $before)
		);

		return $this->saveUnlessRefused(service: $service, event: $event, row: $after);
	}//end writeRequest()

	/**
	 * The service over a given panel check.
	 *
	 * @param PanelIndependenceChecker $independence The panel check.
	 *
	 * @return AdvisoryCommitteeService The service.
	 */
	private function advisoryService(PanelIndependenceChecker $independence): AdvisoryCommitteeService {
		return new AdvisoryCommitteeService(
			settingsService: $this->bezwaarSettings(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			adviceDelegation: $this->createMock(originalClassName: AdviceDelegationService::class),
			auditTrail: $this->bezwaarAuditTrail(),
			independence: $independence,
			internalWrites: $this->internalWrites,
		);
	}//end advisoryService()

	/**
	 * The service over the real panel check.
	 *
	 * @return AdvisoryCommitteeService The service.
	 */
	private function advisoryServiceWithTheRealCheck(): AdvisoryCommitteeService {
		return $this->advisoryService(
			independence: new PanelIndependenceChecker(
				settingsService: $this->bezwaarSettings(),
				logger: $this->createMock(originalClassName: LoggerInterface::class)
			)
		);
	}//end advisoryServiceWithTheRealCheck()

	/**
	 * Create req-1 on objection bezwaar-1 the way OpenRegister inserts an object.
	 *
	 * As in MagicMapper::insertObjectEntity(): a stopped ObjectCreatingEvent
	 * raises HookStoppedException and nothing is saved.
	 *
	 * @param AdvisoryCommitteeService $service The service the guard asks.
	 * @param array<string, mixed>     $row     The request's fields.
	 *
	 * @return HookStoppedException|null The refusal, or null when the request was saved.
	 */
	private function createRequest(AdvisoryCommitteeService $service, array $row): ?HookStoppedException {
		$row = array_merge(['bezwaar' => 'bezwaar-1', 'committee' => 'committee-1'], $row);
		$event = new ObjectCreatingEvent($this->entity(schema: 'bacAdviceRequest', row: $row));

		return $this->saveUnlessRefused(service: $service, event: $event, row: $row);
	}//end createRequest()

	/**
	 * Hand a pre-save event to every guard the app binds to it, then save req-1 unless one refused.
	 *
	 * @param AdvisoryCommitteeService $service The service the guard asks.
	 * @param Event                    $event   An ObjectCreatingEvent or ObjectUpdatingEvent.
	 * @param array<string, mixed>     $row     The row to save.
	 *
	 * @return HookStoppedException|null The refusal, or null when the row was saved.
	 */
	private function saveUnlessRefused(AdvisoryCommitteeService $service, Event $event, array $row): ?HookStoppedException {
		foreach ($this->boundListeners()[$event::class] ?? [] as $listener) {
			if ($listener === DeliberationStartGuard::class) {
				$this->guard(service: $service)->handle(event: $event);
			}
		}

		if ($event->isPropagationStopped() === true) {
			return new HookStoppedException(
				message: (string) ($event->getErrors()['message'] ?? 'Object save rejected by hook'),
				errors: $event->getErrors()
			);
		}

		$this->store->saveObject(object: $row, register: 'dossiq', schema: 'bacAdviceRequest', uuid: 'req-1');
		return null;
	}//end saveUnlessRefused()

	/**
	 * The one trail entry for req-1 that went to the error log because it could not be written.
	 *
	 * @param string $action The entry's action.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function loggedEntry(string $action): array {
		$matches = array_values(array_filter(
			array_column($this->auditErrors, 1),
			static fn (array $context): bool => ($context['object'] ?? null) === 'req-1' && ($context['action'] ?? null) === $action
		));
		$this->assertCount(expectedCount: 1, haystack: $matches, message: 'expected exactly one logged '.$action.' entry on req-1');

		return $matches[0]['entry'];
	}//end loggedEntry()

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
			internalWrites: $this->internalWrites,
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
