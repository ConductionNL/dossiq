<?php

/**
 * Dossiq remedy lodging
 *
 * Registers a remedy (a bezwaar, a beroep, whatever the case type declares)
 * against the decision of a decided case, in one request: it opens a case of
 * the case type the remedy declaration names, linked to the decided case,
 * and records the objection on it through the one objection path
 * (`BezwaarCreationHook::onBezwaarCreated()`, the code
 * `bezwaarObjection#open` runs). When the record fails the new case is
 * deleted again, so no remedy case exists without its objection.
 *
 * Generic (decision 182): what may be lodged, within how many days and which
 * case type it opens is the case type's `remedy` declaration. The Woo request
 * case type is the first to configure it (bezwaar, 42 days, the Bezwaar case
 * type).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Remedy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-against-a-decided-case-req-rml-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Remedy;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\RemedyLodgingInterface;
use OCA\Dossiq\Service\Beschikking\CaseRemedy;
use OCA\Dossiq\Service\Bezwaar\BezwaarCreationHook;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TermijnTimerService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Opens the remedy case against a decided case and records its objection.
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-against-a-decided-case-req-rml-001
 */
class RemedyLodging implements RemedyLodgingInterface {
	use SearchesObjects;

	/**
	 * The channels the objection schema accepts for `receivedChannel`.
	 */
	public const CHANNELS = ['brief', 'email', 'form', 'balie'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService     $settingsService Register, schemas and OpenRegister.
	 * @param CaseRemedy          $caseRemedy      What the decided case's case type declares.
	 * @param BezwaarCreationHook $objections      The one path an objection is recorded through.
	 * @param LoggerInterface     $logger          Logger.
	 * @param CaseDateNormaliser  $dates           The one place a stored date is read.
	 * @param TermijnTimerService|null $timerService The engine calendar bridge; without it the term ends on the counted day.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseRemedy $caseRemedy,
		private readonly BezwaarCreationHook $objections,
		private readonly LoggerInterface $logger,
		private readonly CaseDateNormaliser $dates,
		private readonly ?TermijnTimerService $timerService = null,
	) {
	}//end __construct()

	/**
	 * Lodge a remedy against the decision of a decided case.
	 *
	 * @param string               $caseId    The decided case.
	 * @param array<string, mixed> $objection The objection fields (receivedDate, receivedChannel, grounds, ...).
	 *
	 * @return array{caseId: string, objectionId: string, deadline: string, isTimely: bool}
	 *
	 * @throws RefusedException When the case has no decision or declares no remedy case type.
	 * @throws RuntimeException When OpenRegister is unavailable or the objection cannot be recorded.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-against-a-decided-case-req-rml-001
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-lodged-remedy-is-judged-timely-against-the-declared-term-req-rml-002
	 */
	public function lodge(string $caseId, array $objection): array {
		$objectService = $this->requireObjectService();
		[$register, $caseSchema] = $this->registerAndCaseSchema();
		$decision = $this->requireDecision(objectService: $objectService, register: $register, caseId: $caseId);

		$this->assertComplete(objection: $objection);

		$remedy = $this->requireRemedyCaseType(caseId: $caseId);

		$received = ($this->dates->tryParse(value: ($objection['receivedDate'] ?? null)) ?? $this->dates->today());
		$isTimely = $this->isTimely(decision: $decision, termDays: $remedy['termDays'], received: $received);
		$opened = $this->openRemedyCase(objectService: $objectService, register: $register, caseSchema: $caseSchema, caseId: $caseId,
			kind: $remedy['kind'], remedyType: $remedy['caseType'], received: $received);
		$openedId = self::openedIdOf(opened: $opened);

		$record = $this->recordObjection(objectService: $objectService, register: $register, caseSchema: $caseSchema, openedId: $openedId,
			decision: $decision, objection: ['receivedDate' => $received->format('Y-m-d'), 'isTimely' => $isTimely] + $objection);

		return self::lodged(openedId: $openedId, record: $record, opened: $opened, isTimely: $isTimely);
	}//end lodge()

	/**
	 * OpenRegister's object service, or an exception when it is absent.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-against-a-decided-case-req-rml-001
	 */
	private function requireObjectService(): object {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		return $objectService;
	}//end requireObjectService()

	/**
	 * The configured register and case schema.
	 *
	 * @return array{0: string, 1: string} The register and the case schema.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-against-a-decided-case-req-rml-001
	 */
	private function registerAndCaseSchema(): array {
		$register = (string)$this->settingsService->getConfigValue(key: 'register');
		$caseSchema = (string)$this->settingsService->getConfigValue(key: 'case_schema');

		return [$register, $caseSchema];
	}//end registerAndCaseSchema()

