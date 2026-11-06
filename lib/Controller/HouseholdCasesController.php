<?php

/**
 * The other cases of a case's household (board DqSociaalDomein).
 *
 * GET  /api/cases/{caseId}/household-cases         the household's cases in this domain the reader may open,
 *                                                   then what the reader looked up in other domains this session
 * POST /api/cases/{caseId}/household-cases/lookup  look the household up in the other domains, on a chosen ground
 *
 * Both answer only to a reader who may see the social domain's sensitive
 * fields (dossiq-sensitive; an administrator outside that group is refused
 * too) and who may read the case.
 * An other-domain row carries `exists`, `domain` and `contact` and nothing
 * else, and it exists only because the reader chose a ground and the lookup
 * was logged. It is kept in the reader's session, never stored.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CitizenLookupGuard;
use OCA\Dossiq\Service\SociaalDomein\HouseholdCases;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists a case's household cases, and looks the household up in other domains.
 *
 * Two guards, the session and the household service make thirteen classes
 * with the framework's own; each is one the endpoint needs.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
class HouseholdCasesController extends Controller {
	use TranslatesRefusals;

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request     The request.
	 * @param IUserSession       $userSession Who is asking.
	 * @param ISession           $session     Where this reader's lookups are kept until they sign out.
	 * @param CaseAccessGuard    $caseAccess  Whether the reader may read a case.
	 * @param CitizenLookupGuard $sensitive   Whether the reader may see the social domain's sensitive fields.
	 * @param HouseholdCases     $household   Builds the rows.
	 * @param LoggerInterface    $logger      The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ISession $session,
		private readonly CaseAccessGuard $caseAccess,
		private readonly CitizenLookupGuard $sensitive,
		private readonly HouseholdCases $household,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The household's cases in this domain, then this session's other-domain answers.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse `{ results }`.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	#[NoAdminRequired]
	public function index(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		$refusal = $this->refusalFor(user: $user, caseId: $caseId);
		if ($refusal !== null || $user === null) {
			return $refusal ?? $this->unauthenticated();
		}

		try {
			$case = $this->household->readCase(caseId: $caseId);
			if ($case === null) {
				return new JSONResponse(['results' => []]);
			}

			$bsns = $this->household->householdBsns(case: $case);
			if ($bsns === []) {
				return new JSONResponse(['results' => []]);
			}

			$rows = $this->household->sameDomain(
				case: $case,
				bsns: $bsns,
				canRead: fn (string $id): bool => $this->caseAccess->hasCaseReadAccess(caseId: $id, user: $user),
			);

			return new JSONResponse(['results' => array_merge($rows, $this->lookedUp(caseId: $caseId, user: $user))]);
		} catch (RefusedException $e) {
			return $this->refused(op: 'household-cases', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'household-cases', e: $e);
		}//end try
	}//end index()

	/**
	 * Look the household up in the other domains, on the chosen ground.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse `{ results }`: the other-domain rows.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-the-lookup-requires-a-ground-chosen-first-and-logged-req-xdv-02
	 */
	#[NoAdminRequired]
	public function lookUp(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		$refusal = $this->refusalFor(user: $user, caseId: $caseId);
		if ($refusal !== null || $user === null) {
			return $refusal ?? $this->unauthenticated();
		}

		try {
			$case      = $this->household->readCase(caseId: $caseId);
			$bsns      = [];
			$ownDomain = '';
			if ($case !== null) {
				$bsns      = $this->household->householdBsns(case: $case);
				$ownDomain = $this->household->ownDomain(case: $case);
			}

			$rows = $this->household->otherDomains(
				bsns: $bsns,
				ownDomain: $ownDomain,
				ground: (string)$this->request->getParam('ground', ''),
				requester: $user->getUID(),
			);
			$this->session->set($this->sessionKey(caseId: $caseId, user: $user), json_encode($rows));

			return new JSONResponse(['results' => $rows]);
		} catch (RefusedException $e) {
			return $this->refused(op: 'household-lookup', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'household-lookup', e: $e);
		}//end try
	}//end lookUp()

	/**
	 * Why this reader may not ask about this case, or null when they may.
	 *
	 * @param IUser|null $user   The reader.
	 * @param string     $caseId The case uuid.
	 *
	 * @return JSONResponse|null The refusal.
	 */
	private function refusalFor(?IUser $user, string $caseId): ?JSONResponse {
		if ($user === null) {
			return $this->unauthenticated();
		}

		if ($this->sensitive->maySeeSensitiveFields(user: $user) === false
			|| $this->caseAccess->hasCaseReadAccess(caseId: $caseId, user: $user) === false
		) {
			return new JSONResponse(
				[
					'message' => 'Only someone who works with the social domain\'s sensitive data on this case can see its household.',
					'error'   => 'household-not-allowed',
				],
				Http::STATUS_FORBIDDEN,
			);
		}

		return null;
	}//end refusalFor()

	/**
	 * The other-domain rows this reader looked up on this case this session.
	 *
	 * @param string $caseId The case uuid.
	 * @param IUser  $user   The reader.
	 *
	 * @return array<int, array{exists: bool, domain: string, contact: string}> The rows.
	 */
	private function lookedUp(string $caseId, IUser $user): array {
		$kept = json_decode((string)($this->session->get($this->sessionKey(caseId: $caseId, user: $user)) ?? ''), true);
		if (is_array($kept) === false) {
			return [];
		}

		$rows = [];
		foreach ($kept as $row) {
			if (is_array($row) === true) {
				$rows[] = [
					'exists'  => (bool)($row['exists'] ?? false),
					'domain'  => (string)($row['domain'] ?? ''),
					'contact' => (string)($row['contact'] ?? ''),
				];
			}
		}

		return $rows;
	}//end lookedUp()

	/**
	 * Where a reader's lookup on a case is kept in their session.
	 *
	 * @param string $caseId The case uuid.
	 * @param IUser  $user   The reader.
	 *
	 * @return string The session key.
	 */
	private function sessionKey(string $caseId, IUser $user): string {
		return 'dossiq.household-lookup.' . $user->getUID() . '.' . $caseId;
	}//end sessionKey()

	/**
	 * The answer to a request nobody signed in for.
	 *
	 * @return JSONResponse 401.
	 */
	private function unauthenticated(): JSONResponse {
		return new JSONResponse(['message' => 'Sign in first.', 'error' => 'not-signed-in'], Http::STATUS_UNAUTHORIZED);
	}//end unauthenticated()

	/**
	 * Log a failure and answer it as one.
	 *
	 * @param string    $op The act.
	 * @param Throwable $e  What went wrong.
	 *
	 * @return JSONResponse 500.
	 */
	private function broke(string $op, Throwable $e): JSONResponse {
		$this->logger->error('HouseholdCasesController: ' . $op . ' failed', ['exception' => $e->getMessage()]);

		return new JSONResponse(['message' => 'That did not work.', 'error' => 'household-failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end broke()
}//end class
