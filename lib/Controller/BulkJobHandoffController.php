<?php

/**
 * Dossiq BulkJobHandoffController.
 *
 * The one endpoint dossiq ships for bulk work over cases: it hands the act to
 * OpenRegister's job. Progress, per-row outcome, the CSV, the cancel and the
 * retry are read straight from OpenRegister's own routes, because a proxy for
 * them here would be a second job model that can disagree with the first.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use InvalidArgumentException;
use OCA\Dossiq\Service\Bulk\BulkJobHandoff;
use OCA\Dossiq\Service\Bulk\MixedCaseTypeVersionsException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Hand a bulk act on cases over to the job.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
class BulkJobHandoffController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string          $appName     The app name.
	 * @param IRequest        $request     The request.
	 * @param BulkJobHandoff  $handoff     The hand-off to OpenRegister's job.
	 * @param IUserSession    $userSession The user session.
	 * @param LoggerInterface $logger      The logger.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly BulkJobHandoff $handoff,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Create a previewed bulk job over the selected cases.
	 *
	 * Per-object guard: the job is created as the calling user and
	 * OpenRegister writes every object as that user, so a handler who may not
	 * write a case sees it come back refused rather than applied. There is no
	 * object id in the path to guard on here; the selection is the body.
	 *
	 * @return JSONResponse The previewed job, or the refusal.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$action = (string)$this->request->getParam('action', '');
		$parameters = $this->arrayParam(name: 'parameters');
		$selection = $this->arrayParam(name: 'selection');
		$justification = trim((string)$this->request->getParam('justification', ''));

		try {
			$job = $this->handoff->create(
				actionId: $action,
				parameters: $parameters,
				selection: $selection,
				justification: (($justification === '') ? null : $justification),
				actorUid: $user->getUID(),
			);
		} catch (MixedCaseTypeVersionsException $e) {
			return $this->versionRefusal(exception: $e);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (RuntimeException $e) {
			return $this->engineRefusal(exception: $e, action: $action);
		} catch (Throwable $e) {
			$this->logger->error(
				'BulkJobHandoffController: the act could not be handed over',
				['exception' => $e->getMessage(), 'action' => $action],
			);

			return new JSONResponse(
				['error' => 'The bulk act could not be started'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}//end try

		return new JSONResponse($job, Http::STATUS_CREATED);
	}//end create()

	/**
	 * The refusal a selection spanning two case type versions gets.
	 *
	 * It names both versions and how many cases sit on each, because "mixed
	 * versions" tells a handler nothing they can act on (D-4).
	 *
	 * @param MixedCaseTypeVersionsException $exception The refusal.
	 *
	 * @return JSONResponse The 422.
	 */
	private function versionRefusal(MixedCaseTypeVersionsException $exception): JSONResponse {
		return new JSONResponse(
			[
				'error' => 'The selection holds cases on more than one version of a case type',
				'reason' => 'case-type-versions',
				'details' => [
					'caseType' => $exception->getCaseTypeTitle(),
					'versions' => $exception->getVersions(),
					'counts' => $exception->getCounts(),
				],
			],
			Http::STATUS_UNPROCESSABLE_ENTITY,
		);
	}//end versionRefusal()

	/**
	 * Pass OpenRegister's own refusal through with its reason intact.
	 *
	 * `BulkJobRefusedException` cannot be caught by name here: dossiq is
	 * installable without OpenRegister, and naming the class would be an
	 * autoload on an instance that has none. It extends `RuntimeException` and
	 * carries `getReason()`, which is what this reads.
	 *
	 * @param RuntimeException $exception The refusal, or an ordinary failure.
	 * @param string           $action    The action that was asked for.
	 *
	 * @return JSONResponse The 422, or a 503 when OpenRegister is simply absent.
	 */
	private function engineRefusal(RuntimeException $exception, string $action): JSONResponse {
		if (method_exists($exception, 'getReason') === false) {
			$this->logger->warning(
				'BulkJobHandoffController: the job engine refused the act',
				['exception' => $exception->getMessage(), 'action' => $action],
			);

			return new JSONResponse(
				['error' => $exception->getMessage()],
				Http::STATUS_SERVICE_UNAVAILABLE,
			);
		}

		$details = [];
		if (method_exists($exception, 'getDetails') === true) {
			$details = (array)$exception->getDetails();
		}

		return new JSONResponse(
			[
				'error' => $exception->getMessage(),
				'reason' => (string)$exception->getReason(),
				'details' => $details,
			],
			Http::STATUS_UNPROCESSABLE_ENTITY,
		);
	}//end engineRefusal()

	/**
	 * Read a body parameter that must be an object.
	 *
	 * A caller that sends a JSON string where an object belongs gets an empty
	 * array rather than a cast that silently produces `["{...}"]`.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return array<string, mixed> The parameter.
	 */
	private function arrayParam(string $name): array {
		$value = $this->request->getParam($name, []);

		return (is_array($value) === true) ? $value : [];
	}//end arrayParam()
}//end class
