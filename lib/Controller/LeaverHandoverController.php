<?php

/**
 * Handing over everything a person holds, when they leave.
 *
 * Two endpoints and no third: a preview that mutates nothing, and an execute
 * that runs what the preview named. They are separate calls rather than one
 * call with a `dryRun` flag, because a flag that defaults wrong runs a two
 * hundred case handover nobody looked at.
 *
 * 🔴 ADMINISTRATOR ONLY, AND EXPLICITLY. This act reaches every case a person
 * touches, in every team, which no per-case guard can scope. The check is
 * written here rather than left to `#[NoAdminRequired]`'s absence, so the
 * authority is readable beside the act.
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
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use InvalidArgumentException;
use OCA\Dossiq\Service\People\LeaverHandoverService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Previews and runs the handover of a leaver's work.
 *
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */
class LeaverHandoverController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                $appName     The app name.
	 * @param IRequest              $request     The request.
	 * @param LeaverHandoverService $handover    The act itself.
	 * @param IGroupManager         $groups      Administrator resolution.
	 * @param IUserSession          $userSession The session.
	 * @param LoggerInterface       $logger      The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly LeaverHandoverService $handover,
		private readonly IGroupManager $groups,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * What a leaver handover would move. Mutates nothing.
	 *
	 * @return JSONResponse The preview.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function preview(): JSONResponse {
		$refusal = $this->requireAdministrator();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return new JSONResponse(
				$this->handover->preview(fromUser: (string)$this->request->getParam('fromUser', ''))
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				['message' => 'Name the person who is leaving.', 'error' => 'leaver-not-named'],
				Http::STATUS_BAD_REQUEST,
			);
		} catch (Throwable $e) {
			return $this->broke(op: 'preview', e: $e);
		}
	}//end preview()

	/**
	 * Run the handover the preview named.
	 *
	 * @return JSONResponse What moved.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function execute(): JSONResponse {
		$refusal = $this->requireAdministrator();
		if ($refusal !== null) {
			return $refusal;
		}

		$user = $this->userSession->getUser();
		$actor = '';
		if ($user !== null) {
			$actor = $user->getUID();
		}

		try {
			return new JSONResponse(
				$this->handover->execute(
					fromUser: (string)$this->request->getParam('fromUser', ''),
					toUser: (string)$this->request->getParam('toUser', ''),
					actor: $actor,
				)
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				['message' => 'Name the person who is leaving and the person receiving the work.', 'error' => 'leaver-handover-incomplete'],
				Http::STATUS_BAD_REQUEST,
			);
		} catch (Throwable $e) {
			return $this->broke(op: 'execute', e: $e);
		}
	}//end execute()

	/**
	 * Refuse anyone who is not an administrator.
	 *
	 * BOTH the attribute and this guard, and neither is redundant.
	 * `AuthorizedAdminSetting` is what Nextcloud's middleware enforces before
	 * the method runs, and it is what makes the posture READABLE on the
	 * endpoint instead of implied by the absence of `#[NoAdminRequired]`. This
	 * body check is the one that survives a refactor that drops the attribute,
	 * and it is what names the actor for the record the act writes.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may act.
	 */
	private function requireAdministrator(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null || $this->groups->isAdmin($user->getUID()) === false) {
			return new JSONResponse(
				['message' => 'Only an administrator can hand over somebody\'s work.', 'error' => 'not-an-administrator'],
				Http::STATUS_FORBIDDEN,
			);
		}

		return null;
	}//end requireAdministrator()

	/**
	 * A failure that is not a refusal, logged and answered as one.
	 *
	 * @param string    $op The endpoint, for the log line.
	 * @param Throwable $e  The failure.
	 *
	 * @return JSONResponse The answer.
	 */
	private function broke(string $op, Throwable $e): JSONResponse {
		$this->logger->error('LeaverHandoverController: ' . $op . ' failed', ['exception' => $e->getMessage()]);

		return new JSONResponse(
			['message' => 'The handover could not be completed.', 'error' => 'leaver-handover-failed'],
			Http::STATUS_INTERNAL_SERVER_ERROR,
		);
	}//end broke()
}//end class
