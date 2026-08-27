<?php

/**
 * Dossiq WOO Publication Service
 *
 * Bridges an assembled WOO besluit ({@see WOODecisionService}) to
 * OpenCatalogi's publication model. Builds a disclosure-safe publication
 * payload (redacted documents only, never unredacted originals or withheld
 * documents), creates/updates the publication via
 * {@see OCA\Dossiq\Service\WooPublication\OpenCatalogiApiClient}, and
 * writes the resulting publication id/url/status back onto the dossiq
 * `decision` object through a single `ObjectService::saveObject()` call.
 *
 * OpenCatalogi is a same-instance peer app, consumed only if installed and
 * enabled — there is no hard dependency. Absence of the app, of
 * OpenRegister, or of any publishable document all degrade gracefully
 * (`checkAvailability()`), never throwing an exception into the WOO case
 * flow.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\WooPublication\OpenCatalogiApiClient;
use OCA\Dossiq\Service\WooPublication\WooCategoryMapper;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service for publishing WOO decisions through OpenCatalogi.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */
class WooPublicationService {

	use SearchesObjects;

	/**
	 * The OpenCatalogi app identifier.
	 */
	private const OPENCATALOGI_APP_ID = 'opencatalogi';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service.
	 * @param OpenCatalogiApiClient $apiClient Thin HTTP client to OpenCatalogi's register.
	 * @param WooCategoryMapper $categoryMapper DIWOO informatiecategorie mapper.
	 * @param IAppManager $appManager Nextcloud app manager for feature detection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly OpenCatalogiApiClient $apiClient,
		private readonly WooCategoryMapper $categoryMapper,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check whether WOO publication is currently possible.
	 *
	 * @return array{available: bool, reason?: string} Availability status.
	 *
	 * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d5
	 */
	public function checkAvailability(): array {
		if ($this->isOpenCatalogiInstalled() === false) {
			return ['available' => false, 'reason' => 'opencatalogi_not_installed'];
		}

		if ($this->settingsService->getObjectService() === null) {
			return ['available' => false, 'reason' => 'openregister_unavailable'];
		}

		return ['available' => true];
	}//end checkAvailability()

	/**
	 * Whether OpenCatalogi is installed and enabled.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d5
	 */
	public function isOpenCatalogiInstalled(): bool {
		return $this->appManager->isInstalled(self::OPENCATALOGI_APP_ID)
			&& $this->appManager->isEnabledForUser(self::OPENCATALOGI_APP_ID);
	}//end isOpenCatalogiInstalled()

