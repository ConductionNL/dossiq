<?php

/**
 * Procest Bezwaar Audit Trail.
 *
 * The single append-only `auditTrail` writer for every bezwaar domain
 * record. HearingService and AdvisoryCommitteeService each carried their
 * own private `appendAudit()` + `resolveUserId()` pair with identical
 * bodies; the entry shape and the "actor is ALWAYS derived from
 * IUserSession, never from the caller" rule are a single concern and now
 * live here and nowhere else.
 *
 * This class also owns the canonical Awb / AVG tag vocabulary (REQ-BH-8).
 * Every downstream consumer — beroep dossier export, the accessibility
 * report — reads these exact values, so they are declared once here and
 * re-exported from HearingService for backwards compatibility.
 *
 * Entry shape: an untagged entry is `{event, actor, at, payload}`; a
 * tagged entry is `{event, tag, actor, at, payload}`. Key order is part
 * of the contract because consumers compare whole entries.
 *
 * @category Service
 * @package  OCA\Procest\Service\Bezwaar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Bezwaar;

use DateTimeImmutable;
use DateTimeInterface;
use OCP\IUserSession;

/**
 * Appends entries to a bezwaar record's append-only audit trail.
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
class BezwaarAuditTrail {

	/**
	 * Awb art. 7:2 — hearing scheduled / invitation sent.
	 */
	public const TAG_SCHEDULED = 'awb-art-7:2';

	/**
	 * Awb art. 7:2 — invitation dispatched to an invitee.
	 */
	public const TAG_INVITATION_SENT = 'awb-art-7:2';

	/**
	 * Awb art. 7:3 — bezwaarmaker waived the hoorrecht.
	 */
	public const TAG_WAIVER = 'awb-art-7:3';

	/**
	 * Awb art. 7:4 — inspection of the file (inzage).
	 */
	public const TAG_INSPECTION = 'awb-art-7:4';

	/**
	 * Awb art. 7:6 — a confidential document was withheld.
	 */
	public const TAG_CONFIDENTIAL_WITHELD = 'awb-art-7:6';

	/**
	 * Awb art. 7:7 — verslaglegging (minutes / attendance record).
	 */
	public const TAG_VERSLAG = 'awb-art-7:7';

	/**
	 * Awb art. 7:13 — referral to the bezwaaradviescommissie.
	 */
	public const TAG_BAC_REFERRAL = 'awb-art-7:13';

	/**
	 * AVG art. 6 — consent basis for an audio recording.
	 */
	public const TAG_RECORDING_CONSENT = 'avg-art-6';

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Acting identity source.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Append one entry to an existing audit trail.
	 *
	 * @param array<int, array<string, mixed>> $existing Existing audit entries.
	 * @param string $event Event slug.
	 * @param array<string, mixed> $payload Structured payload.
	 * @param string $tag Awb / AVG tag, or '' for an untagged entry.
	 *
	 * @return array<int, array<string, mixed>> The trail with the new entry appended.
	 *
	 * @spec openspec/specs/bezwaar-hearing/spec.md
	 */
	public function append(array $existing, string $event, array $payload, string $tag = ''): array {
		$entry = ['event' => $event];

		if ($tag !== '') {
			$entry['tag'] = $tag;
		}

		$entry['actor'] = $this->resolveActor();
		$entry['at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$entry['payload'] = $payload;

		$existing[] = $entry;

		return $existing;
	}//end append()

	/**
	 * Resolve the acting user UID from IUserSession.
	 *
	 * Identity is never taken from caller-supplied data; a session-less
	 * (cron / listener) context is recorded as `system`.
	 *
	 * @return string The acting UID, or 'system' when there is no session.
	 *
	 * @spec openspec/specs/bezwaar-hearing/spec.md
	 */
	public function resolveActor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end resolveActor()
}//end class
