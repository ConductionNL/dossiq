<?php

/**
 * Dossiq PersonalQueueController.
 *
 * Every endpoint here answers for the CALLER and takes no user parameter. That
 * is the authorisation model, and it is deliberate rather than lazy: a queue
 * endpoint that accepted a user id would be one missing guard away from
 * letting anybody read anybody's day, and a personal stage endpoint that
 * accepted one would make the private label readable by the person it is
 * private from. There is nothing to get wrong if there is nothing to pass.
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
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Queue\DigestPreferences;
use OCA\Dossiq\Service\Queue\PersonalAgendaItemService;
use OCA\Dossiq\Service\Queue\PersonalQueueService;
use OCA\Dossiq\Service\Queue\PersonalStageService;
use OCA\Dossiq\Service\Queue\QueueViewPreferences;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * The caller's own queue, end of day, planned items and private stages.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class PersonalQueueController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request     The request.
	 * @param IUserSession              $userSession The caller.
	 * @param PersonalQueueService      $queue       The queue itself.
	 * @param QueueViewPreferences      $view        What the reader changed about their view.
	 * @param PersonalStageService      $stages      The reader's private labels.
	 * @param PersonalAgendaItemService $agenda      The reader's own planned items.
	 * @param DigestPreferences         $digest      When the reader wants their digest.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly PersonalQueueService $queue,
		private readonly QueueViewPreferences $view,
		private readonly PersonalStageService $stages,
		private readonly PersonalAgendaItemService $agenda,
		private readonly DigestPreferences $digest,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Everything waiting on the caller.
	 *
	 * @return JSONResponse The queue.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$userId = $this->caller();
		if ($userId === '') {
			return $this->unauthenticated();
		}

		return new JSONResponse($this->queue->forPerson(userId: $userId));
	}//end index()

	/**
	 * What the caller can still do to an item that is refusing to leave.
	 *
	 * There is no endpoint that removes an item, and this one says so rather
	 * than 404ing: a gesture the interface offers has to get an answer it can
	 * act on, and the answer is the hide-for-today offer.
	 *
	 * @param string $group The group the item sits in.
	 *
	 * @return JSONResponse What is hidden now.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	#[NoAdminRequired]
	public function hideGroup(string $group): JSONResponse {
		$userId = $this->caller();
		if ($userId === '') {
			return $this->unauthenticated();
		}

		return new JSONResponse([
			'hiddenGroups' => $this->view->hideForToday(
				userId: $userId,
				group: $group,
				today: (new DateTimeImmutable())->format('Y-m-d')
			),
		]);
	}//end hideGroup()

	/**
	 * Show a group the caller hid.
	 *
	 * @param string $group The group.
	 *
	 * @return JSONResponse What is hidden now.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	#[NoAdminRequired]
	public function showGroup(string $group): JSONResponse {
		$userId = $this->caller();
		if ($userId === '') {
			return $this->unauthenticated();
		}

		return new JSONResponse([
			'hiddenGroups' => $this->view->showAgain(
				userId: $userId,
				group: $group,
				today: (new DateTimeImmutable())->format('Y-m-d')
			),
		]);
	}//end showGroup()

	/**
	 * Remember how the caller groups their queue.
	 *
	 * @return JSONResponse The grouping now stored.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	#[NoAdminRequired]
	public function setGrouping(): JSONResponse {
		$userId = $this->caller();
		if ($userId === '') {
			return $this->unauthenticated();
		}

		return new JSONResponse([
			'groupBy' => $this->view->setGroupBy(
				userId: $userId,
				groupBy: (string)$this->request->getParam('groupBy', 'source')
			),
		]);
	}//end setGrouping()

	/**
	 * The cases and tasks the caller may close their day on.
	 *
	 * dossiq answers WHICH items are candidates. Whether the caller touched
	 * one today is the register's own per-reader read state, which the screen
	 * asks for per item; reproducing that answer here would be a second store
	 * of who has seen what, which `unread-state-on-the-case` exists to stop.
	 *
	 * @return JSONResponse The candidates.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	#[NoAdminRequired]
	public function endOfDay(): JSONResponse {
		$userId = $this->caller();
		if ($userId === '') {
			return $this->unauthenticated();
		}

		$queue = $this->queue->forPerson(userId: $userId);
		$candidates = array_values(
			array_filter(
				$queue['items'],
				static fn (array $item): bool => in_array(($item['subjectType'] ?? ''), ['case', 'task'], true)
			)
		);

		return new JSONResponse(['items' => $candidates, 'unavailable' => $queue['unavailable']]);
	}//end endOfDay()

	/**
	 * Plan an item on the caller's own agenda.
	 *
	 * @return JSONResponse What was planned.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	#[NoAdminRequired]
	public function planItem(): JSONResponse {
		$userId = $this->caller();
		if ($userId === '') {
			return $this->unauthenticated();
		}

		try {
			$planned = $this->agenda->plan(
				userId: $userId,
				title: (string)$this->request->getParam('title', ''),
				startsAt: (string)$this->request->getParam('startsAt', ''),
				template: (string)$this->request->getParam('template', '')
			);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($planned);
	}//end planItem()

	/**
	 * The caller's own stage on one case.
	 *
	 * @param string $caseId The case.
	 *
	 * @return JSONResponse The stage.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	#[NoAdminRequired]
	public function stage(string $caseId): JSONResponse {
		$userId = $this->caller();
		if ($userId === '') {
			return $this->unauthenticated();
		}

		return new JSONResponse(['stage' => $this->stages->get(userId: $userId, caseId: $caseId)]);
	}//end stage()

	/**
	 * Set the caller's own stage on one case.
	 *
	 * @param string $caseId The case.
	 *
	 * @return JSONResponse The stage now stored.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	#[NoAdminRequired]
	public function setStage(string $caseId): JSONResponse {
		$userId = $this->caller();
		if ($userId === '') {
			return $this->unauthenticated();
		}

		return new JSONResponse([
			'stage' => $this->stages->set(
				userId: $userId,
				caseId: $caseId,
				stage: (string)$this->request->getParam('stage', '')
			),
		]);
	}//end setStage()

	/**
	 * When the caller wants their digest.
	 *
	 * @return JSONResponse The settings.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	#[NoAdminRequired]
	public function digestSettings(): JSONResponse {
		$userId = $this->caller();
		if ($userId === '') {
			return $this->unauthenticated();
		}

		return new JSONResponse($this->digest->forUser(userId: $userId));
	}//end digestSettings()

	/**
	 * Save when the caller wants their digest.
	 *
	 * @return JSONResponse The settings now stored.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	#[NoAdminRequired]
	public function saveDigestSettings(): JSONResponse {
		$userId = $this->caller();
		if ($userId === '') {
			return $this->unauthenticated();
		}

		return new JSONResponse(
			$this->digest->save(
				userId: $userId,
				enabled: ($this->request->getParam('enabled', true) !== false),
				hour: (int)$this->request->getParam('hour', DigestPreferences::DEFAULT_HOUR)
			)
		);
	}//end saveDigestSettings()

	/**
	 * Who is calling.
	 *
	 * @return string The caller's uid, or an empty string when nobody is signed in.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function caller(): string {
		return (string)($this->userSession->getUser()?->getUID() ?? '');
	}//end caller()

	/**
	 * The refusal for an unauthenticated call.
	 *
	 * @return JSONResponse The refusal.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function unauthenticated(): JSONResponse {
		return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
	}//end unauthenticated()
}//end class
