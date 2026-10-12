<?php

/**
 * Dossiq session rules.
 *
 * The checks a session is held to, read from its `SessionDefinition`: the
 * inspection floor, the invitee roles and minimum, the attendance grace window,
 * and the minutes and recording consent it requires. Kept apart from the
 * definition so the shape and its parsing stay in one class and the rules in
 * another.
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
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Session;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The rules one session definition sets.
 *
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-planning-a-session-keeps-the-inspection-floor-and-the-invitee-roles-req-css-2
 */
final class SessionRules {

	/**
	 * Constructor.
	 *
	 * @param SessionDefinition $definition The session definition.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SessionDefinition $definition,
	) {
	}//end __construct()

	/**
	 * The last day the file must be open for inspection before the session.
	 *
	 * @param DateTimeImmutable $scheduled The session start.
	 *
	 * @return DateTimeImmutable The inspection deadline.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-planning-a-session-keeps-the-inspection-floor-and-the-invitee-roles-req-css-2
	 */
	public function inspectionDeadline(DateTimeImmutable $scheduled): DateTimeImmutable {
		return $scheduled->sub(new DateInterval('P' . $this->definition->inspectionFloorDays . 'D'));
	}//end inspectionDeadline()

	/**
	 * Refuse a session planned closer than the inspection floor allows.
	 *
	 * @param DateTimeImmutable $scheduled The session start.
	 * @param DateTimeImmutable $now       The moment of planning.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the floor is breached.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-planning-a-session-keeps-the-inspection-floor-and-the-invitee-roles-req-css-2
	 */
	public function assertInspectionFloor(DateTimeImmutable $scheduled, DateTimeImmutable $now): void {
		$days = $this->definition->inspectionFloorDays;
		if ($scheduled < $now->add(new DateInterval('P' . $days . 'D'))) {
			throw new InvalidArgumentException(
				"{$this->definition->label} must be planned at least $days days ahead, so the file is open for inspection first."
			);
		}
	}//end assertInspectionFloor()

	/**
	 * Refuse an invitee list that is too short or names a role not allowed.
	 *
	 * @param array<int, mixed> $invitees The invitees.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the list does not fit.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-planning-a-session-keeps-the-inspection-floor-and-the-invitee-roles-req-css-2
	 */
	public function assertInvitees(array $invitees): void {
		if (count($invitees) < $this->definition->minInvitees) {
			throw new InvalidArgumentException("{$this->definition->label} needs at least {$this->definition->minInvitees} invitee(s).");
		}

		foreach ($invitees as $invitee) {
			if (is_array($invitee) === false) {
				throw new InvalidArgumentException('Each invitee must be an object.');
			}

			if ($this->definition->inviteeRoles === []) {
				continue;
			}

			$role = ($invitee['role'] ?? '');
			if (is_string($role) === false || in_array($role, $this->definition->inviteeRoles, true) === false) {
				$allowed = implode(', ', $this->definition->inviteeRoles);
				throw new InvalidArgumentException("{$this->definition->label} invites only these roles: $allowed.");
			}
		}
	}//end assertInvitees()

	/**
	 * The moment after which an attendance change needs a correction reason.
	 *
	 * @param DateTimeImmutable $scheduled The session start.
	 *
	 * @return DateTimeImmutable The freeze moment.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-attendance-and-minutes-follow-the-session-definition-req-css-3
	 */
	public function attendanceFreezesAt(DateTimeImmutable $scheduled): DateTimeImmutable {
		return $scheduled->add(new DateInterval('PT' . $this->definition->attendanceGraceHours . 'H'));
	}//end attendanceFreezesAt()

	/**
	 * Refuse attendance entries that come after the grace window without a reason.
	 *
	 * @param array<int, mixed> $entries The entries.
	 * @param bool              $closed  Whether the grace window has passed.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a late entry has no correction reason.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-attendance-and-minutes-follow-the-session-definition-req-css-3
	 */
	public function assertAttendance(array $entries, bool $closed): void {
		if ($closed === false) {
			return;
		}

		foreach ($entries as $entry) {
			$reason = '';
			if (is_array($entry) === true && is_string($entry['correctionReason'] ?? null) === true) {
				$reason = trim($entry['correctionReason']);
			}

			if ($reason === '') {
				throw new InvalidArgumentException('Attendance is closed for this session; each change needs a correction reason.');
			}
		}
	}//end assertAttendance()

	/**
	 * Refuse closing without the minutes or the recording consent this session requires.
	 *
	 * @param string $summary  The minutes summary, '' for none.
	 * @param string $document The minutes document, '' for none.
	 * @param string $audio    The audio recording, '' for none.
	 * @param string $consent  The recording consent.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When minutes or consent are missing.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-attendance-and-minutes-follow-the-session-definition-req-css-3
	 */
	public function assertMinutes(string $summary, string $document, string $audio, string $consent): void {
		if ($this->definition->minutesRequired === true && $summary === '' && $document === '') {
			throw new InvalidArgumentException("{$this->definition->label} needs minutes: a summary or a document.");
		}

		if ($audio !== '' && $this->definition->audioNeedsConsent === true && $consent !== 'granted') {
			throw new InvalidArgumentException('An audio recording needs the recording consent of the parties.');
		}
	}//end assertMinutes()

	/**
	 * The invitees as a session stores them: objects, each stamped with when it was invited.
	 *
	 * @param array<int, mixed> $invitees The invitees.
	 * @param DateTimeImmutable $when     The moment of inviting.
	 *
	 * @return array<int, array<string, mixed>> The stamped invitees.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-planning-a-session-keeps-the-inspection-floor-and-the-invitee-roles-req-css-2
	 */
	public function stampInvitees(array $invitees, DateTimeImmutable $when): array {
		$stamped = [];
		foreach ($invitees as $invitee) {
			if (is_array($invitee) === false) {
				continue;
			}

			if (is_string($invitee['invitedAt'] ?? null) === false || $invitee['invitedAt'] === '') {
				$invitee['invitedAt'] = $when->format(DateTimeInterface::ATOM);
			}

			$stamped[] = $invitee;
		}

		return $stamped;
	}//end stampInvitees()
}//end class
