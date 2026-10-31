<?php

/**
 * Dossiq AccrualNoticeController.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Money\AccrualNoticeService;
use OCA\Dossiq\Service\Money\MoneyConfigKeys;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * REST surface for ingebrekestelling registration.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */
class AccrualNoticeController extends Controller {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request Request.
	 * @param AccrualNoticeService $service Service.
	 * @param SettingsService $settings Settings.
	 * @param IUserSession $userSession User session.
	 * @param LoggerInterface $logger Logger.
	 * @param CaseAccessGuard $caseAccess Who may change the case the notice is given on.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccrualNoticeService $service,
		private readonly SettingsService $settings,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly CaseAccessGuard $caseAccess,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Per-object authorization guard.
	 *
	 * @return JSONResponse|null
	 */
	private function ensureAuthenticated(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end ensureAuthenticated()

	/**
	 * Register a notice of default.
	 *
	 * Body: `caseId` (the case's statutory decision term is looked up) or
	 * `termijnInstanceId`, plus `receiptDate`, `notificationChannel`, and
	 * optionally `sender` and `documentLink`. A notice received while the term
	 * still runs is refused with 409 and stored nowhere.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
	 */
	public function register(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
		}

		$body = $this->jsonBody();
		$request = $this->noticeRequest(body: $body);
		if ($request instanceof JSONResponse) {
			return $request;
		}

		$instance = $this->termInstanceOf(caseId: $request['caseId'], instanceId: $request['instanceId']);
		$refused = $this->refusalFor(instance: $instance, caseId: $request['caseId'], user: $user, receipt: $request['receipt']);
		if ($refused !== null) {
			return $refused;
		}

		try {
			$row = $this->service->registerNotice(
				(string)($instance['id'] ?? $request['instanceId']),
				$request['receipt'],
				$request['channel'],
				(string)($body['documentLink'] ?? ''),
				trim((string)($body['sender'] ?? ''))
			);
			return new JSONResponse($row, Http::STATUS_CREATED);
		} catch (Throwable $e) {
			$this->logger->info('Ingebrekestelling register failed: ' . $e->getMessage());
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end register()

	/**
	 * The fields a notice needs, read from the body, or the 400 that says what is missing.
	 *
	 * @param array<string, mixed> $body The request body.
	 *
	 * @return array{caseId: string, instanceId: string, channel: string, receipt: DateTimeImmutable}|JSONResponse
	 *
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
	 */
	private function noticeRequest(array $body): array|JSONResponse {
		$caseId = (string)($body['caseId'] ?? '');
		$instanceId = (string)($body['termijnInstanceId'] ?? '');
		$channel = (string)($body['notificationChannel'] ?? '');
		$whenStr = (string)($body['receiptDate'] ?? '');
		if (($instanceId === '' && $caseId === '') || $channel === '' || $whenStr === '') {
			return new JSONResponse(
				['message' => 'caseId or termijnInstanceId, receiptDate and notificationChannel are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$receipt = new DateTimeImmutable($whenStr);
		} catch (Throwable $e) {
			return new JSONResponse(['message' => 'receiptDate is not a date'], Http::STATUS_BAD_REQUEST);
		}

		return ['caseId' => $caseId, 'instanceId' => $instanceId, 'channel' => $channel, 'receipt' => $receipt];
	}//end noticeRequest()

	/**
	 * Why the notice may not be registered on this term, or null when it may.
	 *
	 * Fails closed: no term (404), a term without a case, a term of another
	 * case or a case the caller may not change (403), and a term that still
	 * runs (409, with the day it ends).
	 *
	 * @param array<string, mixed>|null $instance The term instance, or null.
	 * @param string                    $caseId   The case the caller named, or ''.
	 * @param IUser                     $user     The caller.
	 * @param DateTimeImmutable         $receipt  The day the notice came in.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 *
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
	 */
	private function refusalFor(?array $instance, string $caseId, IUser $user, DateTimeImmutable $receipt): ?JSONResponse {
		if ($instance === null) {
			return new JSONResponse(['message' => 'This case has no decision term to give notice on'], Http::STATUS_NOT_FOUND);
		}

		$owningCase = (string)($instance['case'] ?? '');
		if ($owningCase === '' || ($caseId !== '' && $owningCase !== $caseId)
			|| $this->caseAccess->hasCaseMutationAccess(caseId: $owningCase, user: $user) === false
		) {
			return new JSONResponse(['message' => 'Not authorized for this case'], Http::STATUS_FORBIDDEN);
		}

		$reason = $this->service->prematureReason(instance: $instance, receiptDate: $receipt);
		if ($reason === '') {
			return null;
		}

		return new JSONResponse(
			['message' => $reason, 'refusal' => 'term-running', 'termEnds' => (string)($instance['endDateCurrent'] ?? '')],
			Http::STATUS_CONFLICT
		);
	}//end refusalFor()

	/**
	 * The term instance the notice is given on: the case's statutory term, or the named instance.
	 *
	 * @param string $caseId     The case id, or ''.
	 * @param string $instanceId The TermijnInstance id, or ''.
	 *
	 * @return array<string, mixed>|null The instance, or null when there is none.
	 *
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
	 */
	private function termInstanceOf(string $caseId, string $instanceId): ?array {
		try {
			if ($caseId !== '') {
				return $this->service->statutoryTermOf(caseId: $caseId);
			}

			return $this->service->termInstance(termInstanceId: $instanceId);
		} catch (Throwable $e) {
			$this->logger->info('Notice of default: term lookup failed: ' . $e->getMessage());
			return null;
		}
	}//end termInstanceOf()

	/**
	 * Decode the JSON request body.
	 *
	 * IRequest::getContent() is protected on the concrete OC request, so the
	 * payload is read from php://input. Protected so a test can supply it.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
	 */
	protected function jsonBody(): array {
		$body = json_decode((string)file_get_contents('php://input'), true);
		if (is_array($body) === true) {
			return $body;
		}

		return [];
	}//end jsonBody()

	/**
	 * Deprecated alias of /api/notices-of-default, kept for clients of the old URL.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-003)
	 *
	 * @contract exclude Deprecated path alias for register(); behaviour covered by the register() tests.
	 */
	public function registerLegacyPath(): JSONResponse {
		return $this->register();
	}//end registerLegacyPath()

	/**
	 * Get an ingebrekestelling by id.
	 *
	 * @param string $id Id.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function show(string $id): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$objectService = $this->settings->getObjectService();
		$register = (string)$this->settings->getConfigValue('register');
		$schema = (string)$this->settings->getConfigValue(MoneyConfigKeys::NOTICE_OF_DEFAULT_SCHEMA);
		if ($objectService === null || $register === '' || $schema === '') {
			return new JSONResponse(['message' => 'Service unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		try {
			$row = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id);
		} catch (Throwable $e) {
			return new JSONResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if ($row === null) {
			return new JSONResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($row);
	}//end show()

	/**
	 * Deprecated alias of /api/notices-of-default/{id}, kept for clients of the old URL.
	 *
	 * @param string $id Id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-003)
	 *
	 * @contract exclude Deprecated path alias for show(); behaviour covered by the show() tests.
	 */
	public function showLegacyPath(string $id): JSONResponse {
		return $this->show(id: $id);
	}//end showLegacyPath()
}//end class
