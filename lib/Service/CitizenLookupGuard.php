<?php

/**
 * Dossiq Citizen Lookup Guard.
 *
 * Role authorization for the endpoints that resolve a raw citizen identifier
 * (BSN / burgerId) into that citizen's case and contact history.
 *
 * These endpoints have no per-object owner to check against: the subject is a
 * citizen, not a case, and a KCC agent legitimately answers a call from a
 * citizen they have never handled before. `CaseAccessGuard` therefore does not
 * apply — the question is not "does this user handle this case?" but "is this
 * user a klantcontactcentrum handler at all?".
 *
 * Before this guard existed, `GET /api/kcc/voorblad?burgerId=…` answered HTTP
 * 200 with the citizen's open cases and recent contact history — including the
 * caller's phone number and the free-text summary of every previous call — to
 * ANY authenticated account on the instance. Iterating BSN-shaped identifiers
 * walked the resident population. Reproduced live with two accounts before this
 * change (finding PROC-IDOR-01).
 *
 * Fails closed, and deliberately in the opposite direction to the bug
 * `CaseAccessGuard` was written for: there, a group that did not exist made
 * `groupExists() && !isInGroup()` short-circuit to "authorized". Here the
 * absence of every listed group grants nothing — only an actual membership, or
 * Nextcloud admin, passes.
 *
 * The group list mirrors the idiom already used for the other broad-scope reads
 * in this app (`AiAuditExportController::ALLOWED_GROUPS`,
 * `AiAuditExportController`, `ProcessMiningController`): a fixed set of
 * deployment group names plus an admin fallback.
 *
 * ⚠️ The MECHANISM is precedented; two of the four group NAMES are not. See
 * the note on {@see self::ALLOWED_GROUPS} — `beheerders` and `admin` are
 * attested elsewhere in this app, `kcc` and `klantcontact` are assumptions
 * made by the author of this class, and this guard denies until they exist.
 *
 * 🔴 WHAT THIS CLASS DOES NOT DO, NAMED HERE BECAUSE AN EARLIER VERSION OF THIS
 * COMMENT IMPLIED IT DID. It decides whether the ENDPOINT answers. It does not
 * limit how often, and it did not, until `citizen-lookup-is-guarded-and-
 * recorded`, keep a single field back from a caller who passed it. Two other
 * things now hold those halves, and a reader who needs them should go there:
 *
 *   - HOW OFTEN: `#[UserRateLimit]` on each lookup method of
 *     `ContactMomentController`. Nextcloud's own middleware answers 429 before
 *     the controller runs, so it is not a decision this class can get wrong.
 *   - WHICH FIELDS: {@see self::redactForCaller()} below, over
 *     {@see self::SENSITIVE_CONTACT_FIELDS}, mirroring the declaration on the
 *     `contactmoment` schema.
 *   - WHETHER IT IS RECORDED:
 *     {@see \OCA\Dossiq\Service\Kcc\CitizenLookupRecorder}, on both branches.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCP\IGroupManager;
use OCP\IUser;
use Throwable;

/**
 * Guards citizen-identifier lookups against the caller's KCC role.
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */
class CitizenLookupGuard {

	/**
	 * Groups whose members may resolve a citizen identifier.
	 *
	 * An empty intersection denies. The existence of these groups is never
	 * part of the decision — a group that does not exist simply matches
	 * nobody.
	 *
	 * ⚠️ TWO OF THESE FOUR NAMES ARE ASSUMPTIONS, NOT ESTABLISHED FACTS.
	 * Verified with `grep -w` across this repository at `cb63acad3`:
	 *
	 *   - `beheerders` and `admin` are ATTESTED — both are already used as
	 *     Nextcloud group names by `ProcessMiningController::ALLOWED_GROUPS`
	 *     and `AiAuditExportController::ALLOWED_GROUPS`.
	 *   - `kcc` and `klantcontact` are ASSUMED. Neither appears anywhere in
	 *     this codebase as a group name: `kcc` occurs only as a feature name,
	 *     spec slug and CSS class, and `klantcontact` only as a ZGW domain
	 *     term and a spec slug. They were chosen by the author of this class,
	 *     not derived from anything the app already does.
	 *
	 * The consequence is deliberate and it fails CLOSED: if a deployment's KCC
	 * group is called something else, its call-centre staff get HTTP 403 and an
	 * operator fixes it by creating the group or editing this constant. The
	 * alternative — leaving the endpoint open — returned a citizen's phone
	 * number and the free-text summary of every previous call to ANY
	 * authenticated account (finding PROC-IDOR-01, reproduced live).
	 *
	 * A wrong name here is a functional regression an operator can correct in
	 * a minute. A missing guard is a personal-data breach nobody notices.
	 *
	 * 🔧 DEPLOYMENT: this guard denies until the group exists. Create `kcc`
	 * (or rename the entry below to match your instance) and add the KCC
	 * handlers to it.
	 */
	private const ALLOWED_GROUPS = ['kcc', 'klantcontact', 'beheerders', 'admin'];

