<?php

/**
 * Dossiq Beschikking Service.
 *
 * Orchestrates the full beschikking lifecycle: composition (via the Docudesk
 * template-engine adapter), mandaat-verificatie at the akkoord step, eIDAS-TSP
 * signing (via the OpenConnector signing adapter), Berichtenbox delivery, the
 * field-edit immutability contract, and the verifiable audit-pakket export.
 *
 * All state changes go through StateMachineService, which enforces the formal
 * transition rules and writes an immutable stateMachineLog record. This class
 * owns the transitions themselves and nothing else: persistence lives in
 * {@see BeschikkingRepository}, the authority rules in {@see MandaatVerifier},
 * the export in {@see AuditPacketBuilder}, and the Awb 6:7 objection period in
 * {@see BezwaarTermijnScheduler}.
 *
 * Special-category identifiers (BSN) are never logged raw; only masked.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/beschikking-generatie/tasks.md#T14
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Beschikking\ArchivalAdapterInterface;
use OCA\Dossiq\Service\Beschikking\AuditPacketBuilder;
use OCA\Dossiq\Service\Beschikking\BeschikkingRepository;
use OCA\Dossiq\Service\Beschikking\BezwaarTermijnScheduler;
use OCA\Dossiq\Service\Beschikking\CaseRemedy;
use OCA\Dossiq\Service\Beschikking\MandaatVerifier;
use OCA\Dossiq\Service\Beschikking\SigningAdapterInterface;
use OCA\Dossiq\Service\Beschikking\TemplateEngineAdapterInterface;
use OCA\Dossiq\Service\People\CoordinatorRequirement;
use RuntimeException;

/**
 * Beschikking lifecycle orchestrator.
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T14
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Thirteen, one over the
 * threshold, and the one that crossed it is `CoordinatorRequirement`: the seat
 * a case type may insist on before its besluit is signed. The alternatives
 * were both worse. Checking it in the controller instead leaves the rule on
 * ONE door, so a second caller of `onderteken()` signs without it, and a rule
 * that can be walked around is not a rule. Folding the collaborators into a
 * parameter object hides the dependency list rather than shortening it, which
 * is the reasoning {@see AcknowledgementService} already records for the same
 * trade. The thirteen are each injected and each named, so the class is
 * readable even where it is wide.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Same list, same reason.
 */
class BeschikkingService {

	/**
	 * Constructor.
	 *
	 * @param StateMachineService $stateMachine The state-machine guard.
	 * @param BerichtenboxRoutingService $berichtenbox The Berichtenbox routing service.
	 * @param TemplateEngineAdapterInterface $templateAdapter The Docudesk template adapter.
	 * @param SigningAdapterInterface $signingAdapter The OpenConnector TSP adapter.
	 * @param ArchivalAdapterInterface $archivalAdapter The OpenRegister archival adapter.
	 * @param BeschikkingRepository $repository Beschikking persistence.
	 * @param MandaatVerifier $mandateVerifier Mandaat resolution + verification.
	 * @param AuditPacketBuilder $auditPacket Verifiable audit-pakket assembly.
	 * @param BezwaarTermijnScheduler $bezwaarScheduler Awb 6:7 bezwaartermijn scheduling.
	 * @param CoordinatorRequirement $coordinator The second seat a case type may insist on before signing.
	 * @param CaseRemedy $remedy The remedy this case's decisions carry, and the clock they start.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly StateMachineService $stateMachine,
		private readonly BerichtenboxRoutingService $berichtenbox,
		private readonly TemplateEngineAdapterInterface $templateAdapter,
		private readonly SigningAdapterInterface $signingAdapter,
		private readonly ArchivalAdapterInterface $archivalAdapter,
		private readonly BeschikkingRepository $repository,
		private readonly MandaatVerifier $mandateVerifier,
		private readonly AuditPacketBuilder $auditPacket,
		private readonly BezwaarTermijnScheduler $bezwaarScheduler,
		private readonly CoordinatorRequirement $coordinator,
		private readonly CaseRemedy $remedy,
	) {
	}//end __construct()

	/**
	 * Compose a new beschikking from zaakdata (status: ontwerp). [T05]
	 *
	 * @param string $caseId The case UUID.
	 * @param string|null $templateId The chosen template, or null to auto-select.
	 * @param array<string, mixed> $overrides Optional geadresseerde/field overrides.
	 *
	 * @return array<string, mixed> The created beschikking, with `_required` flags on missing fields.
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T05
	 */
	public function compose(string $caseId, ?string $templateId = null, array $overrides = []): array {
		if ($caseId === '') {
			throw new RuntimeException('zaakId_required');
		}

		$effectiveDate = (new DateTimeImmutable())->format('Y-m-d');
		$resolvedTemplate = ($templateId ?? 'tpl-default');
		$version = $this->templateAdapter->resolveVersion($resolvedTemplate, $effectiveDate);

		$composition = $this->templateAdapter->render(
			$version['templateId'],
			['caseId' => $caseId, 'overrides' => $overrides],
		);

		$decision = [
			'caseId' => $caseId,
			'decisionType' => (string)($overrides['decisionType'] ?? 'toekenning'),
			'templateId' => $version['templateId'],
			// The resolved version is STORED, not just resolved. It was
			// computed here and dropped on the floor: every beschikking
			// recorded which template made it and never which version of it,
			// so a template edited after a decision issued left the appeal
			// against that decision reading the wrong text. `draftVersion`
			// below counts re-renders of this beschikking and answers a
			// different question.
			'templateVersion' => $version['version'],
			'draftVersion' => 1,
			'currentStatus' => 'draft',
			'compositeContent' => $composition,
			'addressee' => (array)($overrides['addressee'] ?? []),
			'decision' => (array)($overrides['decision'] ?? []),
			'rationale' => ($overrides['rationale'] ?? null),
			// 🔴 THE CLAUSE COMES FROM THE CASE TYPE, NOT FROM THE TEMPLATE
			// (REQ-DEC-03). Two case types sharing one template print
			// different terms, and a change in the law is one configuration
			// change rather than forty template edits with a guess about
			// which were missed. A case type that declares no remedy prints
			// nothing here and is warned about at publication; a default
			// clause would put a term nobody chose onto a decision somebody
			// has to act on.
			'legalRemediesClause' => $this->remedy->clauseFor(caseId: $caseId),
		];

		$saved = $this->repository->save(decision: $decision);
		return $this->markRequiredFields(decision: $saved);
	}//end compose()

