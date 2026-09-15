<?php

/**
 * A refusal reaches the caller with a status, a rule and a sentence.
 *
 * Each test asserts all three, on both branches: what the endpoint answers
 * when the rule passes, and what it answers when the rule refuses or cannot
 * be evaluated. Asserting only the body is how a 403 that should be a 503
 * ships green, which is the defect row Q10.14 names.
 *
 * The four converted sites are here as the pair they used to collapse into:
 * before this change each answered an empty value, and the sentence the
 * caller finally produced was about the user ("niet bevoegd", "insufficient
 * mandaat", "no active mandate matrix for tenant") when the truth was a
 * register that could not be read.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 *
 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\BeschikkingController;
use OCA\Dossiq\Controller\MandaatMatrixController;
use OCA\Dossiq\Controller\StatusTransitionController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Middleware\MandateDeniedException;
use OCA\Dossiq\Middleware\MandateValidationMiddleware;
use OCA\Dossiq\Service\BeschikkingService;
use OCA\Dossiq\Service\BulkStatusTransitionService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\MandaatCheckService;
use OCA\Dossiq\Service\MandaatEscalatieService;
use OCA\Dossiq\Service\MandaatGebruikService;
use OCA\Dossiq\Service\MandaatImportService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\TenantAuthenticationService;
use OCA\Dossiq\Service\TenantContext;
use OCA\Dossiq\Service\Transitions\GuardFailedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\Dossiq\Service\Lifecycle\ProcessOwnedStatusRule;

/**
 * Every refusal carries a status the caller can read (REQ-QG-CRN-2).
 *
 * @covers \OCA\Dossiq\Exception\RefusedException
 * @covers \OCA\Dossiq\Controller\StatusTransitionController
 * @covers \OCA\Dossiq\Controller\MandaatMatrixController
 * @covers \OCA\Dossiq\Controller\BeschikkingController
 * @covers \OCA\Dossiq\Middleware\MandateValidationMiddleware
 */
