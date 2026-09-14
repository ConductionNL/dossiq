<?php

/**
 * Dossiq intake fan-out.
 *
 * One melding about a broken streetlight in a park is a handhaving case for one
 * department and an onderhoud case for another. Today it opens one case and the
 * second department never hears. GLPI declares the destinations on the form,
 * about thirty settable fields each, and the lane's own distinction from the
 * case hierarchy is exact: a hierarchy is a relation drawn after the fact, this
 * is the fan-out at intake.
 *
 * WHAT IS BORROWED AND WHAT IS MISSING. The form that carries the declaration
 * belongs in buildiq, under `forms-per-case-type`, which does not exist yet. So
 * the destinations are declared on the intake case type here, and move to the
 * form when buildiq has one. The relation between the created cases wants
 * openregister's `relation-types-with-inverses`, which does not exist either, so
 * the fan-out uses the existing related-cases link. That link carries no inverse
 * name: both sides read the same word, and neither says "this one was fanned out
 * from that one". The limitation is recorded here rather than hidden, because a
 * relation that looks richer than it is costs more than one that says what it
 * is.
 *
 * A DESTINATION THAT FAILS STOPS NOTHING SILENTLY. A retired case type on one
 * destination must not cost the other departments their case. Each destination
 * is created on its own and each failure is reported with its reason, so a
 * handler can see that one of three is missing rather than discover it months
 * later.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One submission, several cases, each in its own department.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — the fan-out reaches the case
 *  type, the register and the relation because opening a case in a department and
 *  tying it to its siblings is what it does; each collaborator knows only its own
 *  half.
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */
class IntakeFanOut {

	use SearchesObjects;

	/**
	 * The case-type property declaring the destinations.
	 */
	public const PROPERTY = 'intakeDestinations';

	/**
	 * The relation the created cases are tied together with.
	 *
	 * `subject` is the peer relation dossiq already has, and it is symmetric:
	 * both sides read the same word. The named relation type with an inverse is
	 * openregister's `relation-types-with-inverses` and it is not built, so this
	 * is what the fan-out ties with until it is.
	 *
	 * @see \OCA\Dossiq\Service\CaseRelationService::RELATION_TYPES
	 */
	public const RELATION = CaseRelationService::RELATION_TYPES[1];

	/**
	 * The rule a fan-out with no usable destination names.
	 */
	public const RULE_NO_DESTINATIONS = 'intake-form-declares-no-destination';

	/**
	 * The rule a fan-out with no register names.
	 */
	public const RULE_NOT_CONFIGURED = 'intake-fan-out-register-not-configured';