	/**
	 * Select the documents that may be disclosed in a WOO publication.
	 *
	 * `niet_openbaar` documents are always excluded. `deels_openbaar`
	 * documents are included only via a finalized `redactedDocumentRef`
	 * (never their original content). `openbaar` documents are included
	 * as-is. See design.md D4 — this is the one place that enforces the
	 * "never publish an unredacted original" invariant.
	 *
	 * @param array<int, array<string, mixed>> $assessments The case's document assessments
	 *                                                      (`documentRef`,
	 *                                                      `classification`, optional
	 *                                                      `redactedDocumentRef`).
	 * @param callable $documentLoader `fn(string $documentRef): ?array`
	 *                                 resolves a document id to its
	 *                                 content/metadata.
	 *
	 * @return array<int, array<string, mixed>> Disclosable documents, each carrying the
	 *                                          resolved (redacted, where applicable) content.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function selectDisclosableDocuments(array $assessments, callable $documentLoader): array {
		$disclosable = [];

		foreach ($assessments as $assessment) {
			$classification = (string)($assessment['classification'] ?? '');

			if ($classification === 'niet_openbaar') {
				continue;
			}

			if ($classification === 'openbaar') {
				$documentRef = (string)($assessment['documentRef'] ?? '');
				$document = $documentLoader($documentRef);
				if ($document !== null) {
					$disclosable[] = $document;
				}

				continue;
			}

			if ($classification === 'deels_openbaar') {
				$redactedRef = $assessment['redactedDocumentRef'] ?? null;
				if (empty($redactedRef) === true) {
					// No finalized redaction yet — exclude. Never fall back to the original.
					continue;
				}

				$redactedDocument = $documentLoader((string)$redactedRef);
				if ($redactedDocument !== null) {
					$disclosable[] = $redactedDocument;
				}
			}
		}//end foreach

		return $disclosable;
	}//end selectDisclosableDocuments()

	/**
	 * Build the OpenCatalogi publication payload for a WOO decision.
	 *
	 * @param array<string, mixed> $case The case object.
	 * @param array<string, mixed> $decision The assembled decision object.
	 * @param array<int, array<string, mixed>> $disclosable Disclosable documents (see
	 *                                                      {@see self::selectDisclosableDocuments()}).
	 *
	 * @return array<string, mixed> The publication payload.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function buildPayload(array $case, array $decision, array $disclosable): array {
		$category = $this->categoryMapper->forDecision($decision);
		$caseId = (string)($case['id'] ?? $case['uuid'] ?? $decision['case'] ?? '');

		return [
			'title' => (string)($case['title'] ?? $decision['title'] ?? 'WOO-besluit ' . $caseId),
			'summary' => (string)($decision['description'] ?? ''),
			'description' => (string)($decision['explanation'] ?? $decision['description'] ?? ''),
			'publicationDate' => (string)($decision['decisionDate'] ?? date('Y-m-d')),
			'tooiCategorieUri' => $category['uri'],
			'tooiCategorieNaam' => $category['label'],
			'status' => 'published',
			'caseReference' => $caseId,
			'documentCount' => count($disclosable),
		];
	}//end buildPayload()

	/**
	 * Publish (or republish) a WOO decision to OpenCatalogi.
	 *
	 * Idempotent per decision: republishing an already-published decision
	 * updates the existing OpenCatalogi publication rather than creating a
	 * duplicate (see design.md D6).
	 *
	 * @param string $caseId The case UUID.
	 * @param string $decisionId The decision UUID (as assembled by WOODecisionService).
	 *
	 * @return array<string, mixed> `{available: bool, reason?: string, publicationId?, publicationUrl?}`.
	 *
	 * @throws RuntimeException When the decision or case cannot be loaded.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function publish(string $caseId, string $decisionId): array {
		$availability = $this->checkAvailability();
		if ($availability['available'] === false) {
			return $availability;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$decisionSchema = $this->settingsService->getConfigValue('decision_schema');

		[$case, $decision] = $this->loadCaseAndDecision(
			objectService: $objectService,
			register: $register,
			decisionSchema: $decisionSchema,
			caseId: $caseId,
			decisionId: $decisionId,
		);

		$disclosable = $this->loadDisclosableDocuments(objectService: $objectService, register: $register, caseId: $caseId);
		if (count($disclosable) === 0) {
			return ['available' => false, 'reason' => 'no_publishable_documents'];
		}

		$payload = $this->buildPayload(case: $case, decision: $decision, disclosable: $disclosable);
		$existingId = (string)($decision['wooPublication']['publicationId'] ?? '');

		try {
			$publicationId = $this->sendPublicationToOpenCatalogi(payload: $payload, disclosable: $disclosable, existingId: $existingId);
		} catch (Throwable $e) {
			$this->logger->error(
				'WooPublicationService::publish failed',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'decisionId' => $decisionId, 'error' => $e->getMessage()],
			);
			return ['available' => false, 'reason' => 'opencatalogi_api_error'];
		}

		$publicationUrl = $this->buildPublicationUrl(publicationId: $publicationId);

		$decision['wooPublication'] = [
			'publicationId' => $publicationId,
			'publicationUrl' => $publicationUrl,
			'status' => 'published',
			'category' => $payload['tooiCategorieUri'],
			'publishedAt' => date('c'),
		];

		$objectService->saveObject(object: $decision, register: $register, schema: $decisionSchema, uuid: $decisionId);

		$this->logger->info(
			'WOO decision published to OpenCatalogi: ' . $publicationId . ' for case ' . $caseId,
			['app' => Application::APP_ID],
		);

		return [
			'available' => true,
			'publicationId' => $publicationId,
			'publicationUrl' => $publicationUrl,
		];
	}//end publish()

	/**
	 * Load the case and decision objects for a publish/withdraw request.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The dossiq register slug.
	 * @param string $decisionSchema The dossiq decision schema slug.
	 * @param string $caseId The case UUID.
	 * @param string $decisionId The decision UUID.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>} `[$case, $decision]`.
	 *
	 * @throws RuntimeException When either object cannot be loaded.
	 */
	private function loadCaseAndDecision(object $objectService, string $register, string $decisionSchema, string $caseId, string $decisionId): array {
		$caseSchema = $this->settingsService->getConfigValue('case_schema');

		$case = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $caseSchema, id: $caseId);
		if ($case === null) {
			throw new RuntimeException('Case not found: ' . $caseId);
		}

