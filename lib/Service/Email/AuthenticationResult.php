<?php

/**
 * Dossiq Sender Authentication Results
 *
 * The four values an authentication check can answer, and the one rule that
 * matters about them: `unavailable` is not `pass`.
 *
 * A check nobody made and a check that succeeded are different facts, and the
 * only reason to keep them apart in code is that they are indistinguishable
 * once they are collapsed. An absent `Authentication-Results` header says
 * nothing about the sender; reporting it as a pass would let a forged bezwaar
 * read as an authenticated one (design D-6).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

/**
 * The vocabulary of an authentication check.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
final class AuthenticationResult {

	/**
	 * The check ran and the sender is who they claim.
	 */
	public const PASS = 'pass';

	/**
	 * The check ran and the sender is not who they claim.
	 */
	public const FAIL = 'fail';

	/**
	 * The check ran and the domain publishes no policy to check against.
	 */
	public const NONE = 'none';

	/**
	 * No check was made, so nothing is known.
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Every value, in the order they are reported.
	 *
	 * @var string[]
	 */
	public const ALL = [
		self::PASS,
		self::FAIL,
		self::NONE,
		self::UNAVAILABLE,
	];

	/**
	 * Coerce whatever a mail server wrote into one of the four values.
	 *
	 * Anything unrecognised is `unavailable`, never `pass`. A receiving server
	 * may write `softfail`, `permerror`, `temperror`, `neutral` or a value no
	 * RFC lists, and every one of them means dossiq does not know.
	 *
	 * @param string $raw The raw token from the header.
	 *
	 * @return string One of the four values.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public static function fromToken(string $raw): string {
		$token = strtolower(trim($raw));
		if ($token === self::PASS || $token === self::FAIL || $token === self::NONE) {
			return $token;
		}

		// 🔴 A SOFTFAIL IS A FAIL, NOT AN UNKNOWN. The sending domain published
		// a policy and this message is outside it; the only thing "soft" about
		// it is that the domain asked receivers not to reject on it. Folding it
		// into `unavailable` would file a message whose domain explicitly
		// disowned it alongside one that was never checked.
		if ($token === 'softfail' || $token === 'hardfail') {
			return self::FAIL;
		}

		return self::UNAVAILABLE;
	}//end fromToken()

	/**
	 * Whether a result is one the four values name.
	 *
	 * @param string $value The candidate.
	 *
	 * @return boolean True when it is a known result.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public static function isKnown(string $value): bool {
		return in_array($value, self::ALL, true);
	}//end isKnown()

	/**
	 * Whether a result should worry a case type.
	 *
	 * `fail` only. `none` means the domain publishes no policy, which is most
	 * of the Dutch internet, and `unavailable` means dossiq did not look. Both
	 * are absences of evidence and neither is evidence of forgery; treating
	 * them as failures would quarantine every message from a small gemeente
	 * that never set up SPF.
	 *
	 * @param string $value The result.
	 *
	 * @return boolean True when the check actively failed.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public static function isFailure(string $value): bool {
		return $value === self::FAIL;
	}//end isFailure()
}//end class
