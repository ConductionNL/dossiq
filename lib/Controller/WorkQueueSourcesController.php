<?php

/**
 * Dossiq WorkQueueSourcesController.
 *
 * Whether every source a queue merges answered (board DqTeamwachtrij, "Bron
 * niet beschikbaar: taken uit pipelinq").
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
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Queue\QueueSourceStatus;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The queue source status of the signed-in reader's own queue.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
class WorkQueueSourcesController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string            $appName     App id.
	 * @param IRequest          $request     Request.
	 * @param IUserSession      $userSession User session.
	 * @param LoggerInterface   $logger      Logger.
	 * @param QueueSourceStatus $status      The sources that did not answer.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly QueueSourceStatus $status,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /api/work-queue/sources. Reads only the caller's own queue, so it
	 * names no item and no source the caller could not already see.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->status->forReader(userId: $user->getUID()));
		} catch (Throwable $e) {
			$this->logger->error('Queue source status could not be read', ['error' => $e->getMessage()]);

			return new JSONResponse(['message' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()
}//end class
