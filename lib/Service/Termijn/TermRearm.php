<?php

/**
 * Dossiq term re-arm.
 *
 * What happens to a case's running statutory clocks when the case is rebound
 * to another case type. A rebind moves no statutory clock: the case was
 * received on a day, and the Awb term runs from the day it was received, not
 * from the day somebody noticed it had been filed under the wrong type. So the
 * successor keeps the old instance's start date, takes only its duration from
 * the target definition, and the extensions already granted travel as days
 * rather than as an end date, because an end date carries the old duration
 * with it.
 *
 * Split out of {@see \OCA\Dossiq\Service\TermijnService}, which was over its
 * complexity ceiling. The lifecycle of one term instance is that service's;
 * moving a case's clocks from one definition to another is the rebind's, and
 * the rebind is this class's only caller.
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
 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use DateTimeImmutable;
use OCA\Dossiq\Exception\NoTermijnDefinitieException;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TermijnService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Re-arming a case's running terms against another case type's definition.
 *
 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
 */
class TermRearm {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param TermijnService          $terms           The one writer of a term instance.
	 * @param SettingsService         $settingsService Register and schema ids, and the object service.
	 * @param LoggerInterface         $logger          Logger.
	 * @param CaseDateNormaliser|null $dates           The one date path, absent in the narrowest test builds.
	 */
	public function __construct(
		private readonly TermijnService $terms,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?CaseDateNormaliser $dates = null,
	) {
	}//end __construct()

