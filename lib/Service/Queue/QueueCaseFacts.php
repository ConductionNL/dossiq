<?php

/**
 * What the work queue reads about one case before it scores it
 *
 * Three facts: the deadline the case is judged on (its nearest open term, or
 * its own deadline), the last moment anybody worked on it, and the urgency
 * profile of its case type.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
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
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * The deadline, last activity and urgency profile of a case in the queue.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
class QueueCaseFacts {
	use SearchesObjects;

	/**
	 * The term statuses whose end date still counts for urgency.
	 *
	 * @var array<int, string>
	 */
	private const OPEN_TERM_STATUSES = ['lopend', 'verlengd', 'paused', 'exceeded'];

	/**
	 * The case type rows read while scoring this request's queue, by id.
	 *
	 * Every case of a type asked for the same row; a queue is a read, so
	 * nothing in it changes a case type.
	 *
	 * @var array<string, array|null>
	 */
	private array $caseTypeRows = [];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service (schema config).
	 * @param CaseJournal $journal Reads the case's own record of acts.
	 * @param CaseDateNormaliser $dates The one date write path.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseJournal $journal,
		private readonly CaseDateNormaliser $dates,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The last activity on a case: the latest of its last save and the newest
	 * entry in its journal, falling back to its start date.
	 *
	 * `SilenceCloseService::lastActivity()` reads the journal first on
	 * purpose, because its own warning write touches the case. The queue
	 * writes nothing to a case, so the latest of the two is the honest answer
	 * here: an edit that wrote no journal entry is still somebody working on
	 * the case.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return string|null The moment, or null when the case carries none.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function lastActivityOf(array $case): ?string {
		$latest = null;
		foreach ($this->activityMoments(case: $case) as $moment) {
			if ($latest === null || $moment > $latest) {
				$latest = $moment;
			}
		}

		if ($latest !== null) {
			return $latest->format('Y-m-d');
		}

		$startDate = (string)($case['startDate'] ?? '');
		if ($startDate === '') {
			return null;
		}

		return $startDate;
	}//end lastActivityOf()

	/**
	 * Every moment a case records activity: its last save and each journal entry.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return array<int, DateTimeImmutable> The moments that parse.
	 */
	private function activityMoments(array $case): array {
		$candidates = [($case['@self']['updated'] ?? null)];
		foreach ($this->journal->entries(case: $case) as $entry) {
			$candidates[] = ($entry['at'] ?? null);
		}

		$moments = [];
		foreach ($candidates as $candidate) {
			if (is_string($candidate) === false || $candidate === '') {
				continue;
			}

			$moment = $this->dates->tryParse($candidate);
			if ($moment !== null) {
				$moments[] = $moment;
			}
		}

		return $moments;
	}//end activityMoments()

	/**
	 * The case type id a case row names, whether as a uuid or an object.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return string The id, or '' when the case names none.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function caseTypeIdOf(array $case): string {
		$caseType = ($case['caseType'] ?? '');
		if (is_array($caseType) === true) {
			$caseType = ($caseType['id'] ?? ($caseType['uuid'] ?? ''));
		}

		if (is_string($caseType) === false) {
			return '';
		}

		return $caseType;
	}//end caseTypeIdOf()

	/**
	 * The profile for the cases of one case type: the instance profile with
	 * the type's own thresholds where it sets them.
	 *
	 * A case type that cannot be read scores with the instance profile, and
	 * says so in the log: a queue that refuses to rank because one case type
	 * went missing helps nobody.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register slug/id.
	 * @param string $caseTypeId The case type id, or ''.
	 * @param UrgencyProfile $profile The instance profile.
	 *
	 * @return UrgencyProfile The profile for that type.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function profileForCaseType(object $objectService, string $register, string $caseTypeId, UrgencyProfile $profile): UrgencyProfile {
		$caseTypeSchema = (string)$this->settingsService->getConfigValue('case_type_schema');
		if ($caseTypeId === '' || $caseTypeSchema === '') {
			return $profile;
		}

		return $this->withThresholdsOf(
			profile: $profile,
			caseType: ['objectService' => $objectService, 'register' => $register, 'schema' => $caseTypeSchema, 'id' => $caseTypeId]
		);
	}//end profileForCaseType()

	/**
	 * A case type row, read once per request.
	 *
	 * @param array{objectService: object, register: string, schema: string, id: string} $caseType Where the row lives.
	 *
	 * @return array<string, mixed>|null The row, or null when absent.
	 */
	private function caseTypeRow(array $caseType): ?array {
		$key = $caseType['register'].'|'.$caseType['schema'].'|'.$caseType['id'];
		if (array_key_exists($key, $this->caseTypeRows) === false) {
			$this->caseTypeRows[$key] = $this->findObjectAsArray(
				objectService: $caseType['objectService'],
				register: $caseType['register'],
				schema: $caseType['schema'],
				id: $caseType['id']
			);
		}

		return $this->caseTypeRows[$key];
	}//end caseTypeRow()

	/**
	 * The profile with the thresholds of the case type it reads; the profile as it is when the read fails or finds none.
	 *
	 * @param UrgencyProfile                                                         $profile  The instance profile.
	 * @param array{objectService: object, register: string, schema: string, id: string} $caseType Where the case type is
	 *                                                                                          stored, and its id.
	 *
	 * @return UrgencyProfile The profile for that type.
	 */
	private function withThresholdsOf(UrgencyProfile $profile, array $caseType): UrgencyProfile {
		try {
			$row = $this->caseTypeRow(caseType: $caseType);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'WorkQueue: case type read failed, scoring with the instance thresholds',
				['caseType' => $caseType['id'], 'error' => $e->getMessage()]
			);
			return $profile;
		}

		if ($row === null) {
			return $profile;
		}

		return $profile->withCaseTypeThresholds(
			criticalDays: ($row['queueCriticalDays'] ?? null),
			warningDays: ($row['queueWarningDays'] ?? null)
		);
	}//end withThresholdsOf()

	/**
	 * Resolve a case's nearest active termijn deadline, falling back to the
	 * case's own computed `deadline` field when no active termijn instance
	 * tracks it (or termijn tracking is not configured).
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register slug/id.
	 * @param string $caseId Case id.
	 * @param string $fallback The case's own `deadline` field value.
	 *
	 * @return string|null The resolved deadline, or null when none available.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function resolveCaseDeadline(object $objectService, string $register, string $caseId, string $fallback): ?string {
		$nearest = $this->nearestActiveTermDeadline(location: [$objectService, $register], caseId: $caseId);
		if ($nearest !== null) {
			return $nearest;
		}

		if ($fallback !== '') {
			return $fallback;
		}

		return null;
	}//end resolveCaseDeadline()

	/**
	 * Find the nearest `endDateCurrent` among a case's open term instances
	 * (running, extended, paused or exceeded), or null when termijn tracking
	 * is not configured, the case has none, or the lookup fails.
	 *
	 * @param array{0: object, 1: string} $location OpenRegister ObjectService and the register slug/id.
	 * @param string                      $caseId   Case id.
	 *
	 * @return string|null The nearest active deadline, or null.
	 *
	 * @spec openspec/specs/my-work/spec.md#requirement-the-urgency-score-reads-every-open-term-req-ote-06
	 */
	private function nearestActiveTermDeadline(array $location, string $caseId): ?string {
		$termSchema = (string)$this->settingsService->getConfigValue('termijn_instance_schema');
		if ($termSchema === '' || $caseId === '') {
			return null;
		}

		try {
			$instances = $this->searchObjectsAsArrays(
				objectService: $location[0],
				register: $location[1],
				schema: $termSchema,
				filters: ['case' => $caseId]
			);
		} catch (\Throwable $e) {
			return null;
		}

		return $this->nearestOpenEnd(instances: $instances);
	}//end nearestActiveTermijnDeadline()

	/**
	 * The nearest `endDateCurrent` among the open term instances.
	 *
	 * @param array<int, array<string, mixed>> $instances The case's term instances.
	 *
	 * @return string|null The nearest active deadline, or null.
	 *
	 * @spec openspec/specs/my-work/spec.md#requirement-the-urgency-score-reads-every-open-term-req-ote-06
	 */
	private function nearestOpenEnd(array $instances): ?string {
		$nearest = null;
		foreach ($instances as $instance) {
			// EVERY OPEN TERM, not only a running one (REQ-OTE-06). A paused
			// term's end has already moved by the pause and an extended one's
			// by the extension, so both are the dates a handler is judged on.
			// Filtered here rather than in the query, because a list of
			// statuses is not a filter every store reads the same way.
			if ($this->isOpenTerm(instance: $instance) === false) {
				continue;
			}

			$date = (string)($instance['endDateCurrent'] ?? '');
			if ($date === '' || ($nearest !== null && $date >= $nearest)) {
				continue;
			}

			$nearest = $date;
		}

		return $nearest;
	}//end nearestOpenEnd()

	/**
	 * Whether a term instance is open: running, extended, paused or exceeded.
	 *
	 * @param array<string, mixed> $instance The term instance.
	 *
	 * @return bool True when the term is open.
	 *
	 * @spec openspec/specs/my-work/spec.md#requirement-the-urgency-score-reads-every-open-term-req-ote-06
	 */
	private function isOpenTerm(array $instance): bool {
		return (in_array((string)($instance['status'] ?? ''), self::OPEN_TERM_STATUSES, true) === true);
	}//end isOpenTerm()
}//end class
