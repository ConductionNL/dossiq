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

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TermKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Which statutory term decides a case's deadline, and the write that applies it.
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) {@see TermKind} is a vocabulary: four
 * constants and four pure predicates over an array, with no state, no I/O and
 * nothing to inject. The same reasoning, word for word, as on CaseTermsService.
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
	 * Whether an instance is the kind that decides a case's deadline.
	 *
	 * @param array<string, mixed> $instance The instance.
	 *
	 * @return bool True for a statutory term, including one written before kinds existed.
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-case-deadline-is-the-statutory-terms-current-end-req-ote-01
	 */
	public function isStatutory(array $instance): bool {
		return TermKind::ofInstance($instance) === TermKind::STATUTORY;
	}//end isStatutory()

	/**
	 * Bring the case in line after this instance was saved, when it is statutory.
	 *
	 * The term engine's one call: a planned, internal or phase term never
	 * decides the case's deadline, so it costs nothing.
	 *
	 * @param array<string, mixed>|null $instance The instance as stored, or null when the store refused.
	 *
	 * @return bool True when the case was written.
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-case-deadline-is-the-statutory-terms-current-end-req-ote-01
	 */
	public function followInstance(?array $instance): bool {
		if ($instance === null || $this->isStatutory(instance: $instance) === false) {
			return false;
		}

		return $this->follow(caseId: trim((string)($instance['case'] ?? '')));
	}//end followInstance()

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
	 * Null means there is no statutory term, and the case keeps its
	 * fallback. Terms that could not be read are a refusal, not a null.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return string|null The `Y-m-d` deadline, or null.
	 *
	 * @throws RefusedException When the terms of the case could not be read.
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-case-deadline-is-the-statutory-terms-current-end-req-ote-01
	 */
	public function deadlineFor(string $caseId): ?string {
		if (trim($caseId) === '') {
			return null;
		}

		// An unreadable store REFUSES (TermInstanceStore::allForCase()); it is
		// not read as "no statutory term". The caller decides what a refusal
		// means for the save it is in.
		$deciding = self::decidingInstance(instances: $this->store->allForCase(caseId: $caseId));
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
		try {
			$deadline = $this->deadlineFor(caseId: $caseId);
			$case = $this->storedCase(caseId: $caseId);
		} catch (Throwable $e) {
			// The term itself is saved, and it is the source. A case whose copy
			// lags is put right by its next save and by the repair step.
			$this->logger->warning(
				'Dossiq termijn: the case or its terms could not be read to follow its statutory term',
				['case' => $caseId, 'error' => $e->getMessage()]
			);

			return false;
		}

		if ($deadline === null || $deadline === '' || $case === null) {
			return false;
		}

		$stored = substr(trim((string)($case['deadline'] ?? '')), 0, 10);
		$mirrored = substr(trim((string)($case[self::FIELD] ?? '')), 0, 10);
		if ($stored === $deadline && $mirrored === $deadline) {
			return false;
		}

		return $this->write(caseId: $caseId, deadline: $deadline);
	}//end follow()

	/**
	 * The stored case, or null when it is not there. A failed read throws.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>|null The case.
	 */
	private function storedCase(string $caseId): ?array {
		[$objectService, $register, $schema] = $this->caseStore();
		if ($objectService === null) {
			return null;
		}

		return $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $caseId
		);
	}//end storedCase()

	/**
	 * Write the statutory end date onto the case.
	 *
	 * @param string $caseId   The case uuid.
	 * @param string $deadline The `Y-m-d` date.
	 *
	 * @return bool True when the write went through.
	 */
	private function write(string $caseId, string $deadline): bool {
		[$objectService, $register, $schema] = $this->caseStore();
		if ($objectService === null) {
			return false;
		}

		try {
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
		}

		return true;
	}//end write()

	/**
	 * The object service, register and case schema, or a null service when unconfigured.
	 *
	 * @return array{0: object|null, 1: string, 2: string} The three.
	 */
	private function caseStore(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($register === '' || $schema === '') {
			$objectService = null;
		}

		return [$objectService, $register, $schema];
	}//end caseStore()
}//end class
