<?php

/**
 * Dossiq WOO Publication Service
 *
 * Bridges an assembled WOO besluit ({@see WOODecisionService}) to
 * OpenCatalogi's publication model. Builds a disclosure-safe publication
 * payload (redacted documents only, never unredacted originals or withheld
 * documents), creates/updates the publication via
 * {@see OCA\Dossiq\Service\Publication\OpenCatalogiApiClient}, and
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

use OCA\Dossiq\Service\Zaakdossier\AccessExceptionValue;
use OCA\Dossiq\Service\Publication\CatalogTransport;
use OCA\Dossiq\Service\Publication\PublicationSources;
use OCA\Dossiq\Service\Assessment\NothingToDisclose;
use OCA\Dossiq\Service\Publication\OpenCatalogiApiClient;
use OCA\Dossiq\Service\WooPublication\WooCategoryMapper;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCA\Dossiq\Woo\WooCaseLedger;
use OCA\Dossiq\Review\PagesSeen;
use OCA\Dossiq\Woo\WooDossierReturn;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for publishing WOO decisions through OpenCatalogi.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Thirteen, one over the threshold. The
 *   last is NothingToDisclose (decision D2), an optional collaborator the container
 *   wires like PagesSeen and WooDossierReturn beside it, so a publish with nothing
 *   to disclose by record publishes the decision alone. Splitting the publish
 *   step to save one class would put the refusal and its exception in two places.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Ten, for the same reason: four optional
 *   collaborators, each defaulting to the behaviour before it existed.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */
class WooPublicationService {

	/**
	 * The OpenCatalogi app identifier.
	 */
	private const OPENCATALOGI_APP_ID = 'opencatalogi';

	/**
	 * The `publicationKind` of a publication made from a Woo request case (C6).
	 */
	public const PUBLICATION_KIND = 'woo-besluit';

	/**
	 * The case's publication state once the decision is assembled (design D-2).
	 */
	public const STATUS_READY = 'ready';

	/**
	 * The case's publication state once the decision is public.
	 */
	public const STATUS_PUBLISHED = 'published';

	/**
	 * The case's publication state once the publication is withdrawn.
	 */
	public const STATUS_WITHDRAWN = 'withdrawn';

	/**
	 * Finds the case's Woo decision and mirrors the publication state onto the case.
	 *
	 * @var WooCaseLedger
	 */
	private readonly WooCaseLedger $caseLedger;

	/**
	 * Loads the case, the decision and the disclosable documents.
	 *
	 * @var PublicationSources
	 */
	private readonly PublicationSources $sources;

