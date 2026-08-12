<?php

/**
 * Test stub mirroring OpenRegister's GuardResult value object.
 *
 * Carries the allow/deny verdict returned by a lifecycle guard. Mirrors the
 * runtime OCA\OpenRegister\Lifecycle\GuardResult so procest's guards can be
 * exercised by the unit suite without the OpenRegister app on the classpath.
 * Autoloaded via the OCA\OpenRegister\ → tests/Stubs/ map (autoload-dev).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Stub
 * @package  OCA\OpenRegister\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

/**
 * Allow / deny verdict from a guard, optionally with a deny message.
 */
final class GuardResult {

	/**
	 * Construct a verdict — use the static factories.
	 *
	 * @param bool $allowed Whether the transition should be allowed.
	 * @param string|null $message Optional deny message.
	 */
	private function __construct(
		private readonly bool $allowed,
		private readonly ?string $message,
	) {
	}//end __construct()

	/**
	 * Allow the transition.
	 *
	 * @return self Allow verdict instance.
	 */
	public static function allow(): self {
		return new self(allowed: true, message: null);
	}//end allow()

	/**
	 * Deny the transition with a user-visible message.
	 *
	 * @param string $message Human-readable reason surfaced to the caller.
	 *
	 * @return self Deny verdict instance.
	 */
	public static function deny(string $message): self {
		return new self(allowed: false, message: $message);
	}//end deny()

	/**
	 * Read whether the verdict allows the transition.
	 *
	 * @return bool True when allowed, false when denied.
	 */
	public function isAllowed(): bool {
		return $this->allowed;
	}//end isAllowed()

	/**
	 * Read the deny message, if any.
	 *
	 * @return string|null Deny message, or null when allowed.
	 */
	public function getMessage(): ?string {
		return $this->message;
	}//end getMessage()
}//end class
