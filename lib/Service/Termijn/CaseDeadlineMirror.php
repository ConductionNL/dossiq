<?php

/**
 * Dossiq case deadline mirror.
 *
 * The one rule that decides a case's `deadline` while the case has a
 * statutory term: it is that term's current end date. The list, the cards,
 * the week strip and every count read `deadline`; the case page reads the
 * term. Before this class they were computed apart and disagreed (one term
 * engine, Ruben 2026-10-09).
 *
 * Two callers. {@see \OCA\Dossiq\Service\TermijnService} calls `follow()`
 * after it saves a statutory instance, which writes the date onto the case.
 * {@see \OCA\Dossiq\Listener\CaseDeadlineFollowsTermListener} calls
 * `deadlineFor()` inside every save of a case, which keeps that date against
 * the OpenRegister calculation and against a stale payload.
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
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TermKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Which statutory term decides a case's deadline, and the write that applies it.
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */
class CaseDeadlineMirror {

	use SearchesObjects;

	/**
	 * The case property the write-back sets.
	 *
	 * NOT `deadline` itself. `deadline` is declared readOnly, and OpenRegister
	 * refuses an update that changes a readOnly value, so a patch naming it
	 * would be answered 422. This field is ordinary; changing it is what makes
	 * the save happen, and the pre-persist listener then writes `deadline`
	 * inside that save, after validation, where readOnly does not apply.
	 */
	public const FIELD = 'statutoryDeadline';

	/**
	 * Constructor.
	 *
	 * @param SettingsService   $settingsService Register and schema ids, and the object service.
	 * @param TermInstanceStore $store           Where the term instances are read.
	 * @param LoggerInterface   $logger          Where a write that could not be made is noted.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly TermInstanceStore $store,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The statutory instance that decides, out of a case's instances.
	 *
	 * The newest statutory instance that is not completed. When every one is
	 * completed, the newest: its date is the date the case was decided
	 * against, and a list that dropped it would show the calculated date
	 * instead, which is the disagreement this class exists to end. Planned,
	 * internal and phase instances never decide.
	 *
	 * @param array<int, array<string, mixed>> $instances The case's instances, newest start first.
	 *
	 * @return array<string, mixed>|null The deciding instance, or null when there is no statutory one.
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-case-deadline-is-the-statutory-terms-current-end-req-ote-01
	 */
	public static function decidingInstance(array $instances): ?array {
		$newest = null;
		foreach ($instances as $instance) {
			if (TermKind::ofInstance($instance) !== TermKind::STATUTORY) {
				continue;
			}

			if (self::endOf(instance: $instance) === '') {
				continue;
			}

			if ((string)($instance['status'] ?? '') !== 'completed') {
				return $instance;
			}

			$newest = ($newest ?? $instance);
		}

		return $newest;
	}//end decidingInstance()

	/**
	 * The end date of an instance as `Y-m-d`, or the empty string.
	 *
	 * @param array<string, mixed> $instance The instance.
	 *
	 * @return string The current end date.
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-case-deadline-is-the-statutory-terms-current-end-req-ote-01
	 */
	public static function endOf(array $instance): string {
		$end = trim((string)($instance['endDateCurrent'] ?? ($instance['endDateCalculated'] ?? '')));

		return substr($end, 0, 10);
	}//end endOf()

	/**
	 * The deadline a case's statutory term decides, or null.
	 *
	 * Null means "leave the case as it is": there is no statutory term, or
	 * the terms could not be read. The second is not turned into the first
	 * silently; it is logged, and the save goes on with what it carried.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return string|null The `Y-m-d` deadline, or null.
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-case-deadline-is-the-statutory-terms-current-end-req-ote-01
	 */
	public function deadlineFor(string $caseId): ?string {
		if (trim($caseId) === '') {
			return null;
		}

		try {
			$instances = $this->store->allForCase(caseId: $caseId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq termijn: the terms of a case could not be read, so its deadline was left as it was',
				['case' => $caseId, 'error' => $e->getMessage()]
			);

			return null;
		}

		$deciding = self::decidingInstance(instances: $instances);
		if ($deciding === null) {
			return null;
		}

		return self::endOf(instance: $deciding);
	}//end deadlineFor()

	/**
	 * Bring the stored case in line with its statutory term.
	 *
	 * Reads the case and writes only when it disagrees, so calling this after
	 * every save of an instance costs one read when nothing moved.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return bool True when the case was written.
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-case-deadline-is-the-statutory-terms-current-end-req-ote-01
	 */
	public function follow(string $caseId): bool {
		$deadline = $this->deadlineFor(caseId: $caseId);
		if ($deadline === null || $deadline === '') {
			return false;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return false;
		}

		try {
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId
			);
			if ($case === null) {
				return false;
			}

			$stored = substr(trim((string)($case['deadline'] ?? '')), 0, 10);
			$mirrored = substr(trim((string)($case[self::FIELD] ?? '')), 0, 10);
			if ($stored === $deadline && $mirrored === $deadline) {
				return false;
			}

			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId,
				changes: [self::FIELD => $deadline]
			);
		} catch (Throwable $e) {
			// The term itself is saved, and it is the source. A case whose copy
			// lags is repaired on its next save by the listener and by the
			// repair step, so this is a warning rather than a failure of the
			// act a handler just took.
			$this->logger->warning(
				'Dossiq termijn: the case deadline could not follow its statutory term',
				['case' => $caseId, 'deadline' => $deadline, 'error' => $e->getMessage()]
			);

			return false;
		}//end try

		return true;
	}//end follow()
}//end class
