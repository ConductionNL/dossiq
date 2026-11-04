<?php

/**
 * Dossiq SettingValueRules.
 *
 * The bounds and choices of the settings the admin tabs save, checked before
 * anything is written so a refused save changes nothing.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Settings
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
 * @spec openspec/changes/admin-tabs-carry-the-settings-board-blocks/specs/tenant-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Settings;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\TenantConfigurationService;

/**
 * Refuses a setting outside its bounds or choices.
 *
 * @spec openspec/changes/admin-tabs-carry-the-settings-board-blocks/specs/tenant-configuration/spec.md
 */
class SettingValueRules {
	public const RULE_UNKNOWN_ZONE = 'unknown-timezone';
	public const RULE_OUT_OF_RANGE = 'setting-out-of-range';

	/**
	 * The numeric settings and their upper bound; the lower bound is 0.
	 *
	 * @var array<string, array{max: float, whole: bool}>
	 */
	private const NUMBERS = [
		'queue_in_sight_days' => ['max' => 180.0, 'whole' => true],
		'queue_resident_wait_weight' => ['max' => 50.0, 'whole' => false],
	];

	/**
	 * The settings that take one of a fixed set of values.
	 *
	 * @var array<string, list<string>>
	 */
	public const CHOICES = [
		'date_format' => ['DD-MM-YYYY', 'YYYY-MM-DD', 'D MMMM YYYY'],
		'feature_new_case_page' => ['true', 'false'],
		'publication_date_rule' => ['', 'first-wednesday', 'decision-date'],
	];

	/**
	 * Refuse the first posted setting that breaks its rule.
	 *
	 * @param array<string, mixed> $data The settings as posted.
	 *
	 * @return void
	 *
	 * @throws RefusedException A 400 naming the zone, or the setting and what it allows.
	 *
	 * @spec openspec/changes/admin-tabs-carry-the-settings-board-blocks/specs/tenant-configuration/spec.md
	 */
	public function assertValid(array $data): void {
		$this->assertKnownZone(data: $data);

		foreach (self::NUMBERS as $key => $rule) {
			if (array_key_exists($key, $data) === true && $this->inBounds(value: $data[$key], max: $rule['max'], whole: $rule['whole']) === false) {
				throw new RefusedException(rule: self::RULE_OUT_OF_RANGE, sentence: $key.': 0 - '.$rule['max'], status: 400);
			}
		}

		foreach (self::CHOICES as $key => $allowed) {
			if (array_key_exists($key, $data) === true && in_array((string)$data[$key], $allowed, true) === false) {
				throw new RefusedException(rule: self::RULE_OUT_OF_RANGE, sentence: $key.': '.implode(', ', array_filter($allowed)), status: 400);
			}
		}
	}//end assertValid()

	/**
	 * Refuse a posted time zone dossiq cannot count in; an empty one unsets it.
	 *
	 * @param array<string, mixed> $data The settings as posted.
	 *
	 * @return void
	 *
	 * @throws RefusedException A 400 naming the zone.
	 */
	private function assertKnownZone(array $data): void {
		$zone = trim((string)($data['timezone'] ?? ''));
		if ($zone !== '' && in_array($zone, TenantConfigurationService::ALLOWED_TIMEZONES, true) === false) {
			throw new RefusedException(rule: self::RULE_UNKNOWN_ZONE, sentence: $zone, status: 400);
		}
	}//end assertKnownZone()

	/**
	 * Whether a posted number lies within 0 and its bound.
	 *
	 * @param mixed $value The posted value.
	 * @param float $max   The upper bound.
	 * @param bool  $whole Whether it must be a whole number.
	 *
	 * @return bool
	 */
	private function inBounds(mixed $value, float $max, bool $whole): bool {
		if (is_numeric($value) === false) {
			return false;
		}

		$number = (float)$value;
		if ($whole === true && floor($number) !== $number) {
			return false;
		}

		return $number >= 0.0 && $number <= $max;
	}//end inBounds()
}//end class
