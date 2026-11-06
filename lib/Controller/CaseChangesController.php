<?php

/**
 * Dossiq CaseChangesController.
 *
 * GET /api/cases/changes: what others changed on the cases a board shows,
 * since the board last asked (board DqWerkbord).
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
 * @spec openspec/specs/realtime-updates-ui/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Service\Board\CaseChangesFeed;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The case changes since a moment, only for cases the reader may read.
 *
 * @spec openspec/specs/realtime-updates-ui/spec.md
 */
class CaseChangesController extends Controller {
	/**
	 * How far back a poll may ask; an older `since` reads as this long ago.
	 */
	private const MAX_LOOKBACK = '-1 day';

	/**
	 * Constructor.
	 *
	 * @param string          $appName     App id.
	 * @param IRequest        $request     Request.
	 * @param IUserSession    $userSession User session.
	 * @param LoggerInterface $logger      Logger.
	 * @param CaseChangesFeed $feed        The changes.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly CaseChangesFeed $feed,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /api/cases/changes?since=<iso>&caseType=<uuid>&assignedGroup=<group>
	 *
	 * The feed reads each changed case again as the caller, so a case the
	 * caller may not read is never named. A first poll without `since` reads
	 * nothing and answers only `now`, the moment the next poll asks from.
	 *
	 * @param string $since         The moment the board last asked, ISO 8601.
	 * @param string $caseType      Only cases of this type, when given.
	 * @param string $assignedGroup Only cases of this group, when given.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/realtime-updates-ui/spec.md
	 */
	#[NoAdminRequired]
	public function index(string $since = '', string $caseType = '', string $assignedGroup = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if (trim($since) === '') {
			return new JSONResponse(['changes' => [], 'now' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
		}

		$moment = date_create_immutable(trim($since));
		if ($moment === false) {
			return new JSONResponse(['message' => 'since must be an ISO 8601 moment'], Http::STATUS_BAD_REQUEST);
		}

		$moment = max($moment, new DateTimeImmutable(self::MAX_LOOKBACK));

		try {
			return new JSONResponse(
				$this->feed->since(readerId: $user->getUID(), since: $moment, scope: ['caseType' => $caseType, 'assignedGroup' => $assignedGroup])
			);
		} catch (Throwable $e) {
			$this->logger->error('Case changes could not be read', ['error' => $e->getMessage()]);

			return new JSONResponse(['message' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()
}//end class
