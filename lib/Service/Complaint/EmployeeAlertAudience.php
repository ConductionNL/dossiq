<?php

/**
 * Dossiq EmployeeAlertAudience.
 *
 * Decides who may read the employee complaint threshold alerts: the roles the
 * complaint-management spec names (the HR coordinator and the department head),
 * held as Nextcloud groups. Which groups those are is configuration, read from
 * the app config key `complaint_employee_alert_groups` (comma separated).
 *
 * Membership only: a Nextcloud administrator is not waved through, because the
 * alert is about an employee and administering the instance is not a reason to
 * read it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Complaint
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
 * @spec openspec/specs/complaint-management/spec.md#scenario-employee-complaint-threshold-alert
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Complaint;

use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Throwable;

/**
 * Group-membership gate for the employee complaint threshold alerts.
 *
 * @spec openspec/specs/complaint-management/spec.md#scenario-employee-complaint-threshold-alert
 */
class EmployeeAlertAudience {

	/**
	 * App config key holding the comma-separated audience groups.
	 */
	public const CONFIG_KEY = 'complaint_employee_alert_groups';

	/**
	 * Groups used when the key is not set: the HR coordinator and department head roles.
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_GROUPS = ['hr-coordinators', 'department-heads'];

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager Resolves the caller's membership.
	 * @param IAppConfig    $appConfig    Holds the configured audience groups.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The configured audience groups, blanks removed.
	 *
	 * @return array<int, string> Group ids.
	 *
	 * @spec openspec/specs/complaint-management/spec.md#scenario-employee-complaint-threshold-alert
	 */
	public function groups(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, implode(',', self::DEFAULT_GROUPS));

		return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $group): bool => $group !== ''));
	}//end groups()

	/**
	 * Whether this user may read the employee threshold alerts.
	 *
	 * @param string $uid The caller's user id, '' for no session.
	 *
	 * @return bool True when the caller is in one of the audience groups.
	 *
	 * @spec openspec/specs/complaint-management/spec.md#scenario-employee-complaint-threshold-alert
	 */
	public function mayReadEmployeeAlerts(string $uid): bool {
		if ($uid === '') {
			return false;
		}

		try {
			foreach ($this->groups() as $group) {
				if ($this->groupManager->isInGroup($uid, $group) === true) {
					return true;
				}
			}
		} catch (Throwable $e) {
			// An unresolvable group check is not an authorization.
			return false;
		}

		return false;
	}//end mayReadEmployeeAlerts()
}//end class
