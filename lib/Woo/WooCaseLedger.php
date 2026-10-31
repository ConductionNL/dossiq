<?php

/**
 * Dossiq Woo case ledger
 *
 * The two things publishing reads and writes on the case itself
 * (woo-publish-decision-from-the-case D-1 and D-2): which decision on the case
 * is its Woo decision, and the publication state the case shows
 * (`wooPublicationStatus`, `wooPublicationUrl`). Split from
 * WooPublicationService so the publication flow reads on its own.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publish-endpoints-find-the-cases-woo-decision-req-wpi-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Publication\PublicationResultLink;
use OCA\Dossiq\Service\Publication\WithheldEntries;
use OCA\Dossiq\Service\Publication\WithheldListRecorder;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the case's Woo decision and writes the case's publication state.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publish-endpoints-find-the-cases-woo-decision-req-wpi-005
 */
class WooCaseLedger {

	use SearchesObjects;

	/**
	 * The failure a delivery answers when its set cannot be written first.
	 */
	public const SET_NOT_WRITTEN = WooDeliveredSetWriter::SET_NOT_WRITTEN;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register, schemas and OpenRegister.
	 * @param LoggerInterface $logger          Logger.
	 * @param IURLGenerator|null $urlGenerator Makes the publication link absolute.
	 * @param WooDeliveredSetWriter|null $deliveredSets Records what each delivery sent out (woo-delivered-set-is-a-record).
	 * @param WithheldListRecorder|null $withheldRecorder Records what a publication withheld in opencatalogi.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?IURLGenerator $urlGenerator = null,
		private readonly ?WooDeliveredSetWriter $deliveredSets = null,
		private readonly ?WithheldListRecorder $withheldRecorder = null,
	) {
	}//end __construct()

	/**
	 * Record what the publication withheld, configured for the Woo case type.
	 *
	 * The Woo case type configures the generic withheld list (decision 182):
	 * `niet_openbaar` is withheld in full, `weigeringsgronden` holds its
	 * grounds. The answer is reported, never thrown: the publication is not
	 * undone because its list could not be recorded. An empty assessment list
	 * clears the publication's list (a withdrawal).
	 *
	 * @param string                           $publicationId The publication.
	 * @param array<int, array<string, mixed>> $assessments   The case's assessments, [] to clear.
	 *
	 * @return array{record: array<string, mixed>, note: string} The recorder's answer and the case's sentence.
	 *
	 * @spec openspec/changes/woo-decision-records-what-was-withheld/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-published-woo-decision-records-its-withheld-documents-in-opencatalogi-req-wrw-001
	 * @spec openspec/changes/woo-decision-records-what-was-withheld/specs/woo-publication-via-opencatalogi/spec.md#requirement-without-opencatalogis-receiving-side-nothing-is-recorded-req-wrw-002
	 */
	public function recordWithheld(string $publicationId, array $assessments): array {
		if ($this->withheldRecorder === null) {
			return ['record' => ['status' => 'not-recorded', 'reason' => WithheldListRecorder::REASON_TOO_OLD], 'note' => ''];
		}

		$record = $this->withheldRecorder->record(
			publicationId: $publicationId,
			entries: (new WithheldEntries())->fromAssessments(
				assessments: $assessments,
				withheldValue: 'niet_openbaar',
				groundsField: 'weigeringsgronden',
			),
		);

		return ['record' => $record, 'note' => $this->withheldRecorder->describe(answer: $record)];
	}//end recordWithheld()

	/**
	 * Deliver through `$send`, recording the set around it when a writer is wired.
	 *
	 * @param string                           $caseId     The Woo case.
	 * @param string                           $decisionId The Woo decision.
	 * @param array<int, array<string, mixed>> $items      What goes out, one entry per document.
	 * @param callable(): string               $send       Creates or updates the publication; answers its id.
	 *
	 * @return array{publicationId: string, setId: string}
	 *
	 * @throws \Throwable The send's failure, after the pending set is gone; SET_NOT_WRITTEN when the set cannot be written.
	 *
	 * @spec openspec/specs/woo-delivered-set/spec.md#requirement-every-delivery-writes-a-set-with-its-own-identity-and-manifest-req-wds-001
	 */
	public function deliver(string $caseId, string $decisionId, array $items, callable $send): array {
		if ($this->deliveredSets === null) {
			return ['publicationId' => (string)$send(), 'setId' => ''];
		}

		return $this->deliveredSets->deliver(caseId: $caseId, decisionId: $decisionId, delivered: $items, send: $send);
	}//end deliver()

	/**
	 * Stamp the withdraw on the publication's frozen set; a failure is logged, the withdraw stands.
	 *
	 * @param string $caseId        The case.
	 * @param string $publicationId The withdrawn publication.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-delivered-set/spec.md#requirement-a-frozen-set-and-its-assessments-refuse-change-req-wds-002
	 */
	public function markWithdrawn(string $caseId, string $publicationId): void {
		try {
			$this->deliveredSets?->markWithdrawn(caseId: $caseId, publicationId: $publicationId);
		} catch (Throwable $e) {
			$this->logger->error(
				'WooPublicationService: the delivered set could not record the withdraw',
				['app' => Application::APP_ID, 'publicationId' => $publicationId, 'error' => $e->getMessage()]
			);
		}
	}//end markWithdrawn()

	/**
	 * An absolute link for the resident, or the path when no URL generator is wired.
	 *
	 * @param string $path The instance-local path.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
	 */
	public function absolute(string $path): string {
		if ($this->urlGenerator === null) {
			return $path;
		}

		return $this->urlGenerator->getAbsoluteURL($path);
	}//end absolute()

	/**
	 * The case's one Woo decision: the decision on the case that carries a `wooSummary`.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{decisionId: string, refusal: array<string, mixed>}
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publish-endpoints-find-the-cases-woo-decision-req-wpi-005
	 */
	public function resolveWooDecision(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$ids = [];
		if ($objectService !== null) {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $this->settingsService->getConfigValue('register'),
				schema: $this->settingsService->getConfigValue('decision_schema'),
				filters: ['case' => $caseId, '_limit' => 50],
			);
			foreach ($rows as $row) {
				if ((string)($row['case'] ?? '') !== $caseId || empty($row['wooSummary']) === true) {
					continue;
				}

				$ids[] = (string)($row['@self']['id'] ?? $row['id'] ?? $row['uuid'] ?? '');
			}
		}

		$ids = array_values(array_filter(array_unique($ids)));
		if (count($ids) === 1) {
			return ['decisionId' => $ids[0], 'refusal' => []];
		}

		if ($ids === []) {
			return ['decisionId' => '', 'refusal' => ['available' => false, 'reason' => 'no_woo_decision']];
		}

		return ['decisionId' => '', 'refusal' => ['available' => false, 'reason' => 'several_woo_decisions', 'decisionIds' => $ids]];
	}//end resolveWooDecision()

	/**
	 * Mirror the publication state onto the case (design D-2), through the PATCH seam.
	 *
	 * @param string               $caseId  The case UUID.
	 * @param array<string, mixed> $changes The two fields.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-sees-where-the-decision-became-public-req-wds-003
	 */
	public function writeCaseState(string $caseId, array $changes): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null || $caseId === '') {
			return;
		}

		// The requester's link follows the same write (woo-dossier-shared-with-the-requester REQ-WDS-003).
		$changes = array_merge($changes, (new PublicationResultLink())->changesFor(state: $changes));

		try {
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $this->settingsService->getConfigValue('register'),
				schema: $this->settingsService->getConfigValue('case_schema'),
				id: $caseId,
				changes: $changes,
			);
		} catch (Throwable $e) {
			// The decision holds the truth; the case mirror is corrected by the next write.
			$this->logger->warning(
				'WooPublicationService: the case could not show its publication state',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'error' => $e->getMessage()],
			);
		}
	}//end writeCaseState()
}//end class