	/**
	 * Load a single beschikking by id. [T06]
	 *
	 * Delegates to {@see BeschikkingRepository::find()}.
	 *
	 * @param string $decisionId The beschikking UUID.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T06
	 */
	public function find(string $decisionId): ?array {
		return $this->repository->find(decisionId: $decisionId);
	}//end find()

	/**
	 * Grant mandaat-approval and transition to akkoord-mandaat. [T07]
	 *
	 * @param string $decisionId The beschikking UUID.
	 * @param string $approvedBy The approver's Nextcloud UID.
	 *
	 * @return array<string, mixed> The updated beschikking.
	 *
	 * @throws RuntimeException On a missing beschikking, invalid transition, or insufficient mandaat.
	 * @throws RefusedException When the mandate scheme could not be read, so the mandate cannot be checked.
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T07
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function akkoord(string $decisionId, string $approvedBy): array {
		$decision = $this->repository->requireBeschikking(decisionId: $decisionId);
		$current = (string)($decision['currentStatus'] ?? '');

		if ($this->stateMachine->validateTransition($current, 'approved-mandate') === false) {
			throw new RuntimeException('invalid_transition');
		}

		$regeling = $this->mandateVerifier->resolveMandaatRegeling(caseType: (string)($decision['caseType'] ?? ''));
		$level = $this->mandateVerifier->resolveNiveauForUser(
			regeling: $regeling,
			decision: $decision,
			approvedBy: $approvedBy
		);

		if ($level === null) {
			throw new RuntimeException('mandaat_insufficient');
		}

		$decision['mandateGranted'] = [
			'mandateSchemeId' => (string)($regeling['id'] ?? ($regeling['@self']['slug'] ?? '')),
			'mandateLevel' => $level,
			'approvedBy' => $approvedBy,
			'approvedDate' => (new DateTimeImmutable())->format('c'),
		];
		$decision['currentStatus'] = 'approved-mandate';

		$saved = $this->repository->save(decision: $decision);
		$this->stateMachine->logTransition(
			$decisionId,
			$current,
			'approved-mandate',
			['actor' => $approvedBy, 'actorType' => 'employee', 'trigger' => 'manual'],
		);

		return $saved;
	}//end akkoord()

	/**
	 * Sign the beschikking via the TSP and transition to ondertekend. [T08]
	 *
	 * @param string $decisionId The beschikking UUID.
	 * @param string $tspProvider The TSP provider slug.
	 * @param string $signatory The signer's Nextcloud UID.
	 *
	 * @return array<string, mixed> The updated beschikking.
	 *
	 * @throws RuntimeException On a missing beschikking or invalid transition.
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T08
	 */
	public function onderteken(string $decisionId, string $tspProvider, string $signatory): array {
		$decision = $this->repository->requireBeschikking(decisionId: $decisionId);
		$current = (string)($decision['currentStatus'] ?? '');

		if ($this->stateMachine->validateTransition($current, 'signed') === false) {
			throw new RuntimeException('invalid_transition');
		}

		// BEFORE THE TSP IS CALLED, not after. A signature is minted at a
		// provider and countersigned onto the file; refusing afterwards would
		// leave a signed document behind a refused act, which is worse than
		// either outcome on its own.
		$this->coordinator->requireSeatFilled(caseId: (string)($decision['caseId'] ?? ''));

		$fileId = (string)(($decision['compositeContent']['fileId'] ?? ''));
		$signature = $this->signingAdapter->sign($fileId, $signatory, $tspProvider);

		$decision['signature'] = [
			'tspProvider' => $tspProvider,
			'tspProviderEidasId' => (string)($signature['tspProviderEidasId'] ?? ''),
			'signatory' => $signatory,
			'signingMoment' => (string)($signature['signingMoment'] ?? ''),
			'kind' => 'gekwalificeerde-elektronische-handtekening',
			'certificateSerialNumber' => (string)($signature['certificateSerialNumber'] ?? ''),
			'validationRapportId' => (string)($signature['validationRapportId'] ?? ''),
		];
		$decision['compositeContent']['fileId'] = (string)($signature['signedBestandId'] ?? $fileId);
		$decision['currentStatus'] = 'signed';

		$saved = $this->repository->save(decision: $decision);
		$this->stateMachine->logTransition(
			$decisionId,
			$current,
			'signed',
			[
				'actor' => $signatory,
				'actorType' => 'employee',
				'trigger' => 'manual',
				'evidenceMaterial' => [
					'kind' => 'tsp-handtekening-rapport',
					'rapportId' => (string)($signature['validationRapportId'] ?? ''),
				],
			],
		);

		return $saved;
	}//end onderteken()