	/**
	 * Constructor.
	 *
	 * @param SettingsService     $settingsService  Register and schema resolution.
	 * @param CaseTypeResolver    $caseTypeResolver The effective case type.
	 * @param CaseRelationService $relations        The peer relation between cases.
	 * @param LoggerInterface     $logger           Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeResolver $caseTypeResolver,
		private readonly CaseRelationService $relations,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The destinations this intake form declares, enabled ones only.
	 *
	 * @param array<string, mixed> $caseType The effective intake case type row.
	 *
	 * @return array<int, array{title: string, caseType: string, department: string}>
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function destinationsFor(array $caseType): array {
		$declared = ($caseType[self::PROPERTY] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		$destinations = [];
		foreach ($declared as $entry) {
			if (is_array($entry) === false || ($entry['enabled'] ?? true) === false) {
				continue;
			}

			$target = $this->referenceOf(value: ($entry['caseType'] ?? ''));
			if ($target === '') {
				continue;
			}

			$destinations[] = [
				'title' => trim((string)($entry['title'] ?? '')),
				'caseType' => $target,
				'department' => trim((string)($entry['department'] ?? '')),
			];
		}

		return $destinations;
	}//end destinationsFor()

	/**
	 * Open one case per declared destination, and tie them together.
	 *
	 * @param string               $formCaseTypeId The intake case type the form maps to.
	 * @param array<string, mixed> $submission     The submitted values every case starts from.
	 * @param string               $submissionId   The submission's own identifier.
	 *
	 * @return array{created: array<int, array<string, mixed>>, failed: array<int, array<string, string>>, relationHasNoInverse: bool}
	 *
	 * @throws RefusedException When nothing is declared or the register is absent.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function submit(string $formCaseTypeId, array $submission, string $submissionId): array {
		$caseType = $this->caseTypeResolver->effectiveCaseType(caseTypeId: $formCaseTypeId);
		$destinations = $this->destinationsFor(caseType: $caseType);
		if ($destinations === []) {
			throw new RefusedException(
				rule: self::RULE_NO_DESTINATIONS,
				sentence: 'This intake form declares no destination, so submitting it would '
					. 'open no case at all.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$created = [];
		$failed = [];
		foreach ($destinations as $destination) {
			try {
				$created[] = $this->open(
					destination: $destination,
					submission: $submission,
					submissionId: $submissionId
				);
			} catch (Throwable $e) {
				$reason = $this->readableReason(error: $e);
				$this->logger->warning(
					'Dossiq fan-out: destination {destination} opened no case',
					['destination' => $destination['title'], 'reason' => $reason],
				);
				$failed[] = [
					'destination' => $destination['title'],
					'caseType' => $destination['caseType'],
					'reason' => $reason,
				];
			}//end try
		}

		$this->tieTogether(created: $created);

		return [
			'created' => $created,
			'failed' => $failed,
			'relationHasNoInverse' => true,
		];
	}//end submit()

	/**
	 * Tie every created case to every other one from the same submission.
	 *
	 * @param array<int, array<string, mixed>> $created The cases that were opened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	private function tieTogether(array $created): void {
		$ids = [];
		foreach ($created as $case) {
			$id = trim((string)($case['id'] ?? ''));
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		$count = count($ids);
		for ($i = 0; $i < $count; $i++) {
			for ($j = ($i + 1); $j < $count; $j++) {
				$this->relations->addRelation(
					caseId: $ids[$i],
					targetId: $ids[$j],
					natureRelationship: self::RELATION,
					notes: 'Opened from the same submission.',
				);
			}
		}
	}//end tieTogether()

	/**
	 * Open one case for one destination.
	 *
	 * @param array{title: string, caseType: string, department: string} $destination  The destination.
	 * @param array<string, mixed>                                      $submission   The submitted values.
	 * @param string                                                    $submissionId The submission.
	 *
	 * @return array<string, mixed> `{id, caseType, department, title}`.
	 *
	 * @throws RefusedException When the register is absent or the write failed.
	 */
	private function open(array $destination, array $submission, string $submissionId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			throw RefusedException::indeterminate(
				rule: self::RULE_NOT_CONFIGURED,
				sentence: 'The register is not configured, so the submission opened no case.',
			);
		}

		$payload = array_merge(
			$submission,
			[
				'caseType' => $destination['caseType'],
				'assignedGroup' => $destination['department'],
				'intakeSubmission' => $submissionId,
			]
		);

		$stored = $this->saveObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			object: $payload
		);
		if ($stored === null) {
			throw new RefusedException(
				rule: self::RULE_NOT_CONFIGURED,
				sentence: 'The case for ' . $destination['title'] . ' was not written.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return [
			'id' => (string)($stored['@self']['id'] ?? ($stored['id'] ?? '')),
			'caseType' => $destination['caseType'],
			'department' => $destination['department'],
			'title' => $destination['title'],
		];
	}//end open()

	/**
	 * Why one destination failed, in a sentence a handler can read.
	 *
	 * A {@see RefusedException} carries the engine code in `getMessage()` and
	 * the prose in `getSentence()`, so reporting the message would put
	 * `intake_fan_out_register_not_configured` on screen beside a department
	 * name.
	 *
	 * @param Throwable $error What went wrong.
	 *
	 * @return string One sentence.
	 */
	private function readableReason(Throwable $error): string {
		if ($error instanceof RefusedException === true) {
			return $error->getSentence();
		}

		return $error->getMessage();
	}//end readableReason()

	/**
	 * One reference, whether it arrived as a string or as an expanded object.
	 *
	 * @param mixed $value The declared reference.
	 *
	 * @return string The UUID, or ''.
	 */
	private function referenceOf(mixed $value): string {
		if (is_array($value) === true) {
			return trim((string)($value['id'] ?? ($value['@self']['id'] ?? '')));
		}

		return trim((string)$value);
	}//end referenceOf()
}//end class
