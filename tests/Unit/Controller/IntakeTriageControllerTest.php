<?php

/**
 * The intake surface: the declaration a form reads, and the three acts.
 *
 * The declaration endpoint is the one the create form and the assignee picker
 * both draw themselves from, so what matters is that it answers the SAME
 * declaration the write is enforced against, key for key. A surface that
 * answered a tidied-up shape would give the picker one narrowing and the write
 * another, and the picker would offer a team the save turns down.
 *
 * The three acts are driven for their guards as much as for their work. The
 * declaration read needs a session and nothing more, because a case type is
 * configuration every handler already reads. Refusing a case is a mutation of
 * that case and goes through {@see \OCA\Dossiq\Service\CaseAccessGuard}.
 * Sleeping an item touches the triage queue, which holds the original of
 * everything the mailbox received, and is gated on the intake role.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\IntakeTriageController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Email\IntakePolicy;
use OCA\Dossiq\Service\Intake\AssigneeNarrowing;
use OCA\Dossiq\Service\Intake\CaseClassification;
use OCA\Dossiq\Service\Intake\ClassificationSchemes;
use OCA\Dossiq\Service\Intake\IntakeFanOut;
use OCA\Dossiq\Service\Intake\IntakeRequirements;
use OCA\Dossiq\Service\Intake\TriageSleep;
use OCA\Dossiq\Service\Routing\RefusalOutcome;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Covers the declaration read, the refusal, the queue, the sleep and the fan-out.
 *
 * @covers \OCA\Dossiq\Controller\IntakeTriageController
 *
 * @uses \OCA\Dossiq\Service\Intake\AssigneeNarrowing
 * @uses \OCA\Dossiq\Service\Intake\CaseClassification
 * @uses \OCA\Dossiq\Service\Intake\ClassificationSchemes
 * @uses \OCA\Dossiq\Service\Intake\IntakeRequirements
 */
final class IntakeTriageControllerTest extends TestCase {

	/**
	 * What the case type declares.
	 *
	 * @var array<string, mixed>
	 */
	private array $caseType = [];

	/**
	 * Whether the caller holds the intake role.
	 *
	 * @var boolean
	 */
	private bool $hasIntakeRole = true;

	/**
	 * Whether the caller may mutate the case.
	 *
	 * @var boolean
	 */
	private bool $mayMutate = true;

	/**
	 * The refusal outcome.
	 *
	 * @var RefusalOutcome&MockObject
	 */
	private RefusalOutcome $refusal;

	/**
	 * The triage sleep.
	 *
	 * @var TriageSleep&MockObject
	 */
	private TriageSleep $sleep;

	/**
	 * The fan-out.
	 *
	 * @var IntakeFanOut&MockObject
	 */
	private IntakeFanOut $fanOut;

	/**
	 * The intake log.
	 *
	 * @var IntakeLog&MockObject
	 */
	private IntakeLog $log;

