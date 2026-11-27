<?php

/**
 * Dossiq session follow-ups.
 *
 * What happens around a session plan besides storing it: the planned session
 * opens as a meeting in the decision app, it is recorded on the case's
 * history, and a session the register refuses is logged with its cause. Each
 * part is optional; without it the plan itself still stands.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Session
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
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-planned-session-opens-as-a-meeting-in-the-decision-app-req-css-6
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Session;

use OCA\Dossiq\Kernel\Contracts\CaseTimelineInterface;
use OCA\Dossiq\Kernel\Contracts\TimelineKindsInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Opens the meeting, records the history entry and logs a refused session.
 *
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-planned-session-opens-as-a-meeting-in-the-decision-app-req-css-6
 */
class SessionFollowUps {

	/**
	 * Constructor.
	 *
	 * @param SessionMeeting|null        $meetings Opens a planned session as a meeting in the decision app.
	 * @param CaseTimelineInterface|null $timeline Records the planned session on the case's history.
	 * @param LoggerInterface            $logger   Names the cause of a refused session.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?SessionMeeting $meetings = null,
		private readonly ?CaseTimelineInterface $timeline = null,
		private readonly LoggerInterface $logger = new NullLogger(),
	) {
	}//end __construct()

	/**
	 * Open a stored session as a meeting, and answer the meeting's id.
	 *
	 * @param array<string, mixed>        $session  The stored session, with its id.
	 * @param array{0: string, 1: string} $location The register and the session schema it is stored in.
	 *
	 * @return string The meeting id, or '' when no meeting was opened.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-planned-session-opens-as-a-meeting-in-the-decision-app-req-css-6
	 */
	public function openMeeting(array $session, array $location): string {
		if ($this->meetings === null) {
			return '';
		}

		return $this->meetings->open(session: $session, location: $location);
	}//end openMeeting()

	/**
	 * Put the planned session on the case's history, so the case shows it beside its other events.
	 *
	 * @param SessionWrite         $write   The planned session.
	 * @param array<string, mixed> $session The stored session.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/complaints-are-a-case-type/specs/complaint-management/spec.md
	 */
	public function recordPlanned(SessionWrite $write, array $session): void {
		if ($this->timeline === null) {
			return;
		}

		$this->timeline->record(
			caseId: $write->caseId,
			kind: TimelineKindsInterface::SESSION,
			message: $write->definition->label . ' planned for ' . $write->scheduled->format('Y-m-d H:i'),
			fields: [
				'act'           => 'planned',
				'sessionId'     => (string)($session['id'] ?? ($session['@self']['id'] ?? '')),
				'sessionKey'    => $write->definition->key,
				'scheduledDate' => $write->scheduled->format(DATE_ATOM),
			],
		);
	}//end recordPlanned()

	/**
	 * Log why the register refused a session, so the error answer names its cause somewhere an operator can read.
	 *
	 * @param string    $caseId The case the session belongs to.
	 * @param Throwable $error  Why the register refused it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-planning-a-session-keeps-the-inspection-floor-and-the-invitee-roles-req-css-2
	 */
	public function saveRefused(string $caseId, Throwable $error): void {
		$this->logger->error(
			'SessionService: the register refused the session',
			['caseId' => $caseId, 'exception' => $error->getMessage()],
		);
	}//end saveRefused()
}//end class