	/**
	 * Delivers the publication to the catalog, and withdraws it.
	 *
	 * @var CatalogTransport
	 */
	private readonly CatalogTransport $transport;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service.
	 * @param OpenCatalogiApiClient $apiClient Thin HTTP client to OpenCatalogi's register.
	 * @param WooCategoryMapper $categoryMapper DIWOO informatiecategorie mapper.
	 * @param IAppManager $appManager Nextcloud app manager for feature detection.
	 * @param LoggerInterface $logger Logger.
	 * @param WooDossierReturn|null $dossierReturn Brings the decision back to its source dossier (C6).
	 * @param WooCaseLedger|null $caseLedger Finds the case's Woo decision and writes the case's publication state.
	 * @param WooCaseDocuments|null $caseDocuments Loads a case document with its file content.
	 * @param PagesSeen|null $pagesSeen The pages seen per document; publishing waits for the required ones.
	 * @param NothingToDisclose|null $nothingToDisclose Whether the case records that nothing can be made public,
	 *        so the decision is published alone; without it an empty disclosable set is refused as before.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		OpenCatalogiApiClient $apiClient,
		private readonly WooCategoryMapper $categoryMapper,
		private readonly IAppManager $appManager,
		LoggerInterface $logger,
		private readonly ?WooDossierReturn $dossierReturn = null,
		?WooCaseLedger $caseLedger = null,
		?WooCaseDocuments $caseDocuments = null,
		private readonly ?PagesSeen $pagesSeen = null,
		private readonly ?NothingToDisclose $nothingToDisclose = null,
	) {
		$this->caseLedger = ($caseLedger ?? new WooCaseLedger(settingsService: $settingsService, logger: $logger));
		$this->sources    = new PublicationSources(settingsService: $settingsService, caseDocuments: $caseDocuments);
		$this->transport  = new CatalogTransport(settingsService: $settingsService, apiClient: $apiClient, logger: $logger, caseLedger: $this->caseLedger);
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
		return $this->sources->collectDelivery(assessments: $assessments, documentLoader: $documentLoader)['documents'];
	}//end selectDisclosableDocuments()

	/**
	 * The disclosable documents and, beside each, what the delivered set records of it.
	 *
	 * @param array<int, array<string, mixed>> $assessments    The case's document assessments.
	 * @param callable                         $documentLoader `fn(string $documentRef): ?array`.
	 *
	 * @return array{documents: array<int, array<string, mixed>>, items: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 * @spec openspec/specs/woo-delivered-set/spec.md#requirement-every-delivery-writes-a-set-with-its-own-identity-and-manifest-req-wds-001
	 */
	public function collectDelivery(array $assessments, callable $documentLoader): array {
		return $this->sources->collectDelivery(assessments: $assessments, documentLoader: $documentLoader);
	}//end collectDelivery()

	/**
	 * Build the OpenCatalogi publication payload for a WOO decision.
	 *
	 * @param array<string, mixed> $case The case object.
	 * @param array<string, mixed> $decision The assembled decision object.
	 *
	 * @return array<string, mixed> The publication payload.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
	 */
	public function buildPayload(array $case, array $decision): array {
		$category = $this->categoryMapper->forDecision($decision);
		$caseId = (string)($case['id'] ?? $case['uuid'] ?? $decision['case'] ?? '');

		$payload = [
			'title' => (string)($case['title'] ?? $decision['title'] ?? 'WOO-besluit ' . $caseId),
			'summary' => (string)($decision['description'] ?? ''),
			'description' => (string)($decision['explanation'] ?? $decision['description'] ?? ''),
			// Public read access is `publicationDate <= now` on a date-time
			// (opencatalogi publication schema), so the moment of publishing.
			'publicationDate' => date('c'),
			'status' => 'published',
			'publicationKind' => self::PUBLICATION_KIND,
			// OpenCatalogi's own information category: its Woo sitemap and the
			// search facet read it (hydra woo-citizen-journey C6, as settled).
			'wooCategory' => $category['code'],
			'caseReference' => $caseId,
		];

		$from = (string)($case['wooRequest']['periodeVan'] ?? '');
		$to = (string)($case['wooRequest']['periodeTot'] ?? '');
		if ($from !== '' || $to !== '') {
			$payload['period'] = ['from' => $from, 'to' => $to];
		}

		return $payload;
	}//end buildPayload()

	/**
	 * Publish (or republish) a WOO decision to OpenCatalogi.
	 *
	 * Idempotent per decision: republishing an already-published decision
	 * updates the existing OpenCatalogi publication rather than creating a
	 * duplicate (see design.md D6). Without a decision id the case's one Woo
	 * decision is used (design D-1). Afterwards the case reads `published`
	 * with the link, and a request started from a dossier gets the
	 * publication back in that dossier (D-7).
	 *
	 * @param string $caseId The case UUID.
	 * @param string $decisionId The decision UUID, or '' to use the case's Woo decision.
	 *
	 * @return array<string, mixed> `{available: bool, reason?: string, decisionIds?, publicationId?, publicationUrl?}`.
	 *
	 * @throws RuntimeException When the decision or case cannot be loaded.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publish-endpoints-find-the-cases-woo-decision-req-wpi-005
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-decision-comes-back-to-the-dossier-it-was-asked-from-req-wpi-008
	 */
	public function publish(string $caseId, string $decisionId = ''): array {
		$availability = $this->checkAvailability();
		if ($availability['available'] === false) {
			return $availability;
		}

		if ($decisionId === '') {
			$resolved = $this->caseLedger->resolveWooDecision(caseId: $caseId);
			if ($resolved['decisionId'] === '') {
				return $resolved['refusal'];
			}

			$decisionId = $resolved['decisionId'];
		}

		$unseen = $this->unseenPages(caseId: $caseId);
		if ($unseen !== []) {
			return ['available' => false, 'reason' => 'pages_unseen', 'unseen' => $unseen];
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$decisionSchema = $this->settingsService->getConfigValue('decision_schema');

		[$case, $decision] = $this->sources->caseAndDecision(
			objectService: $objectService,
			register: $register,
			decisionSchema: $decisionSchema,
			caseId: $caseId,
			decisionId: $decisionId,
		);

		$delivery = $this->sources->delivery(objectService: $objectService, register: $register, caseId: $caseId);
		$disclosable = $delivery['documents'];
		$refusal = $this->documentRefusal(documents: $disclosable);
		if ($refusal !== null) {
			return $refusal;
		}

		if (count($disclosable) === 0
			&& $this->nothingToDisclose?->recorded(case: $case, caseId: $caseId, assessments: $delivery['assessments']) !== true
		) {
			return ['available' => false, 'reason' => 'no_publishable_documents'];
		}

		$payload = $this->buildPayload(case: $case, decision: $decision);
		$existingId = (string)($decision['wooPublication']['publicationId'] ?? '');

		// The delivered set is written before anything goes out (REQ-WDS-001).
		$delivered = $this->transport->deliver(
			caseId: $caseId,
			decisionId: $decisionId,
			items: $delivery['items'],
			payload: $payload,
			disclosable: $disclosable,
			existingId: $existingId,
		);
		if (isset($delivered['reason']) === true) {
			return ['available' => false, 'reason' => $delivered['reason']];
		}

		$publicationId = $delivered['publicationId'];

		$publicationUrl = $this->buildPublicationUrl(publicationId: $publicationId);

		$decision['wooPublication'] = [
			'publicationId' => $publicationId,
			'publicationUrl' => $publicationUrl,
			'status' => self::STATUS_PUBLISHED,
			'category' => $payload['wooCategory'],
			'publishedAt' => date('c'),
		];

		$objectService->saveObject(object: $decision, register: $register, schema: $decisionSchema, uuid: $decisionId);

		$withheld = $this->caseLedger->recordWithheld(publicationId: $publicationId, assessments: $delivery['assessments']);
		$this->caseLedger->writeCaseState(
			caseId: $caseId,
			changes: [
				'wooPublicationStatus' => self::STATUS_PUBLISHED,
				'wooPublicationUrl' => $this->caseLedger->absolute(path: $publicationUrl),
				'wooWithheldNote' => $withheld['note'],
			],
		);

		$this->dossierReturn?->append(case: $case, publicationId: $publicationId, title: (string)$payload['title']);

		$this->dossierReturn?->tellTheResident(case: $case, caseId: $caseId, publicationId: $publicationId);

		return [
			'available' => true,
			'publicationId' => $publicationId,
			'publicationUrl' => $publicationUrl,
			'deliveredSet' => $delivered['setId'],
			'withheldRecord' => $withheld['record'],
		];
	}//end publish()




	/**
	 * The disclosable documents whose file could not be read.
	 *
	 * Each of them would go out as a publication entry without its file, so a
	 * publication with one of them is refused instead.
	 *
	 * @param array<int, array<string, mixed>> $documents The disclosable documents.
	 *
	 * @return list<string> Their names.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	private function unreadable(array $documents): array {
		$names = [];
		foreach ($documents as $document) {
			if (empty($document['content']) === true) {
				$names[] = (string)($document['fileName'] ?? ($document['title'] ?? ($document['id'] ?? '')));
			}
		}

		return $names;
	}//end unreadable()

	/**
	 * The refusal a set of disclosable documents earns: one whose file cannot be read, or one under an access
	 * exception. Null when the set may go out.
	 *
	 * @param array<int, array<string, mixed>> $documents The disclosable documents.
	 *
	 * @return array{available: false, reason: string, documents: list<string>}|null The refusal.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-with-an-exception-is-never-published
	 */
	private function documentRefusal(array $documents): ?array {
		$unreadable = $this->unreadable(documents: $documents);
		if ($unreadable !== []) {
			return ['available' => false, 'reason' => 'documents_unreadable', 'documents' => $unreadable];
		}

		$restricted = $this->restricted(documents: $documents);
		if ($restricted !== []) {
			return ['available' => false, 'reason' => 'documents_restricted', 'documents' => $restricted];
		}

		return null;
	}//end documentRefusal()

	/**
	 * The disclosable documents that name their only readers: an access exception and a publication contradict
	 * each other, so the publication is refused until the exception is lifted.
	 *
	 * @param array<int, array<string, mixed>> $documents The disclosable documents.
	 *
	 * @return list<string> Their names.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-with-an-exception-is-never-published
	 */
	private function restricted(array $documents): array {
		$value = new AccessExceptionValue();
		$names = [];
		foreach ($documents as $document) {
			if ($value->exceptionOf(record: $document) !== null) {
				$names[] = (string)($document['fileName'] ?? ($document['title'] ?? ($document['id'] ?? '')));
			}
		}

		return $names;
	}//end restricted()

	/**
	 * The in-scope documents with required pages nobody has seen yet.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, list<int>> The unseen pages by document; empty without the review schema.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	private function unseenPages(string $caseId): array {
		return ($this->pagesSeen?->unseenInCase(caseId: $caseId) ?? []);
	}//end unseenPages()

	/**
	 * Withdraw (depublish) a previously published WOO decision.
	 *
	 * @param string $decisionId The decision UUID, or '' to use the case's Woo decision.
	 * @param string $caseId The case UUID; needed when no decision id is given.
	 *
	 * @return array<string, mixed> `{available: bool, reason?: string}`.
	 *
	 * @throws RuntimeException When the decision cannot be loaded.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publish-endpoints-find-the-cases-woo-decision-req-wpi-005
	 */
	public function withdraw(string $decisionId, string $caseId = ''): array {
		$availability = $this->checkAvailability();
		if ($availability['available'] === false) {
			return $availability;
		}

		if ($decisionId === '') {
			$resolved = $this->caseLedger->resolveWooDecision(caseId: $caseId);
			if ($resolved['decisionId'] === '') {
				return $resolved['refusal'];
			}

			$decisionId = $resolved['decisionId'];
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$decisionSchema = $this->settingsService->getConfigValue('decision_schema');

		$decision = $this->sources->decision(objectService: $objectService, register: $register, decisionSchema: $decisionSchema, decisionId: $decisionId);

		$publicationId = (string)($decision['wooPublication']['publicationId'] ?? '');
		if ($publicationId === '') {
			return ['available' => false, 'reason' => 'no_publication'];
		}

		$reason = $this->transport->depublish(publicationId: $publicationId, decisionId: $decisionId);
		if ($reason !== '') {
			return ['available' => false, 'reason' => $reason];
		}

		$decision['wooPublication']['status'] = self::STATUS_WITHDRAWN;
		$decision['wooPublication']['withdrawnAt'] = date('c');

		// The set stays frozen and records the withdraw (REQ-WDS-002).
		$this->caseLedger->markWithdrawn(caseId: (string)($decision['case'] ?? $caseId), publicationId: $publicationId);

		$objectService->saveObject(object: $decision, register: $register, schema: $decisionSchema, uuid: $decisionId);

		// A withdrawn publication withholds nothing any more: its list is cleared.
		$withheld = $this->caseLedger->recordWithheld(publicationId: $publicationId, assessments: []);
		$this->caseLedger->writeCaseState(
			caseId: (string)($decision['case'] ?? $caseId),
			changes: ['wooPublicationStatus' => self::STATUS_WITHDRAWN, 'wooWithheldNote' => $withheld['note']],
		);

		return ['available' => true, 'withheldRecord' => $withheld['record']];
	}//end withdraw()

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