	/**
	 * Build collaborators for a caller who holds the intake role.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->hasIntakeRole = true;
		$this->mayMutate = true;
		$this->caseType = [
			'title' => 'Handhavingsverzoek',
			'intakeRequirements' => [
				'requiredBeforeCreation' => ['communicationChannel', 'confidentiality'],
				'requiredBeforeComplete' => ['requesterAddress'],
			],
			'assigneeNarrowing' => ['allowedGroups' => ['handhaving', 'juridische-zaken']],
			'refusalDestination' => ['department' => 'Juridische Zaken', 'role' => 'intake'],
		];

		$this->refusal = $this->createMock(RefusalOutcome::class);
		$this->sleep = $this->createMock(TriageSleep::class);
		$this->fanOut = $this->createMock(IntakeFanOut::class);
		$this->log = $this->createMock(IntakeLog::class);
	}//end setUp()

	/**
	 * The controller, wired over the declarations and the mocked acts.
	 *
	 * @param boolean $signedIn Whether there is a session.
	 *
	 * @return IntakeTriageController The controller under test.
	 */
	private function controller(bool $signedIn = true): IntakeTriageController {
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturnCallback(fn (): array => $this->caseType);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$policy = $this->createMock(IntakePolicy::class);
		$policy->method('mayRunIntake')->willReturnCallback(fn (): bool => $this->hasIntakeRole);

		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('assertCaseMutationAccess')->willReturnCallback(
			function (): void {
				if ($this->mayMutate === false) {
					throw new OCSForbiddenException('nope');
				}
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jdevries');
		$session->method('getUser')->willReturn(($signedIn === true) ? $user : null);

		// The declarations are the REAL readers, never doubles. The whole claim
		// of the requirements endpoint is that the picker is told what the write
		// enforces, and a double would let the two drift apart while this test
		// stayed green.
		return new IntakeTriageController(
			request: $this->createMock(IRequest::class),
			caseTypeResolver: $resolver,
			requirements: new IntakeRequirements(),
			classification: new CaseClassification(
				schemes: new ClassificationSchemes(appConfig: $appConfig)
			),
			schemes: new ClassificationSchemes(appConfig: $appConfig),
			narrowing: new AssigneeNarrowing(),
			refusal: $this->refusal,
			sleep: $this->sleep,
			fanOut: $this->fanOut,
			log: $this->log,
			policy: $policy,
			caseAccessGuard: $guard,
			userSession: $session,
			logger: new NullLogger(),
		);
	}//end controller()

	/**
	 * The declaration endpoint answers what the write enforces.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-case-type-declares-what-must-be-answered-before-a-case-exists-req-triage-01
	 */
	public function testTheRequirementsEndpointAnswersTheDeclaration(): void {
		$response = $this->controller()->requirements(caseTypeId: 'ct-1');
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['communicationChannel', 'confidentiality'],
			$body['intakeRequirements']['requiredBeforeCreation']
		);
		$this->assertSame(
			['requesterAddress'],
			$body['intakeRequirements']['requiredBeforeComplete']
		);
	}//end testTheRequirementsEndpointAnswersTheDeclaration()

	/**
	 * The declaration carries the narrowing a picker draws itself from.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-case-type-narrows-who-may-be-assigned-at-creation-req-triage-03
	 */
	public function testTheRequirementsEndpointCarriesTheNarrowing(): void {
		$body = $this->controller()->requirements(caseTypeId: 'ct-1')->getData();

		$this->assertSame(
			['handhaving', 'juridische-zaken'],
			$body['assigneeNarrowing']['allowedGroups']
		);
		$this->assertFalse($body['narrowsNothing']);
	}//end testTheRequirementsEndpointCarriesTheNarrowing()

	/**
	 * The declaration says whether the classification scheme resolves.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-classification-that-is-an-access-rule-is-required-before-the-case-exists-req-triage-02
	 */
	public function testTheRequirementsEndpointReportsAnUnresolvableScheme(): void {
		$this->caseType['caseClassification'] = [
			'scheme' => 'tmlo-2019',
			'classificationIsAccessRule' => true,
		];

		$body = $this->controller()->requirements(caseTypeId: 'ct-1')->getData();

		$this->assertFalse($body['schemeResolves']);
		$this->assertSame([], $body['classificationValues']);
	}//end testTheRequirementsEndpointReportsAnUnresolvableScheme()

	/**
	 * A case type nothing can resolve answers 404, not an empty declaration.
	 *
	 * An empty declaration would tell the form that nothing is asked, which is
	 * the same answer a genuinely undeclared case type gives.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function testAnUnknownCaseTypeAnswersNotFound(): void {
		$this->caseType = [];

		$response = $this->controller()->requirements(caseTypeId: 'ct-gone');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnUnknownCaseTypeAnswersNotFound()

	/**
	 * The declaration read needs a session.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function testTheDeclarationReadNeedsASession(): void {
		$response = $this->controller(signedIn: false)->requirements(caseTypeId: 'ct-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testTheDeclarationReadNeedsASession()

	/**
	 * Refusing a case answers the record the outcome produced.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-refused-intake-goes-to-a-named-department-and-role-req-triage-04
	 */
	public function testRefusingACaseAnswersTheRecord(): void {
		$this->refusal->method('refuse')->willReturn(
			[
				'refused' => true,
				'department' => 'Juridische Zaken',
				'role' => 'intake',
				'reason' => 'Niet voor ons.',
				'refusedBy' => 'jdevries',
			]
		);

		$response = $this->controller()->refuse(caseId: 'case-1', reason: 'Niet voor ons.');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Juridische Zaken', $response->getData()['department']);
	}//end testRefusingACaseAnswersTheRecord()

	/**
	 * A refusal the outcome refused comes back as the rule, not as a 500.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-refused-intake-goes-to-a-named-department-and-role-req-triage-04
	 */
	public function testARefusedRefusalCarriesItsRuleAndSentence(): void {
		$this->refusal->method('refuse')->willThrowException(
			new RefusedException(
				rule: RefusalOutcome::RULE_NO_DESTINATION,
				sentence: 'This case type does not say where a refused case goes.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			)
		);

		$response = $this->controller()->refuse(caseId: 'case-1', reason: 'Niet voor ons.');

		$this->assertSame(RefusedException::STATUS_UNPROCESSABLE, $response->getStatus());
		$this->assertSame(RefusalOutcome::RULE_NO_DESTINATION, $response->getData()['error']);
		$this->assertStringContainsString(
			'where a refused case goes',
			$response->getData()['message']
		);
	}//end testARefusedRefusalCarriesItsRuleAndSentence()

	/**
	 * A caller who may not mutate the case may not refuse it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-refused-intake-goes-to-a-named-department-and-role-req-triage-04
	 */
	public function testACallerWithoutCaseAccessCannotRefuse(): void {
		$this->mayMutate = false;
		$this->refusal->expects($this->never())->method('refuse');

		$response = $this->controller()->refuse(caseId: 'case-1', reason: 'Niet voor ons.');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testACallerWithoutCaseAccessCannotRefuse()

	/**
	 * The triage queue answers the items a handler should see.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-triage-item-sleeps-until-a-date-and-comes-back-req-triage-05
	 */
	public function testTheQueueLeavesOutTheSleepingItems(): void {
		// One item per sleepable outcome, so the count is the queue's and not an
		// artefact of the same rows being answered twice.
		$this->log->method('search')->willReturnCallback(
			static function (array $filters = []): array {
				return [['id' => 'e-' . ($filters['outcome'] ?? '?')]];
			}
		);
		$this->sleep->method('awake')->willReturn([['id' => 'e-quarantined']]);

		$body = $this->controller()->queue()->getData();

		$this->assertSame([['id' => 'e-quarantined']], $body['results']);
		$this->assertSame(1, $body['sleeping']);
	}//end testTheQueueLeavesOutTheSleepingItems()

	/**
	 * Sleeping an item answers the record the sleep produced.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-triage-item-sleeps-until-a-date-and-comes-back-req-triage-05
	 */
	public function testSleepingAnItemAnswersTheRecord(): void {
		$this->sleep->method('sleep')->willReturn(
			[
				'sleepUntil' => '2027-03-01',
				'sleepReason' => 'Wachten op het bestemmingsplan.',
				'sleptBy' => 'jdevries',
				'sleptAt' => '2026-09-15T06:00:00+00:00',
			]
		);

		$response = $this->controller()->sleepItem(
			entryId: 'e-1',
			until: '2027-03-01',
			reason: 'Wachten op het bestemmingsplan.'
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('2027-03-01', $response->getData()['sleepUntil']);
	}//end testSleepingAnItemAnswersTheRecord()

	/**
	 * A sleep the service refused comes back as the rule it named.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-triage-item-sleeps-until-a-date-and-comes-back-req-triage-05
	 */
	public function testASleepOnAnAcceptedCaseCarriesItsRule(): void {
		$this->sleep->method('sleep')->willThrowException(
			new RefusedException(
				rule: TriageSleep::RULE_ALREADY_A_CASE,
				sentence: 'Suspend the term on the case instead.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			)
		);

		$response = $this->controller()->sleepItem(
			entryId: 'e-1',
			until: '2027-03-01',
			reason: 'Wachten.'
		);

		$this->assertSame(TriageSleep::RULE_ALREADY_A_CASE, $response->getData()['error']);
	}//end testASleepOnAnAcceptedCaseCarriesItsRule()

	/**
	 * The triage acts are gated on the intake role, not on being logged in.
	 *
	 * The log holds the original of every message the mailbox received, which
	 * is personal data about people who never agreed to the whole instance
	 * reading it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function testTheTriageActsAreGatedOnTheIntakeRole(): void {
		$this->hasIntakeRole = false;
		$this->sleep->expects($this->never())->method('sleep');
		$this->fanOut->expects($this->never())->method('submit');

		$controller = $this->controller();

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->queue()->getStatus());
		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$controller->sleepItem(entryId: 'e-1', until: '2027-03-01', reason: 'x')->getStatus()
		);
		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$controller->fanOut(caseTypeId: 'ct-1')->getStatus()
		);
	}//end testTheTriageActsAreGatedOnTheIntakeRole()

	/**
	 * The fan-out answers what was created and what was not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-one-submission-opens-several-cases-tracked-together-req-triage-06
	 */
	public function testTheFanOutReportsTheFailedDestinationBesideTheCreated(): void {
		$this->fanOut->method('submit')->willReturn(
			[
				'created' => [['id' => 'case-1', 'department' => 'handhaving']],
				'failed' => [['destination' => 'Onderhoud', 'reason' => 'The case type has been retired.']],
				'relationHasNoInverse' => true,
			]
		);

		$body = $this->controller()->fanOut(
			caseTypeId: 'ct-melding',
			submission: ['title' => 'Kapotte lantaarnpaal'],
			submissionId: 'sub-1'
		)->getData();

		$this->assertCount(1, $body['created']);
		$this->assertSame('Onderhoud', $body['failed'][0]['destination']);
		$this->assertTrue($body['relationHasNoInverse']);
	}//end testTheFanOutReportsTheFailedDestinationBesideTheCreated()
}//end class