	/**
	 * Deliver the beschikking via Berichtenbox and transition to verzonden. [T09]
	 *
	 * Creates a BezwaarTrigger with a 6-week bezwaartermijn (Awb 6:7).
	 *
	 * @param string $decisionId The beschikking UUID.
	 * @param string $actor The dispatching user's UID.
	 *
	 * @return array<string, mixed> The updated beschikking.
	 *
	 * @throws RuntimeException On a missing beschikking or invalid transition.
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T09
	 */
	public function verzend(string $decisionId, string $actor): array {
		$decision = $this->repository->requireBeschikking(decisionId: $decisionId);
		$current = (string)($decision['currentStatus'] ?? '');

		if ($this->stateMachine->validateTransition($current, 'sent') === false) {
			throw new RuntimeException('invalid_transition');
		}

		$dispatch = $this->berichtenbox->routeToBerichtenbox($decision);

		$bekendmaking = (new DateTimeImmutable())->format('Y-m-d');
		// The term the CASE TYPE declares, when it declares one. Falling back
		// to the scheduler's six weeks keeps every case type that declares
		// nothing behaving exactly as it did, and stops a decision printing
		// forty-two days over a clock that runs for six weeks.
		$declaredDays = $this->remedy->termDaysFor(caseId: (string)($decision['caseId'] ?? ''));
		$term = $this->bezwaarScheduler->computeTermijn(
			bekendmaking: $bekendmaking,
			termDays: $declaredDays,
		);

		$decision['dispatch'] = $dispatch;
		$decision['announcementDate'] = $bekendmaking;
		$decision['objectionTermEndDate'] = $term['endDate'];
		$decision['reminderDate'] = $term['herinnering'];
		$decision['currentStatus'] = 'sent';

		$saved = $this->repository->save(decision: $decision);

		$this->bezwaarScheduler->createBezwaarTrigger(
			decisionId: $decisionId,
			bekendmaking: $bekendmaking,
			endDate: $term['endDate'],
			herinnering: $term['herinnering'],
		);

		// The clock, beside the trigger. The trigger is the scheduling record
		// that fires a reminder; the term instance is what the CASE answers
		// "is this decision still open to bezwaar" from, without anybody doing
		// arithmetic against a date on a document (REQ-DEC-04).
		$this->remedy->bindTerm(
			caseId: (string)($decision['caseId'] ?? ''),
			decisionId: $decisionId,
			sentOn: new DateTimeImmutable($bekendmaking),
		);

		$this->stateMachine->logTransition(
			$decisionId,
			$current,
			'sent',
			['actor' => $actor, 'actorType' => 'employee', 'trigger' => 'manual'],
		);

		return $saved;
	}//end verzend()

