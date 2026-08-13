<?php

/**
 * Procest Beschikking Service.
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
 * @package  OCA\Procest\Service
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T14
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCA\Procest\Service\Beschikking\ArchivalAdapterInterface;
use OCA\Procest\Service\Beschikking\AuditPacketBuilder;
use OCA\Procest\Service\Beschikking\BeschikkingRepository;
use OCA\Procest\Service\Beschikking\BezwaarTermijnScheduler;
use OCA\Procest\Service\Beschikking\MandaatVerifier;
use OCA\Procest\Service\Beschikking\SigningAdapterInterface;
use OCA\Procest\Service\Beschikking\TemplateEngineAdapterInterface;
use RuntimeException;

/**
 * Beschikking lifecycle orchestrator.
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T14
 */
class BeschikkingService {

	/**
	 * Fields that may NOT be edited once a beschikking is immutable.
	 *
	 * @var array<int, string>
	 */
	private const CONTENT_FIELDS = [
		'rationale',
		'beslissing',
		'geadresseerde',
		'decisionType',
		'rechtsmiddelenClausule',
		'feeAmount',
		'templateId',
	];

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
			'ontwerpVersion' => 1,
			'huidigeStatus' => 'ontwerp',
			'samengesteldeInhoud' => $composition,
			'geadresseerde' => (array)($overrides['geadresseerde'] ?? []),
			'beslissing' => (array)($overrides['beslissing'] ?? []),
			'rationale' => ($overrides['rationale'] ?? null),
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
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T07
	 */
	public function akkoord(string $decisionId, string $approvedBy): array {
		$decision = $this->repository->requireBeschikking(decisionId: $decisionId);
		$current = (string)($decision['huidigeStatus'] ?? '');

		if ($this->stateMachine->validateTransition($current, 'akkoord-mandaat') === false) {
			throw new RuntimeException('invalid_transition');
		}

		$regeling = $this->mandateVerifier->resolveMandaatRegeling(caseType: (string)($decision['caseType'] ?? ''));
		$niveau = $this->mandateVerifier->resolveNiveauForUser(
			regeling: $regeling,
			decision: $decision,
			approvedBy: $approvedBy
		);

		if ($niveau === null) {
			throw new RuntimeException('mandaat_insufficient');
		}

		$decision['mandateGranted'] = [
			'mandateSchemeId' => (string)($regeling['id'] ?? ($regeling['@self']['slug'] ?? '')),
			'mandateNiveau' => $niveau,
			'approvedBy' => $approvedBy,
			'approvedDate' => (new DateTimeImmutable())->format('c'),
		];
		$decision['huidigeStatus'] = 'akkoord-mandaat';

		$saved = $this->repository->save(decision: $decision);
		$this->stateMachine->logTransition(
			$decisionId,
			$current,
			'akkoord-mandaat',
			['actor' => $approvedBy, 'actorType' => 'medewerker', 'trigger' => 'handmatig'],
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
		$current = (string)($decision['huidigeStatus'] ?? '');

		if ($this->stateMachine->validateTransition($current, 'ondertekend') === false) {
			throw new RuntimeException('invalid_transition');
		}

		$fileId = (string)(($decision['samengesteldeInhoud']['fileId'] ?? ''));
		$signature = $this->signingAdapter->sign($fileId, $signatory, $tspProvider);

		$decision['signature'] = [
			'tspProvider' => $tspProvider,
			'tspProviderEidasId' => (string)($signature['tspProviderEidasId'] ?? ''),
			'signatory' => $signatory,
			'ondertekeningMoment' => (string)($signature['ondertekeningMoment'] ?? ''),
			'kind' => 'gekwalificeerde-elektronische-handtekening',
			'certificateSerialNumber' => (string)($signature['certificateSerialNumber'] ?? ''),
			'validationRapportId' => (string)($signature['validationRapportId'] ?? ''),
		];
		$decision['samengesteldeInhoud']['fileId'] = (string)($signature['signedBestandId'] ?? $fileId);
		$decision['huidigeStatus'] = 'ondertekend';

		$saved = $this->repository->save(decision: $decision);
		$this->stateMachine->logTransition(
			$decisionId,
			$current,
			'ondertekend',
			[
				'actor' => $signatory,
				'actorType' => 'medewerker',
				'trigger' => 'handmatig',
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
		$current = (string)($decision['huidigeStatus'] ?? '');

		if ($this->stateMachine->validateTransition($current, 'verzonden') === false) {
			throw new RuntimeException('invalid_transition');
		}

		$verzending = $this->berichtenbox->routeToBerichtenbox($decision);

		$bekendmaking = (new DateTimeImmutable())->format('Y-m-d');
		$term = $this->bezwaarScheduler->computeTermijn(bekendmaking: $bekendmaking);

		$decision['verzending'] = $verzending;
		$decision['bekendmakingDate'] = $bekendmaking;
		$decision['objectionTermEndDate'] = $term['endDate'];
		$decision['herinneringDate'] = $term['herinnering'];
		$decision['huidigeStatus'] = 'verzonden';

		$saved = $this->repository->save(decision: $decision);

		$this->bezwaarScheduler->createBezwaarTrigger(
			decisionId: $decisionId,
			bekendmaking: $bekendmaking,
			endDate: $term['endDate'],
			herinnering: $term['herinnering'],
		);

		$this->stateMachine->logTransition(
			$decisionId,
			$current,
			'verzonden',
			['actor' => $actor, 'actorType' => 'medewerker', 'trigger' => 'handmatig'],
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
		$status = (string)($decision['huidigeStatus'] ?? '');

		if ($this->stateMachine->isImmutable($status) === true) {
			foreach (array_keys($updates) as $field) {
				if (in_array($field, self::CONTENT_FIELDS, true) === true) {
					throw new RuntimeException('immutable');
				}
			}
		}

		foreach ($updates as $field => $value) {
			$decision[$field] = $value;
		}

		$decision['ontwerpVersion'] = ((int)($decision['ontwerpVersion'] ?? 1)) + 1;

		return $this->repository->save(decision: $decision);
	}//end updateFields()

	/**
	 * Verify whether a mandaat covers a decision. [T14 verifyMandaat]
	 *
	 * Delegates to {@see MandaatVerifier::verifyMandaat()}.
	 *
	 * @param array<string, mixed> $regeling The mandaatRegeling object.
	 * @param string $niveau The proposed approver level.
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
		string $niveau,
		float $amount,
		string $decisionType,
		string $caseType,
	): bool {
		return $this->mandateVerifier->verifyMandaat(
			regeling: $regeling,
			niveau: $niveau,
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
		$current = (string)($decision['huidigeStatus'] ?? '');

		if ($this->stateMachine->validateTransition($current, 'gearchiveerd') === false) {
			throw new RuntimeException('invalid_transition');
		}

		$metadata = [
			'schema' => 'TMLO-1.2',
			'identificatieKenmerk' => (string)($decision['reference'] ?? ''),
			'aggregatieniveau' => 'Archiefstuk',
			'creatieDatum' => (string)(($decision['mandateGranted']['approvedDate'] ?? '')),
			'bekendmakingDate' => (string)($decision['bekendmakingDate'] ?? ''),
			'vertrouwelijkheid' => 'vertrouwelijk',
			'bewaartermijn' => 'P15Y',
		];

		$fileId = (string)(($decision['samengesteldeInhoud']['fileId'] ?? ''));
		$result = $this->archivalAdapter->ingest($decisionId, $fileId, $metadata);

		$decision['archief'] = [
			'gearchiveerdOn' => (new DateTimeImmutable())->format('c'),
			'archiefId' => (string)$result['archiefId'],
			'tmloMetadata' => $metadata,
			'destructionDate' => (string)$result['destructionDate'],
		];
		$decision['huidigeStatus'] = 'gearchiveerd';

		$saved = $this->repository->save(decision: $decision);
		$this->stateMachine->logTransition(
			$decisionId,
			$current,
			'gearchiveerd',
			['actor' => 'systeem', 'actorType' => 'systeem', 'trigger' => 'automatisch'],
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

		$geadresseerde = (array)($decision['geadresseerde'] ?? []);
		if (($geadresseerde['name'] ?? '') === '') {
			$decision['geadresseerde_required'] = true;
		}

		return $decision;
	}//end markRequiredFields()
}//end class