		$decision = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $decisionSchema, id: $decisionId);
		if ($decision === null) {
			throw new RuntimeException('Decision not found: ' . $decisionId);
		}

		return [$case, $decision];
	}//end loadCaseAndDecision()

	/**
	 * Load and select the disclosable documents for a case's WOO assessments.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The dossiq register slug.
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, array<string, mixed>> The disclosable documents.
	 */
	private function loadDisclosableDocuments(object $objectService, string $register, string $caseId): array {
		$assessmentSchema = $this->settingsService->getConfigValue('woo_assessment_schema');
		$documentSchema = $this->settingsService->getConfigValue('document_schema');

		$assessments = [];
		if (empty($assessmentSchema) === false) {
			$assessments = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $assessmentSchema,
				filters: ['caseRef' => $caseId, '_limit' => 500],
			);
		}

		$documentLoader = function (string $documentRef) use ($objectService, $register, $documentSchema): ?array {
			if (empty($documentSchema) === true || $documentRef === '') {
				return null;
			}

			return $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $documentSchema, id: $documentRef);
		};

		return $this->selectDisclosableDocuments(assessments: $assessments, documentLoader: $documentLoader);
	}//end loadDisclosableDocuments()

	/**
	 * Create-or-update the publication in OpenCatalogi and attach every
	 * disclosable document to it.
	 *
	 * @param array<string, mixed> $payload The publication payload.
	 * @param array<int, array<string, mixed>> $disclosable The disclosable documents.
	 * @param string $existingId A prior publication id, or '' to create new.
	 *
	 * @return string The publication id.
	 *
	 * @throws Throwable Propagated from the API client on any transport failure.
	 */
	private function sendPublicationToOpenCatalogi(array $payload, array $disclosable, string $existingId): string {
		$ocRegister = $this->settingsService->getWooPublicationConfigValue('woo_publication_register');
		$ocSchema = $this->settingsService->getWooPublicationConfigValue('woo_publication_schema');
		$ocDocumentSchema = $this->settingsService->getWooPublicationConfigValue('woo_publication_document_schema');

		$publication = null;
		if ($existingId !== '') {
			$publication = $this->apiClient->updatePublication(register: $ocRegister, schema: $ocSchema, id: $existingId, payload: $payload);
		}

		if ($publication === null) {
			$publication = $this->apiClient->createPublication(register: $ocRegister, schema: $ocSchema, payload: $payload);
		}

		$publicationId = (string)($publication['id'] ?? $publication['uuid'] ?? $existingId);

		foreach ($disclosable as $document) {
			$this->attachDisclosableDocument(
				ocRegister: $ocRegister,
				ocDocumentSchema: $ocDocumentSchema,
				publicationId: $publicationId,
				document: $document,
			);
		}

		return $publicationId;
	}//end sendPublicationToOpenCatalogi()

	/**
	 * Withdraw (depublish) a previously published WOO decision.
	 *
	 * @param string $decisionId The decision UUID.
	 *
	 * @return array<string, mixed> `{available: bool, reason?: string}`.
	 *
	 * @throws RuntimeException When the decision cannot be loaded.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function withdraw(string $decisionId): array {
		$availability = $this->checkAvailability();
		if ($availability['available'] === false) {
			return $availability;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$decisionSchema = $this->settingsService->getConfigValue('decision_schema');

		$decision = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $decisionSchema, id: $decisionId);
		if ($decision === null) {
			throw new RuntimeException('Decision not found: ' . $decisionId);
		}

		$publicationId = (string)($decision['wooPublication']['publicationId'] ?? '');
		if ($publicationId === '') {
			return ['available' => false, 'reason' => 'no_publication'];
		}

		$ocRegister = $this->settingsService->getWooPublicationConfigValue('woo_publication_register');
		$ocSchema = $this->settingsService->getWooPublicationConfigValue('woo_publication_schema');

		try {
			$this->apiClient->updatePublication(
				register: $ocRegister,
				schema: $ocSchema,
				id: $publicationId,
				payload: ['depublicatiedatum' => date('c')],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'WooPublicationService::withdraw failed',
				['app' => Application::APP_ID, 'decisionId' => $decisionId, 'error' => $e->getMessage()],
			);
			return ['available' => false, 'reason' => 'opencatalogi_api_error'];
		}

		$decision['wooPublication']['status'] = 'withdrawn';
		$decision['wooPublication']['withdrawnAt'] = date('c');

		$objectService->saveObject(object: $decision, register: $register, schema: $decisionSchema, uuid: $decisionId);

		$this->logger->info(
			'WOO publication withdrawn: ' . $publicationId,
			['app' => Application::APP_ID],
		);

		return ['available' => true];
	}//end withdraw()

	/**
	 * Attach one disclosable document (+ its file content, when present) to
	 * a publication.
	 *
	 * @param string $ocRegister The OpenCatalogi register slug.
	 * @param string $ocDocumentSchema The OpenCatalogi document schema slug.
	 * @param string $publicationId The publication id to link to.
	 * @param array<string, mixed> $document The disclosable document (dossiq shape).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	private function attachDisclosableDocument(
		string $ocRegister,
		string $ocDocumentSchema,
		string $publicationId,
		array $document,
	): void {
		$title = (string)($document['title'] ?? $document['fileName'] ?? 'document');
		$fileName = (string)($document['fileName'] ?? $title);
		$mimeType = (string)($document['format'] ?? 'application/octet-stream');

		$created = $this->apiClient->attachDocument(
			register: $ocRegister,
			schema: $ocDocumentSchema,
			payload: [
				'title' => $title,
				'filename' => $fileName,
				'mimeType' => $mimeType,
				'publication' => ['id' => $publicationId],
			],
		);

		$documentId = ($created['id'] ?? $created['uuid'] ?? null);
		$content = ($document['content'] ?? null);

		if ($documentId !== null && empty($content) === false) {
			$this->apiClient->attachFile(
				register: $ocRegister,
				schema: $ocDocumentSchema,
				objectId: (string)$documentId,
				fileName: $fileName,
				base64Content: (string)$content,
				mimeType: $mimeType,
			);
		}
	}//end attachDisclosableDocument()

	/**
	 * Build a stable reference URL for a publication.
	 *
	 * @param string $publicationId The publication id.
	 *
	 * @return string The publication's URL.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	private function buildPublicationUrl(string $publicationId): string {
		$catalogSlug = $this->settingsService->getConfigValue('woo_publication_catalog_slug', 'publication');

		return '/index.php/apps/opencatalogi/' . $catalogSlug . '/' . $publicationId;
	}//end buildPublicationUrl()
}//end class