	/**
	 * The group that may read a contact moment's sensitive fields.
	 *
	 * The same group `sensitive-fields-declared` put the BSN behind, and the
	 * same group named in the `contactmoment` schema's own declaration. One
	 * group, two places, because one of the two is the rule OpenRegister
	 * enforces on the object and the other is the payload this app composed.
	 */
	public const SENSITIVE_GROUP = 'dossiq-sensitive';

	/**
	 * The fields of a contact moment only the sensitive group may read.
	 *
	 * These four are exactly what the class comment above says this guard
	 * protects: the caller's number, the citizen's identifier, the free-text
	 * summary of the call and its transcript. Each one also carries
	 * `authorization.read` on the `contactmoment` schema, so a direct
	 * OpenRegister read is refused for the same caller by the same group.
	 *
	 * 🔴 THIS LIST IS THE ONLY RULE, AND IT CAN ONLY REMOVE. It is not a second
	 * evaluator of the declaration: it holds no conditions, reads no schema and
	 * can never GRANT a field the declaration withholds. A field that belongs
	 * here and is missing is protected by the declaration on the object and
	 * unprotected in this payload, which is the direction to fail in.
	 *
	 * @var array<int, string>
	 */
	public const SENSITIVE_CONTACT_FIELDS = [
		'callerIdentification',
		'geidentificeerdeBurgerId',
		'summary',
		'transcript',
	];

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager The group manager.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Whether the given user may resolve citizen identifiers.
	 *
	 * @param IUser $user The authenticated user.
	 *
	 * @return bool True when the user is a KCC handler or a Nextcloud admin.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function isCitizenLookupAllowed(IUser $user): bool {
		$uid = $user->getUID();
		if ($uid === '') {
			return false;
		}

		try {
			foreach (self::ALLOWED_GROUPS as $group) {
				if ($this->groupManager->isInGroup($uid, $group) === true) {
					return true;
				}
			}

			return $this->groupManager->isAdmin($uid);
		} catch (Throwable $e) {
			// An unresolvable group check is not an authorization.
			return false;
		}
	}//end isCitizenLookupAllowed()

	/**
	 * Whether this caller may read a contact moment's sensitive fields.
	 *
	 * Membership only. A Nextcloud administrator is NOT waved through here,
	 * unlike the endpoint decision above: the four fields are personal data
	 * about a citizen, and being able to administer the instance is not a
	 * reason to read the transcript of their call.
	 *
	 * @param IUser $user The authenticated user.
	 *
	 * @return bool True when the caller holds the sensitive group.
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-a-citizen-lookup-answers-only-the-fields-the-caller-may-read-req-sec-cl-1
	 */
	public function maySeeSensitiveFields(IUser $user): bool {
		$uid = $user->getUID();
		if ($uid === '') {
			return false;
		}

		try {
			return $this->groupManager->isInGroup($uid, self::SENSITIVE_GROUP);
		} catch (Throwable $e) {
			// An unresolvable group check is not an authorization.
			return false;
		}
	}//end maySeeSensitiveFields()

	/**
	 * Take the sensitive fields out of a lookup payload the caller may not read.
	 *
	 * Handles the two shapes the lookup endpoints answer: a list of contact
	 * moments, and the voorblad, whose `recenteContactmomenten` is such a list.
	 * Anything else is returned untouched, because removing keys from a shape
	 * this does not recognise is how a redaction quietly eats a feature.
	 *
	 * @param IUser                $user    The authenticated caller.
	 * @param array<string, mixed> $payload The composed response.
	 *
	 * @return array<string, mixed> The payload, redacted when it has to be.
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-a-citizen-lookup-answers-only-the-fields-the-caller-may-read-req-sec-cl-1
	 */
	public function redactForCaller(IUser $user, array $payload): array {
		if ($this->maySeeSensitiveFields(user: $user) === true) {
			return $payload;
		}

		foreach (['contactmomenten', 'recenteContactmomenten'] as $key) {
			if (is_array(($payload[$key] ?? null)) === true) {
				$payload[$key] = array_map(
					static function (mixed $row): mixed {
						if (is_array($row) === false) {
							return $row;
						}

						return array_diff_key($row, array_flip(self::SENSITIVE_CONTACT_FIELDS));
					},
					$payload[$key]
				);
			}
		}

		return $payload;
	}//end redactForCaller()

	/**
	 * Which of the sensitive fields this caller was answered.
	 *
	 * Written into the audit row's `geraadpleegdeVelden`, so the record says
	 * what was revealed rather than only that something was.
	 *
	 * @param IUser $user The authenticated caller.
	 *
	 * @return array<int, string> The fields, [] when none were.
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	public function revealedFieldsFor(IUser $user): array {
		if ($this->maySeeSensitiveFields(user: $user) === false) {
			return [];
		}

		return self::SENSITIVE_CONTACT_FIELDS;
	}//end revealedFieldsFor()
}//end class
