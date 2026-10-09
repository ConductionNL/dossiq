<?php

/**
 * Dossiq case deadline follower.
 *
 * Where a case has a statutory term instance, the case `deadline` equals that
 * instance's `endDateCurrent` after every change to it: an extension, a pause,
 * a resumption (REQ-WTR-001). Without this the term engine and the case
 * disagreed after the first extension, and the case list counted down to a
 * date the engine no longer used.
 *
 * The case's `deadline` is readOnly, so this does not write it. It records the
 * date in {@see CaseDeadlineMirror} and saves the case with its stored values,
 * which passes OpenRegister's readOnly check because nothing readOnly changed.
 * {@see \OCA\Dossiq\Listener\CaseDeadlineListener} then takes the date on
 * that save's pre-persist event and writes it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
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
 * @spec openspec/specs/woo-case-type/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TermKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Make a case's deadline follow its statutory term instance.
 *
 * @spec openspec/specs/woo-case-type/spec.md
 */
class CaseDeadlineFollower {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Register and schema ids, and the object service.
	 * @param CaseDeadlineMirror $mirror          Where the date waits for the case save.
	 * @param LoggerInterface    $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseDeadlineMirror $mirror,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Bring the case's deadline in line with a statutory term instance just written.
	 *
	 * Other kinds of term (a planned end, an internal target, a phase term)
	 * are not the case's deadline and are left alone. A case that already
	 * carries the date is not saved again.
	 *
	 * @param array<string, mixed> $instance The stored term instance.
	 *
	 * @return bool True when the case was saved to follow the term.
	 *
	 * @spec openspec/specs/woo-case-type/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) TermKind::ofInstance() is a pure
	 * classifier over the instance array, with no state to inject.
	 */
	public function follow(array $instance): bool {
		if (TermKind::ofInstance(instance: $instance) !== TermKind::STATUTORY) {
			return false;
		}

		$caseId = $this->referenceId(value: ($instance['case'] ?? null));
		$endDate = substr(trim((string)($instance['endDateCurrent'] ?? '')), 0, 10);
		$location = $this->caseLocation();
		if ($caseId === '' || $endDate === '' || $location === null) {
			return false;
		}

		[$objectService, $register, $schema] = $location;

		try {
			$case = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $caseId);
			if ($case === null || substr((string)($case['deadline'] ?? ''), 0, 10) === $endDate) {
				return false;
			}

			$this->mirror->expect(
				caseId: $caseId,
				deadline: $endDate,
				deadlineBeforeRoll: substr(trim((string)($instance['endDateBeforeRoll'] ?? '')), 0, 10)
			);

			unset($case['@self']);
			$this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $schema, object: $case, uuid: $caseId);
		} catch (Throwable $e) {
			$this->mirror->forget(caseId: $caseId);
			$this->logger->warning(
				'Dossiq termijn: the case deadline could not follow its term',
				['case' => $caseId, 'endDateCurrent' => $endDate, 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end follow()

	/**
	 * Where cases are stored: the object service, register and case schema.
	 *
	 * @return array{0: object, 1: string, 2: string}|null The three, or null when any is unconfigured.
	 */
	private function caseLocation(): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		return [$objectService, $register, $schema];
	}//end caseLocation()

	/**
	 * The id a reference carries, whether it arrived as a uuid or as a row.
	 *
	 * @param mixed $value A uuid string, or an array carrying `id`/`uuid`.
	 *
	 * @return string The id, or the empty string.
	 */
	private function referenceId(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? ''));
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end referenceId()
}//end class
