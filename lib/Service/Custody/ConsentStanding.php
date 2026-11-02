<?php

/**
 * Where one recorded consent stands at a moment.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Custody
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
 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Custody;

use DateTimeImmutable;
use Throwable;

/**
 * Reads a consent's withdrawal and dates, and places a moment against them.
 *
 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
class ConsentStanding {

	/**
	 * A consent that covers the moment.
	 *
	 * @var string
	 */
	public const COVERS = 'covers';

	/**
	 * A consent that was withdrawn.
	 *
	 * @var string
	 */
	public const WITHDRAWN = 'withdrawn';

	/**
	 * A consent that ended before the moment.
	 *
	 * @var string
	 */
	public const LAPSED = 'lapsed';

	/**
	 * A consent that does not count: not started yet, or with an unreadable date.
	 *
	 * @var string
	 */
	public const NOT_COUNTED = 'not-counted';

	/**
	 * Where one consent stands at the moment.
	 *
	 * A consent whose start or end date is set but cannot be read does not
	 * count: it neither covers nor names a lapse.
	 *
	 * @param array<string, mixed> $consent The recorded consent.
	 * @param DateTimeImmutable    $moment  The moment being asked about.
	 *
	 * @return string One of COVERS, WITHDRAWN, LAPSED, or NOT_COUNTED.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	public function standingOf(array $consent, DateTimeImmutable $moment): string {
		if ($this->isWithdrawn(consent: $consent) === true) {
			return self::WITHDRAWN;
		}

		$window = $this->windowOf(consent: $consent);
		if ($window === null) {
			return self::NOT_COUNTED;
		}

		return $this->placeIn(window: $window, moment: $moment);
	}//end standingOf()

	/**
	 * Whether the consent is withdrawn, in any of the forms the register can hold.
	 *
	 * @param array<string, mixed> $consent The recorded consent.
	 *
	 * @return bool True for true, 1, "1" and "true" (any case).
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	private function isWithdrawn(array $consent): bool {
		$value = ($consent['withdrawn'] ?? false);
		if (is_string($value) === true) {
			$value = strtolower(trim($value));
		}

		return in_array($value, [true, 1, '1', 'true'], true);
	}//end isWithdrawn()

	/**
	 * The start and end of a consent, or null when either is set but unreadable.
	 *
	 * @param array<string, mixed> $consent The recorded consent.
	 *
	 * @return array{0: DateTimeImmutable|null, 1: DateTimeImmutable|null}|null Start and end, null for an open side.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	private function windowOf(array $consent): ?array {
		$grantedOn = $this->dateOf(value: (string)($consent['grantedDate'] ?? ''));
		$expiresOn = $this->dateOf(value: (string)($consent['validTo'] ?? ''));
		if ($grantedOn === false || $expiresOn === false) {
			return null;
		}

		return [$grantedOn, $expiresOn];
	}//end windowOf()

	/**
	 * A recorded date.
	 *
	 * @param string $value The recorded date.
	 *
	 * @return DateTimeImmutable|false|null The date, null when none is set, false when it cannot be read.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	public function dateOf(string $value): DateTimeImmutable|false|null {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $e) {
			return false;
		}
	}//end dateOf()

	/**
	 * Whether the moment falls inside a readable window.
	 *
	 * @param array{0: DateTimeImmutable|null, 1: DateTimeImmutable|null} $window Start and end, null for an open side.
	 * @param DateTimeImmutable                                           $moment The moment being asked about.
	 *
	 * @return string COVERS, LAPSED, or NOT_COUNTED when the window has not opened yet.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
	 */
	private function placeIn(array $window, DateTimeImmutable $moment): string {
		[$grantedOn, $expiresOn] = $window;
		if ($grantedOn !== null && $grantedOn > $moment) {
			return self::NOT_COUNTED;
		}

		if ($expiresOn !== null && $expiresOn < $moment) {
			return self::LAPSED;
		}

		return self::COVERS;
	}//end placeIn()
}//end class