	/**
	 * The id of the opened remedy case, or an exception when it has none.
	 *
	 * @param array<string, mixed> $opened The opened remedy case.
	 *
	 * @return string The id.
	 *
	 * @throws RuntimeException When the remedy case could not be opened.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-against-a-decided-case-req-rml-001
	 */
	private static function openedIdOf(array $opened): string {
		$openedId = (string)($opened['id'] ?? ($opened['uuid'] ?? ''));
		if ($openedId === '') {
			throw new RuntimeException('The remedy case could not be opened');
		}

		return $openedId;
	}//end openedIdOf()

	/**
	 * What lodging a remedy answers.
	 *
	 * @param string               $openedId The opened remedy case.
	 * @param array<string, mixed> $record   The recorded objection.
	 * @param array<string, mixed> $opened   The opened remedy case, for its deadline.
	 * @param bool                 $isTimely Whether the remedy was lodged in time.
	 *
	 * @return array{caseId: string, objectionId: string, deadline: string, isTimely: bool}
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-against-a-decided-case-req-rml-001
	 */
	private static function lodged(string $openedId, array $record, array $opened, bool $isTimely): array {
		return [
			'caseId' => $openedId,
			'objectionId' => (string)($record['id'] ?? ($record['uuid'] ?? '')),
			'deadline' => (string)($opened['plannedEndDate'] ?? ($opened['deadline'] ?? '')),
			'isTimely' => $isTimely,
		];
	}//end lodged()