	/**
	 * Field-edit a beschikking, honouring the immutability contract. [T11]
	 *
	 * @param string $decisionId The beschikking UUID.
	 * @param array<string, mixed> $updates The field updates.
	 *
	 * @return array<string, mixed> The updated beschikking.
	 *
	 * @throws RuntimeException 'immutable' when the beschikking is ondertekend or later and a content field is touched.
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T11
	 */
	public function updateFields(string $decisionId, array $updates): array {
		$decision = $this->repository->requireBeschikking(decisionId: $decisionId);

		$this->stateMachine->assertMutable(stored: $decision, changed: $updates);

		foreach ($updates as $field => $value) {
			$decision[$field] = $value;
		}

		$decision['draftVersion'] = ((int)($decision['draftVersion'] ?? 1)) + 1;

		return $this->repository->save(decision: $decision);
	}//end updateFields()

	/**
	 * Verify whether a mandaat covers a decision. [T14 verifyMandaat]
	 *
	 * Delegates to {@see MandaatVerifier::verifyMandaat()}.
	 *
	 * @param array<string, mixed> $regeling The mandaatRegeling object.
	 * @param string $level The proposed approver level.
	 * @param float $amount The decision bedrag.
	 * @param string $decisionType The decision type.
	 * @param string $caseType The case type.
	 *
	 * @return bool True when the level may sign this decision within its limit.
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T14
	 */
	public function verifyMandaat(
		array $regeling,
		string $level,
		float $amount,
		string $decisionType,
		string $caseType,
	): bool {
		return $this->mandateVerifier->verifyMandaat(
			regeling: $regeling,
			level: $level,
			amount: $amount,
			decisionType: $decisionType,
			caseType: $caseType,
		);
	}//end verifyMandaat()

	/**
	 * Assemble and PKCS#7-sign the verifiable audit-pakket ZIP. [T10]
	 *
	 * Delegates to {@see AuditPacketBuilder::build()}.
	 *
	 * @param string $decisionId The beschikking UUID.
	 *
	 * @return string The ZIP bytes.
	 *
	 * @throws RuntimeException On a missing beschikking or when ZIP support is unavailable.
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T10
	 */
	public function exportAuditPacket(string $decisionId): string {
		$decision = $this->repository->requireBeschikking(decisionId: $decisionId);

		return $this->auditPacket->build(decisionId: $decisionId, decision: $decision);
	}//end exportAuditPacket()

	/**
	 * Archive a beschikking to durable storage and transition to gearchiveerd. [T13]
	 *
	 * @param string $decisionId The beschikking UUID.
	 *
	 * @return array<string, mixed> The updated beschikking.
	 *
	 * @throws RuntimeException On a missing beschikking or invalid transition.
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T13
	 */
	public function archive(string $decisionId): array {
		$decision = $this->repository->requireBeschikking(decisionId: $decisionId);
		$current = (string)($decision['currentStatus'] ?? '');

		if ($this->stateMachine->validateTransition($current, 'archived') === false) {
			throw new RuntimeException('invalid_transition');
		}

		$metadata = [
			'schema' => 'TMLO-1.2',
			'identificatieKenmerk' => (string)($decision['reference'] ?? ''),
			'aggregatieniveau' => 'Archiefstuk',
			'creatieDatum' => (string)(($decision['mandateGranted']['approvedDate'] ?? '')),
			'announcementDate' => (string)($decision['announcementDate'] ?? ''),
			'vertrouwelijkheid' => 'vertrouwelijk',
			'bewaartermijn' => 'P15Y',
		];

		$fileId = (string)(($decision['compositeContent']['fileId'] ?? ''));
		$result = $this->archivalAdapter->ingest($decisionId, $fileId, $metadata);

		$decision['archive'] = [
			'archivedOn' => (new DateTimeImmutable())->format('c'),
			'archiveId' => (string)$result['archiveId'],
			'tmloMetadata' => $metadata,
			'destructionDate' => (string)$result['destructionDate'],
		];
		$decision['currentStatus'] = 'archived';

		$saved = $this->repository->save(decision: $decision);
		$this->stateMachine->logTransition(
			$decisionId,
			$current,
			'archived',
			['actor' => 'systeem', 'actorType' => 'systeem', 'trigger' => 'automatic'],
		);

		return $saved;
	}//end archive()

	/**
	 * Flag required-but-empty fields with `_required` markers.
	 *
	 * @param array<string, mixed> $decision The beschikking.
	 *
	 * @return array<string, mixed>
	 */
	private function markRequiredFields(array $decision): array {
		if (($decision['rationale'] ?? null) === null || $decision['rationale'] === '') {
			$decision['motivering_required'] = true;
		}

		$addressee = (array)($decision['addressee'] ?? []);
		if (($addressee['name'] ?? '') === '') {
			$decision['geadresseerde_required'] = true;
		}

		return $decision;
	}//end markRequiredFields()
}//end class
