<?php

/**
 * The instance's queue urgency settings, read from the app config.
 *
 * Four keys an administrator sets on the dossiq admin settings page, saved
 * through the app's own admin-guarded settings write. This class only reads
 * them, and reads them leniently through UrgencyProfile's constructor.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
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
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use OCA\Dossiq\Service\SettingsService;

/**
 * Reads the admin's queue thresholds and weights.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
class QueueUrgencySettings {

	public const KEY_CRITICAL_DAYS = 'queue_critical_days';
	public const KEY_WARNING_DAYS = 'queue_warning_days';
	public const KEY_PRIORITY_WEIGHT = 'queue_priority_weight';
	public const KEY_IDLE_WEIGHT = 'queue_idle_weight';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings The app config reader.
	 */
	public function __construct(
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * The instance profile: the admin's numbers, or the defaults.
	 *
	 * @return UrgencyProfile The profile every case starts from.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function profile(): UrgencyProfile {
		return new UrgencyProfile(
			criticalDays: $this->settings->getConfigValue(self::KEY_CRITICAL_DAYS, ''),
			warningDays: $this->settings->getConfigValue(self::KEY_WARNING_DAYS, ''),
			priorityWeight: $this->settings->getConfigValue(self::KEY_PRIORITY_WEIGHT, ''),
			idleWeight: $this->settings->getConfigValue(self::KEY_IDLE_WEIGHT, ''),
		);
	}//end profile()
}//end class