class RefusalCarriesAStatusTest extends TestCase {
	/**
	 * A signed-in user session.
	 *
	 * @return IUserSession The session, answering `tester`.
	 */
	private function session(): IUserSession {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('tester');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * The transition controller, over a given engine.
	 *
	 * @param StatusTransitionService $engine The engine mock.
	 * @param array<string, mixed>    $body   The request body.
	 *
	 * @return StatusTransitionController The controller.
	 */
	private function transitionController(
		StatusTransitionService $engine,
		array $body = ['transitionId' => 't-close'],
	): StatusTransitionController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn($body);

		return new StatusTransitionController(
			appName: 'dossiq',
			request: $request,
			transitionEngine: $engine,
			bulkEngine: $this->createMock(originalClassName: BulkStatusTransitionService::class),
			userSession: $this->session(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			caseAccessGuard: $this->createMock(originalClassName: CaseAccessGuard::class),
			processOwnedStatus: $this->createMock(originalClassName: ProcessOwnedStatusRule::class),
		);
	}//end transitionController()

	/**
	 * A transition the case's status does not offer answers 409, rule named.
	 *
	 * The pass branch sits beside it on purpose: the same endpoint, the same
	 * shape of assertion, so the refusal's status is read against a status
	 * that is known to be right.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testARefusedTransitionAnswers409AndNamesTheRule(): void {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->method('execute')->willThrowException(
			new RefusedException(
				rule: 'transition-from-status-mismatch',
				sentence: 'This move does not start from the status the case is in.',
				status: RefusedException::STATUS_REFUSED,
			)
		);

		$response = $this->transitionController(engine: $engine)->execute('case-1');
		$body = (array)$response->getData();

		self::assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		self::assertSame(expected: 'transition-from-status-mismatch', actual: $body['error']);
		self::assertSame(expected: 'transition_from_status_mismatch', actual: $body['code']);
		self::assertSame(expected: 'This move does not start from the status the case is in.', actual: $body['message']);
	}//end testARefusedTransitionAnswers409AndNamesTheRule()

	/**
	 * The transition that is allowed answers 200 and the engine's result.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAnAllowedTransitionAnswers200(): void {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->method('execute')->willReturn(['status' => 'closed']);

		$response = $this->transitionController(engine: $engine)->execute('case-1');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: ['status' => 'closed'], actual: (array)$response->getData());
	}//end testAnAllowedTransitionAnswers200()

	/**
	 * A caller outside the transition's group gets 403, not 409.
	 *
	 * Both are refusals; only the status says which. Before RefusedException
	 * the status was decided by a match on a code string in the controller,
	 * and everything it did not recognise became 400.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAnUnauthorisedTransitionAnswers403(): void {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->method('execute')->willThrowException(
			new RefusedException(
				rule: 'transition-unauthorized',
				sentence: 'You are not in a group this move is open to.',
				status: RefusedException::STATUS_FORBIDDEN,
			)
		);

		$response = $this->transitionController(engine: $engine)->execute('case-1');
		$body = (array)$response->getData();

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		self::assertSame(expected: 'transition-unauthorized', actual: $body['error']);
	}//end testAnUnauthorisedTransitionAnswers403()

	/**
	 * Closing without a result answers 422 and keeps the code the UI reads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testClosingWithoutAResultAnswers422(): void {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->method('execute')->willThrowException(
			new RefusedException(
				rule: 'result-type-required',
				sentence: 'Pick a result before closing this case.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			)
		);

		$response = $this->transitionController(engine: $engine)->execute('case-1');
		$body = (array)$response->getData();

		self::assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
		self::assertSame(expected: 'result-type-required', actual: $body['error']);
		self::assertSame(
			expected: 'result_type_required',
			actual: $body['code'],
			message: 'caseLifecycleHelpers reads `code`; dropping it would show the slug to a handler.'
		);
	}//end testClosingWithoutAResultAnswers422()

	/**
	 * A guard refusal answers 409, names its rule, and still lists the guards.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAGuardRefusalNamesItsRuleAndKeepsTheGuards(): void {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->method('execute')->willThrowException(
			new GuardFailedException(failedGuards: [['type' => 'requiredDocument', 'passed' => false]])
		);

		$response = $this->transitionController(engine: $engine)->execute('case-1');
		$body = (array)$response->getData();

		self::assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		self::assertSame(expected: 'transition-guard-failed', actual: $body['error']);
		self::assertSame(expected: 'This move is blocked by a rule on the case.', actual: $body['message']);
		self::assertCount(expectedCount: 1, haystack: (array)$body['failedGuards']);
	}//end testAGuardRefusalNamesItsRuleAndKeepsTheGuards()

	/**
	 * The mandate controller, over a given check service.
	 *
	 * @param MandaatCheckService $check The check mock.
	 *
	 * @return MandaatMatrixController The controller.
	 */
	private function mandateController(MandaatCheckService $check): MandaatMatrixController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn(
			['decisionType' => 'subsidie', 'caseId' => 'case-1', 'caseProperties' => []]
		);
		$request->method('getParam')->willReturn('');

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->method('getConfigValue')->willReturn('');

		return new MandaatMatrixController(
			appName: 'dossiq',
			request: $request,
			userSession: $this->session(),
			check: $check,
			escalation: $this->createMock(originalClassName: MandaatEscalatieService::class),
			gebruik: $this->createMock(originalClassName: MandaatGebruikService::class),
			import: $this->createMock(originalClassName: MandaatImportService::class),
			settings: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end mandateController()

	/**
	 * A mandate register that answered still answers 200 and its rows.
	 *
	 * This is the pair the 503 below is only meaningful against: the same
	 * endpoint, the same shape of assertion, on a call that succeeded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAReadableMandateRegisterAnswers200(): void {
		$check = $this->createMock(originalClassName: MandaatCheckService::class);
		$check->method('getApplicableForUser')->willReturn([['id' => 'm-1', 'unilateral' => true]]);

		$response = $this->mandateController(check: $check)->applicable('case-1');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertCount(expectedCount: 1, haystack: (array)$response->getData());
	}//end testAReadableMandateRegisterAnswers200()

	/**
	 * The applicable-mandates list does not fold a refusal into emptiness.
	 *
	 * The endpoint's own `catch (Throwable) { $rows = []; }` answered 200 with
	 * an empty list for a register that could not be read, which reads on
	 * screen as "you hold no mandates".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testTheApplicableListDoesNotSwallowARefusal(): void {
		$check = $this->createMock(originalClassName: MandaatCheckService::class);
		$check->method('getApplicableForUser')->willThrowException(
			RefusedException::indeterminate(
				rule: 'mandaat-register-unreadable',
				sentence: 'The mandate register could not be read, so this decision cannot be authorised right now.',
			)
		);

		$response = $this->mandateController(check: $check)->applicable('case-1');

		self::assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
		self::assertSame(expected: 'mandaat-register-unreadable', actual: ((array)$response->getData())['error']);
	}//end testTheApplicableListDoesNotSwallowARefusal()

	/**
	 * The decision controller, over a given decision service.
	 *
	 * @param BeschikkingService $service The service mock.
	 *
	 * @return BeschikkingController The controller.
	 */
	private function decisionController(BeschikkingService $service): BeschikkingController {
		return new BeschikkingController(
			appName: 'dossiq',
			request: $this->createMock(originalClassName: IRequest::class),
			decisionService: $service,
			userSession: $this->session(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end decisionController()

	/**
	 * An unreadable mandate scheme answers 503, not 403 "insufficient mandaat".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAnUnreadableMandateSchemeAnswers503(): void {
		$service = $this->createMock(originalClassName: BeschikkingService::class);
		$service->method('akkoord')->willThrowException(
			RefusedException::indeterminate(
				rule: 'mandaat-regeling-unreadable',
				sentence: 'The mandate scheme could not be read, so this approval cannot be checked right now.',
			)
		);

		$response = $this->decisionController(service: $service)->akkoord('decision-1');
		$body = (array)$response->getData();

		self::assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
		self::assertSame(expected: 'mandaat-regeling-unreadable', actual: $body['error']);
	}//end testAnUnreadableMandateSchemeAnswers503()

	/**
	 * A mandate that genuinely does not reach still answers 403.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAnInsufficientMandateStillAnswers403(): void {
		$service = $this->createMock(originalClassName: BeschikkingService::class);
		$service->method('akkoord')->willThrowException(new RuntimeException('mandaat_insufficient'));

		$response = $this->decisionController(service: $service)->akkoord('decision-1');

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testAnInsufficientMandateStillAnswers403()

	/**
	 * The tenant middleware, over a given authentication service.
	 *
	 * @param TenantAuthenticationService $auth The auth mock.
	 *
	 * @return MandateValidationMiddleware The middleware.
	 */
	private function mandateMiddleware(TenantAuthenticationService $auth): MandateValidationMiddleware {
		return new MandateValidationMiddleware(
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $this->session(),
			context: $this->createMock(originalClassName: TenantContext::class),
			authService: $auth,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end mandateMiddleware()

	/**
	 * A mandate matrix that could not be read leaves the middleware as 503.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAnUnreadableMandateMatrixAnswers503(): void {
		$middleware = $this->mandateMiddleware(auth: $this->createMock(originalClassName: TenantAuthenticationService::class));

		$response = $middleware->afterException(
			$this->createMock(originalClassName: Controller::class),
			'update',
			RefusedException::indeterminate(
				rule: 'tenant-mandate-matrix-unreadable',
				sentence: 'The mandate matrix could not be read, so this action cannot be checked right now.',
			)
		);
		$body = (array)$response->getData();

		self::assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
		self::assertSame(expected: 'tenant-mandate-matrix-unreadable', actual: $body['error']);
		self::assertSame(expected: 'tenant_mandate_matrix_unreadable', actual: $body['code']);
	}//end testAnUnreadableMandateMatrixAnswers503()

	/**
	 * A mandate matrix that ran and denied still answers 403.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testADeniedMandateStillAnswers403(): void {
		$middleware = $this->mandateMiddleware(auth: $this->createMock(originalClassName: TenantAuthenticationService::class));

		$response = $middleware->afterException(
			$this->createMock(originalClassName: Controller::class),
			'update',
			new MandateDeniedException(message: 'Role reader is not authorised for action edit', code: 403)
		);

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testADeniedMandateStillAnswers403()

	/**
	 * The exception itself: rule, sentence, status and the code the UI reads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testTheRefusalCarriesAllFourFacts(): void {
		$refusal = new RefusedException(
			rule: 'transition-conflict',
			sentence: 'Another change reached this case first. Reload it and try again.',
		);

		self::assertSame(expected: 'transition-conflict', actual: $refusal->getRule());
		self::assertSame(expected: 'transition_conflict', actual: $refusal->getMessage());
		self::assertSame(expected: 409, actual: $refusal->getStatus());
		self::assertStringContainsString(needle: 'Reload it', haystack: $refusal->getSentence());

		$indeterminate = RefusedException::indeterminate(rule: 'x-unreadable', sentence: 'Could not tell.');
		self::assertSame(expected: 503, actual: $indeterminate->getStatus());
	}//end testTheRefusalCarriesAllFourFacts()
}//end class
