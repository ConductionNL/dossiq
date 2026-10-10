<?php

/**
 * Dossiq Beschikking Successor Controller.
 *
 * `POST /api/beschikkingen/{id}/successor`: correct or withdraw a signed
 * beschikking by issuing a numbered successor (REQ-BES-012).
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
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Beschikking\BeschikkingRepository;
use OCA\Dossiq\Service\Beschikking\BeschikkingSuccession;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Issues a wijzigingsbeschikking or an intrekkingsbeschikking.
 *
 * WHO MAY. Whoever may change the case the beschikking belongs to: issuing a
 * successor writes on that case, and the per-case mutation check is the one
 * the rest of the case's writes already answer to. The beschikking's own
 * lifecycle (mandaat at akkoord, the signature) still applies to the
 * successor, which is born a draft.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class BeschikkingSuccessorController extends Controller {
	use TranslatesRefusals;

	/**
	 * Constructor.
	 *
	 * @param string                $appName     The app name.
	 * @param IRequest              $request     The HTTP request.
	 * @param BeschikkingSuccession $succession  Issues the successor.
	 * @param BeschikkingRepository $repository  Reads the original, for its case.
	 * @param CaseAccessGuard       $accessGuard Per-case mutation access.
	 * @param IUserSession          $userSession The current session.
	 * @param LoggerInterface       $logger      The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly BeschikkingSuccession $succession,
		private readonly BeschikkingRepository $repository,
		private readonly CaseAccessGuard $accessGuard,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Issue a successor to the beschikking `{id}`.
	 *
	 * Body: `decisionType` (`amendment` or `withdrawal`), and optionally
	 * `rationale`, `decision`, `addressee` and `templateId`.
	 *
	 * @param string $id The beschikking being corrected or withdrawn.
	 *
	 * @return JSONResponse 201 with the successor; 401, 403, 404, 409 or 422 otherwise.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function create(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$original = $this->repository->find(decisionId: $id);
		if ($original === null) {
			return new JSONResponse(['error' => 'Beschikking not found'], Http::STATUS_NOT_FOUND);
		}

		$caseId = (string)($original['caseId'] ?? '');
		if ($caseId === '' || $this->accessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'You may not change this case'], Http::STATUS_FORBIDDEN);
		}

		$body = $this->request->getParams();

		try {
			$successor = $this->succession->issue(
				originalId: $id,
				kind: (string)($body['decisionType'] ?? ''),
				overrides: $this->contentOf(body: $body),
				templateId: $this->templateOf(body: $body),
			);

			return new JSONResponse($successor, Http::STATUS_CREATED);
		} catch (RefusedException $refusal) {
			return $this->refused(op: 'successor', e: $refusal);
		} catch (Throwable $failure) {
			$this->logger->error(
				'BeschikkingSuccessorController: successor failed',
				['exception' => $failure->getMessage(), 'beschikkingId' => $id],
			);

			return new JSONResponse(['error' => 'Could not complete the request'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end create()

	/**
	 * The successor's content as the handler sent it, and nothing else.
	 *
	 * The number, the pointers and the status are never read from the
	 * body: compose() and the succession write them.
	 *
	 * @param array<string, mixed> $body The request parameters.
	 *
	 * @return array<string, mixed> Rationale, decision and addressee, where sent.
	 */
	private function contentOf(array $body): array {
		$overrides = [];
		foreach (['rationale', 'decision', 'addressee'] as $field) {
			if (array_key_exists($field, $body) === true) {
				$overrides[$field] = $body[$field];
			}
		}

		return $overrides;
	}//end contentOf()

	/**
	 * The template the handler chose, or null to keep the original's.
	 *
	 * @param array<string, mixed> $body The request parameters.
	 *
	 * @return string|null The template id.
	 */
	private function templateOf(array $body): ?string {
		$templateId = trim((string)($body['templateId'] ?? ''));
		if ($templateId === '') {
			return null;
		}

		return $templateId;
	}//end templateOf()
}//end class