	/**
	 * Re-arm this case's running terms against another case type's definition.
	 *
	 * 🔴 A REBIND MOVES NO STATUTORY CLOCK, AND THAT IS THE WHOLE RULE. The
	 * case was received on a day, and the Awb term runs from the day it was
	 * received, not from the day somebody noticed it had been filed under the
	 * wrong type. So the new instance keeps the old one's `startDate`, and the
	 * only thing the target definition supplies is the DURATION. Starting the
	 * clock again at the rebind would hand the organisation weeks it is not
	 * entitled to, silently, on every case that was ever refiled.
	 *
	 * 🔑 EXTENSIONS AND SUSPENSIONS TRAVEL AS DAYS, NOT AS DATES. An Awb 4:14
	 * verdaging is "this term is longer by N days", and the days are what
	 * survives a change of definition: carrying the old `endDateCurrent`
	 * forward would carry the old duration with it and quietly ignore the
	 * target's rule. Each carried event is re-recorded on the new instance, so
	 * the trail says why the end date is where it is rather than leaving a
	 * number nobody can account for. D-2's fixture is the test of exactly this:
	 * a 56-day term started 1 June, extended once by 14 days, rebound on 20
	 * June to an 84-day definition, ends on 1 June plus 98 days.
	 *
	 * A target case type with no term definition is NOT an empty answer. The
	 * old instances are left running and the count of them is reported, because
	 * completing a statutory clock that has no successor is how a case silently
	 * stops being watched.
	 *
	 * @param string $caseId       The case whose terms are re-armed.
	 * @param string $caseTypeSlug The TARGET case type, as the slug the term
	 *                             definitions are keyed by. A uuid matches no
	 *                             definition and the re-arm silently does
	 *                             nothing, so callers holding one convert it
	 *                             through {@see CaseTypeSlugResolver} first.
	 * @param string $reason       Why the case was rebound, for the trail.
	 *
	 * @return array{rearmed: int, kept: int, note: string} What happened to the clocks.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	public function forDefinition(string $caseId, string $caseTypeSlug, string $reason): array {
		$running = [];
		foreach ($this->terms->instancesForCase(caseId: $caseId) as $instance) {
			if ((string)($instance['status'] ?? '') === 'lopend') {
				$running[] = $instance;
			}
		}

		if ($running === []) {
			return ['rearmed' => 0, 'kept' => 0, 'note' => ''];
		}

		$definitie = $this->terms->getTermijnDefinitie(caseType: $caseTypeSlug);
		if ($definitie === null) {
			$this->logger->warning(
				'TermRearm.forDefinition: the target case type has no active term definition, '
					. 'so the running terms were left on the definition they started under',
				['case' => $caseId, 'caseType' => $caseTypeSlug, 'running' => count($running)]
			);

			return [
				'rearmed' => 0,
				'kept' => count($running),
				'note' => 'The target case type has no active term definition, so this case\'s running terms '
					. 'were left as they are rather than closed with nothing to replace them.',
			];
		}

		$rearmed = 0;
		foreach ($running as $instance) {
			if ($this->rearmOne(instance: $instance, caseId: $caseId, caseTypeSlug: $caseTypeSlug, reason: $reason) === true) {
				$rearmed++;
			}
		}

		return [
			'rearmed' => $rearmed,
			'kept' => (count($running) - $rearmed),
			'note' => '',
		];
	}//end forDefinition()

	/**
	 * Close one running term and open its successor on the same start date.
	 *
	 * @param array<string, mixed> $instance     The running instance.
	 * @param string               $caseId       The case.
	 * @param string               $caseTypeSlug The target case type's slug.
	 * @param string               $reason       Why the case was rebound.
	 *
	 * @return boolean True when the successor was created.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	private function rearmOne(array $instance, string $caseId, string $caseTypeSlug, string $reason): bool {
		$instanceId = (string)($instance['id'] ?? '');
		if ($instanceId === '') {
			return false;
		}

		$carried = $this->carriedEvents(termInstanceId: $instanceId);
		$startDate = $this->startOf(instance: $instance);

		try {
			$successor = $this->terms->createTermijnInstance(
				caseId: $caseId,
				caseType: $caseTypeSlug,
				startDate: $startDate
			);
		} catch (NoTermijnDefinitieException | RuntimeException $e) {
			// The successor is created BEFORE the old one is closed, so a
			// failure here leaves the case with the clock it already had
			// rather than with none at all.
			$this->logger->error(
				'TermRearm.forDefinition: the successor term could not be created, '
					. 'so the running term was left alone',
				['case' => $caseId, 'instance' => $instanceId, 'error' => $e->getMessage()]
			);

			return false;
		}

		$this->terms->markTermijnCompleted(
			termInstanceId: $instanceId,
			rationale: 'Termijn afgesloten bij herbinding naar een ander zaaktype: ' . $reason,
		);

		$this->replay(successor: $successor, carried: $carried);

		return true;
	}//end rearmOne()

	/**
	 * Re-record the old term's extensions and suspensions on its successor.
	 *
	 * @param array<string, mixed>             $successor The new instance.
	 * @param array<int, array<string, mixed>> $carried   The events to carry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	private function replay(array $successor, array $carried): void {
		$successorId = (string)($successor['id'] ?? '');
		if ($successorId === '' || $carried === []) {
			return;
		}

		$days = 0;
		$extensions = 0;
		foreach ($carried as $event) {
			$impact = (int)($event['daysImpact'] ?? 0);
			$days += $impact;
			if ((string)($event['type'] ?? '') === 'verdaging') {
				$extensions++;
			}

			$this->terms->recordEvent(
				termInstanceId: $successorId,
				type: (string)($event['type'] ?? 'verdaging'),
				basis: (string)($event['basis'] ?? 'AWB 4:14'),
				rationale: (string)($event['rationale'] ?? ''),
				daysImpact: $impact,
				moment: $this->momentOf(event: $event),
				actor: (string)($event['actor'] ?? 'system'),
			);
		}

		if ($days === 0 && $extensions === 0) {
			return;
		}

		$current = $this->shifted(
			calculated: (string)($successor['endDateCalculated'] ?? ''),
			days: $days,
		);

		$this->terms->updateTermijnInstance(
			termInstanceId: $successorId,
			patch: ['endDateCurrent' => $current, 'countExtensions' => $extensions]
		);
	}//end replay()

	/**
	 * A calculated end date moved by the days the carried events are worth.
	 *
	 * @param string $calculated The calculated end date, `Y-m-d`, or '' when there is none.
	 * @param int    $days       The days to move it by, which may be negative.
	 *
	 * @return string The moved date, or the calculated one when there is nothing to move.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	private function shifted(string $calculated, int $days): string {
		if ($calculated === '' || $days === 0) {
			return $calculated;
		}

		$sign = '-';
		if ($days >= 0) {
			$sign = '+';
		}

		return (new DateTimeImmutable($calculated))
			->modify($sign . abs($days) . ' days')
			->format('Y-m-d');
	}//end shifted()

	/**
	 * The events of one instance that change how long it runs.
	 *
	 * `start` is excluded because the successor's own start event already
	 * carries the target definition's duration, and adding the old one would
	 * count a duration twice. `voltooi` is excluded because it ends a term
	 * rather than lengthening it.
	 *
	 * @param string $termInstanceId The instance.
	 *
	 * @return array<int, array<string, mixed>> The events, oldest first.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	private function carriedEvents(string $termInstanceId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_gebeurtenis_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['deadlineInstance' => $termInstanceId]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'TermRearm.carriedEvents lookup failed, so no extension was carried forward',
				['instance' => $termInstanceId, 'error' => $e->getMessage()]
			);

			return [];
		}

		$carried = [];
		foreach ($rows as $row) {
			if (in_array((string)($row['type'] ?? ''), ['start', 'voltooi'], true) === false) {
				$carried[] = $row;
			}
		}

		usort(
			$carried,
			static fn (array $a, array $b): int
				=> strcmp((string)($a['moment'] ?? ''), (string)($b['moment'] ?? ''))
		);

		return $carried;
	}//end carriedEvents()

	/**
	 * The day a running term started, as a date.
	 *
	 * @param array<string, mixed> $instance The instance.
	 *
	 * @return DateTimeImmutable|null The start, or null when it carries none.
	 */
	private function startOf(array $instance): ?DateTimeImmutable {
		// THE ONE DATE PATH. `new DateTimeImmutable($raw)` here read the
		// PROCESS zone, so the same stored string became a different day on
		// two servers, and the swallowing catch meant nothing said so. The
		// normaliser resolves the administered zone and answers null for a
		// value it cannot read, which is the same contract without the second
		// rule.
		return $this->dates?->tryParse($instance['startDate'] ?? null);
	}//end startOf()

	/**
	 * The moment an event was recorded, as a date.
	 *
	 * @param array<string, mixed> $event The event.
	 *
	 * @return DateTimeImmutable|null The moment, or null for now.
	 */
	private function momentOf(array $event): ?DateTimeImmutable {
		// Same rule as {@see self::startOf()}, and the reason
		// `OneDateWritePathTest` names this method by name: a private method
		// whose name reads like a date helper and whose body parses a string
		// is a second definition of what a date is.
		return $this->dates?->tryParse($event['moment'] ?? null);
	}//end momentOf()

}//end class