	/**
	 * The latest decision of the case, refusing when there is none.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 * @param string $caseId        The decided case.
	 *
	 * @return array<string, mixed> The decision.
	 *
	 * @throws RefusedException When the case has no decision.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-against-a-decided-case-req-rml-001
	 */
	private function requireDecision(object $objectService, string $register, string $caseId): array {
		$decision = $this->latestDecision(objectService: $objectService, register: $register, caseId: $caseId);
		if ($decision === null) {
			throw new RefusedException(
				rule: 'remedy-no-decision',
				sentence: 'This case has no decision yet, so nothing can be lodged against it.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		return $decision;
	}//end requireDecision()

	/**
	 * The remedy declaration of the case's type, refusing when it names no case type.
	 *
	 * @param string $caseId The decided case.
	 *
	 * @return array{kind: string, termDays: int, body: string, enabled: bool, caseType: string, id: string, label: string, description: string}
	 *         The remedy declaration.
	 *
	 * @throws RefusedException When the case type declares no remedy case type.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-against-a-decided-case-req-rml-001
	 */
	private function requireRemedyCaseType(string $caseId): array {
		$remedy = $this->caseRemedy->declarationOf(caseId: $caseId);
		if ($remedy['enabled'] === false || $remedy['caseType'] === '') {
			throw new RefusedException(
				rule: 'remedy-no-case-type',
				sentence: 'The case type of this case names no case type for a remedy against its decisions.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		return $remedy;
	}//end requireRemedyCaseType()

	/**
	 * Open the remedy case, titled after the decided case.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register.
	 * @param string               $caseSchema    The case schema.
	 * @param string               $caseId        The decided case.
	 * @param string               $kind          The remedy kind, for the title.
	 * @param string               $remedyType    The remedy case type.
	 * @param DateTimeImmutable    $received      When the remedy came in.
	 *
	 * @return array<string, mixed> The opened case, or an empty array.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-against-a-decided-case-req-rml-001
	 */
	private function openRemedyCase(
		object $objectService,
		string $register,
		string $caseSchema,
		string $caseId,
		string $kind,
		string $remedyType,
		DateTimeImmutable $received,
	): array {
		$decided = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $caseSchema, id: $caseId) ?? [];

		return $this->saveObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			object: [
				'title' => trim(ucfirst($kind) . ': ' . (string)($decided['title'] ?? $caseId)),
				'caseType' => $remedyType,
				'startDate' => $received->format('Y-m-d'),
			],
		) ?? [];
	}//end openRemedyCase()

	/**
	 * Record the objection on the opened case, withdrawing the case when that fails.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register.
	 * @param string               $caseSchema    The case schema.
	 * @param string               $openedId      The opened remedy case.
	 * @param array<string, mixed> $decision      The contested decision.
	 * @param array<string, mixed> $objection     The objection payload.
	 *
	 * @return array<string, mixed> The recorded objection.
	 *
	 * @throws RuntimeException When the objection cannot be recorded.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-against-a-decided-case-req-rml-001
	 */
	private function recordObjection(
		object $objectService,
		string $register,
		string $caseSchema,
		string $openedId,
		array $decision,
		array $objection,
	): array {
		try {
			$record = $this->objections->onBezwaarCreated(
				objectionCaseId: $openedId,
				contestedDecisionId: (string)($decision['id'] ?? ''),
				objectionPayload: $objection,
			);
		} catch (Throwable $e) {
			$this->withdrawOpened(objectService: $objectService, register: $register, schema: $caseSchema, caseId: $openedId);
			throw new RuntimeException($e->getMessage(), 0, $e);
		}

		return $record;
	}//end recordObjection()

	/**
	 * Whether the remedy came in within the declared term.
	 *
	 * The term starts the day after the decision was made known (Awb 6:8) and
	 * runs `termDays` days, so its last day is the made-known date plus
	 * `termDays`. Made known is the publication date, else the delivery date,
	 * else the decision date. A late remedy is still registered (Awb 6:11).
	 *
	 * @param array<string, mixed> $decision The contested decision.
	 * @param int                  $termDays The declared term in days; 0 means no term to miss.
	 * @param DateTimeImmutable    $received When the remedy came in.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-lodged-remedy-is-judged-timely-against-the-declared-term-req-rml-002
	 */
	public function isTimely(array $decision, int $termDays, DateTimeImmutable $received): bool {
		$madeKnown = null;
		foreach (['publicationDate', 'deliveryDate', 'decisionDate'] as $field) {
			$madeKnown = $this->dates->tryParse(value: ($decision[$field] ?? null));
			if ($madeKnown !== null) {
				break;
			}
		}

		if ($madeKnown === null || $termDays <= 0) {
			return true;
		}

		// Algemene termijnenwet art. 1: a term that ends on a Saturday, a
		// Sunday or a public holiday runs to the next working day.
		$end = $madeKnown->modify('+' . $termDays . ' days');
		$end = ($this->timerService?->rollTermEndFor(date: $end) ?? $end);

		// Compared as calendar days: a term ends at the end of its last day,
		// whatever hour either moment carries.
		return $this->dates->formatCalendarDate(moment: $received) <= $this->dates->formatCalendarDate(moment: $end);
	}//end isTimely()

	/**
	 * Refuse, before anything is opened, an objection the objection schema would refuse.
	 *
	 * @param array<string, mixed> $objection The objection fields.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the grounds are missing or the channel is not one the schema knows.
	 */
	private function assertComplete(array $objection): void {
		if (trim((string)($objection['grounds'] ?? '')) === '') {
			throw new RefusedException(
				rule: 'remedy-grounds-missing',
				sentence: 'Say on what grounds the objection is made.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if (in_array((string)($objection['receivedChannel'] ?? ''), self::CHANNELS, true) === false) {
			throw new RefusedException(
				rule: 'remedy-channel-unknown',
				sentence: 'Say how the objection came in: by letter, e-mail, form or at the desk.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}
	}//end assertComplete()

	/**
	 * The case's most recent decision, or null.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The dossiq register.
	 * @param string $caseId        The decided case.
	 *
	 * @return array<string, mixed>|null
	 */
	private function latestDecision(object $objectService, string $register, string $caseId): ?array {
		$schema = (string)$this->settingsService->getConfigValue(key: 'decision_schema');
		if ($schema === '' || $register === '') {
			return null;
		}

		$decisions = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['case' => $caseId, '_limit' => 50],
		);
		usort(
			$decisions,
			static fn (array $left, array $right): int => strcmp((string)($right['decisionDate'] ?? ''), (string)($left['decisionDate'] ?? ''))
		);

		return ($decisions[0] ?? null);
	}//end latestDecision()

	/**
	 * Delete the remedy case again after its objection failed.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The dossiq register.
	 * @param string $schema        The case schema.
	 * @param string $caseId        The case just opened.
	 *
	 * @return void
	 */
	private function withdrawOpened(object $objectService, string $register, string $schema, string $caseId): void {
		try {
			$objectService->deleteObject(uuid: $caseId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->error(
				'A remedy case whose objection failed could not be deleted again',
				['app' => AppIdentity::APP_ID, 'caseId' => $caseId, 'error' => $e->getMessage()],
			);
		}
	}//end withdrawOpened()

}//end class
