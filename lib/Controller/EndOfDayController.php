<?php

/**
 * Dossiq end of day controller
 *
 * `GET /api/personal-queue/end-of-day`: the reader's day for the "Close out
 * your day" page, worked out on the server so it survives a reload.
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
 * @spec openspec/specs/end-of-day-manifest/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Queue\EndOfDayService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDateTimeZone;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The reader's day.
 *
 * @spec openspec/specs/end-of-day-manifest/spec.md
 */
class EndOfDayController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest        $request     The request.
	 * @param IUserSession    $userSession The reader.
	 * @param EndOfDayService $endOfDay    The day lists.
	 * @param IDateTimeZone   $timeZone    The reader's time zone, which decides what "today" is.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly EndOfDayService $endOfDay,
		private readonly IDateTimeZone $timeZone,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The reader's day: what they opened today without a note, the notes they
	 * recorded today, what comes first tomorrow and the figures. "Opened" is
	 * OpenRegister's per-reader read state, not a second store of who saw what.
	 *
	 * @return JSONResponse The day.
	 *
	 * @spec openspec/specs/end-of-day-manifest/spec.md#requirement-the-day-lists-are-worked-out-on-the-server
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$now = new DateTimeImmutable('now', $this->timeZone->getTimeZone());
		return new JSONResponse($this->endOfDay->dayFor(userId: $user->getUID(), now: $now));
	}//end show()
}//end class
