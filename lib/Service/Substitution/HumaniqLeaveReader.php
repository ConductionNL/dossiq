<?php

/**
 * Dossiq HumaniqLeaveReader.
 *
 * Reads humaniq's approved leave for one handler, so a substitution's period
 * can follow the absence that caused it. Absence itself stays humaniq's: this
 * reader never writes, never stores a leave record of its own, and treats
 * humaniq being absent as "no leave known" rather than as a failure (company
 * ADR-022, ADR-058).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Substitution
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
 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Substitution;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Answers whether a handler is on approved leave on a given day, per humaniq.
 *
 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
 */
class HumaniqLeaveReader {
	use SearchesObjects;

	/**
	 * The app whose leave this reads. Its id landed with the fleet rename, and
	 * it is a runtime lookup: an id nothing answers to makes this silently
	 * answer "no leave", not fail.
	 */
	private const HUMANIQ_APP_ID = 'humaniq';

	/**
	 * humaniq's register slug, overridable per instance.
	 *
	 * @var string
	 */
	private const REGISTER_KEY = 'humaniq_register';

	/**
	 * Its default, matching humaniq's own shipped bundle.
	 *
	 * @var string
	 */
	private const REGISTER_DEFAULT = 'humaniq';

	/**
	 * The schema holding the employee records leave is requested against.
	 *
	 * @var string
	 */
	private const EMPLOYEE_SCHEMA = 'Employee';

	/**
	 * The schema holding the leave requests themselves.
	 *
	 * @var string
	 */
	private const LEAVE_SCHEMA = 'LeaveRequest';

	/**
	 * How many of a handler's approved leave requests are read at most.
	 *
	 * ADR-058: the query is bounded. One handler's approved leave over a
	 * working life is a short list, and the alternative to a bound is a read
	 * that grows without one ever noticing.
	 */
	private const LEAVE_LIMIT = 50;

	/**
	 * Per-request answers, keyed "userId|day", so resolving five substitutions
	 * on one page load does not issue five pairs of queries.
	 *
	 * @var array<string, array<string, string>|null>
	 */
	private array $cache = [];

	/**
	 * Whether the "humaniq is not installed" line has been logged this request.
	 *
	 * @var boolean
	 */
	private bool $absenceLogged = false;

	/**
	 * Constructor.
	 *
	 * @param IAppManager     $appManager      Tells whether humaniq is here at all.
	 * @param SettingsService $settingsService The OpenRegister ObjectService bridge.
	 * @param LoggerInterface $logger          The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The approved leave period covering a day for one handler, if any.
	 *
	 * Two bounded reads: the employee record carrying that Nextcloud account
	 * (humaniq's leave is requested by an Employee domain object, never by a
	 * user id, per company ADR-046), then that employee's approved leave.
	 *
	 * Every way this can fail to answer resolves to null, which the caller
	 * reads as "no leave known" and falls back to the substitution's typed
	 * dates. That is deliberate: a substitution must not evaporate because the
	 * HR app is missing, unconfigured or briefly unreachable.
	 *
	 * @param string $userId The handler's Nextcloud user id.
	 * @param string $day    The reference day (`Y-m-d`).
	 *
	 * @return array<string, string>|null `['startDate' => ..., 'endDate' => ...]`, or null.
	 *
	 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
	 */
	public function approvedLeaveCovering(string $userId, string $day): ?array {
		$userId = trim($userId);
		if ($userId === '' || $day === '') {
			return null;
		}

		$cacheKey = $userId . '|' . $day;
		if (array_key_exists($cacheKey, $this->cache) === true) {
			return $this->cache[$cacheKey];
		}

		$leave = $this->readLeave(userId: $userId, day: $day);
		$this->cache[$cacheKey] = $leave;

		return $leave;
	}//end approvedLeaveCovering()

	/**
	 * The uncached read behind {@see self::approvedLeaveCovering()}.
	 *
	 * @param string $userId The handler's Nextcloud user id.
	 * @param string $day    The reference day (`Y-m-d`).
	 *
	 * @return array<string, string>|null The covering period, or null.
	 *
	 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
	 */
	private function readLeave(string $userId, string $day): ?array {
		if ($this->appManager->isInstalled(self::HUMANIQ_APP_ID) === false) {
			if ($this->absenceLogged === false) {
				$this->absenceLogged = true;
				$this->logger->info(
					'Dossiq: humaniq is not installed, so a substitution keeps its typed dates',
					['app' => self::HUMANIQ_APP_ID]
				);
			}

			return null;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = (string)$this->settingsService->getConfigValue(
			self::REGISTER_KEY,
			self::REGISTER_DEFAULT
		);
		if ($register === '') {
			return null;
		}

		try {
			$employees = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: self::EMPLOYEE_SCHEMA,
				filters: ['nextcloudUserId' => $userId, '_limit' => 1]
			);
			$employeeId = (string)($employees[0]['id'] ?? ($employees[0]['uuid'] ?? ''));
			if ($employeeId === '') {
				return null;
			}

			$requests = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: self::LEAVE_SCHEMA,
				filters: [
					'employeeId' => $employeeId,
					'status' => 'approved',
					'_limit' => self::LEAVE_LIMIT,
				]
			);
		} catch (\Throwable $e) {
			// The HR app answering badly is not this app's failure to report to
			// a case handler; it is a reason to fall back to the typed dates.
			$this->logger->warning(
				'Dossiq: could not read humaniq leave for a substitution',
				['user' => $userId, 'error' => $e->getMessage()]
			);

			return null;
		}//end try

		return $this->covering(requests: $requests, day: $day);
	}//end readLeave()

	/**
	 * The first read request whose period contains the day.
	 *
	 * @param array<int, array<string, mixed>> $requests The approved requests.
	 * @param string                           $day      The reference day (`Y-m-d`).
	 *
	 * @return array<string, string>|null The covering period, or null.
	 *
	 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
	 */
	private function covering(array $requests, string $day): ?array {
		foreach ($requests as $request) {
			// Re-checked here rather than trusted to the filter: a search that
			// silently ignores an unknown field answers the whole collection,
			// and an unapproved request would then set a period nobody granted.
			if ((string)($request['status'] ?? '') !== 'approved') {
				continue;
			}

			$start = substr((string)($request['startDate'] ?? ''), 0, 10);
			$end = substr((string)($request['endDate'] ?? ''), 0, 10);
			if ($start === '' || $end === '') {
				continue;
			}

			if ($day >= $start && $day <= $end) {
				return ['startDate' => $start, 'endDate' => $end];
			}
		}//end foreach

		return null;
	}//end covering()
}//end class
