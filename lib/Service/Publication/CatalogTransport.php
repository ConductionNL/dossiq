<?php

/**
 * Dossiq catalog transport.
 *
 * The catalog side of a publication: the delivered set is written first and
 * the publication is created or updated in the catalog with every disclosable
 * file attached; a withdraw sets the depublication date. A transport failure
 * answers a reason, logged, so the caller refuses instead of failing.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Publication
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
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Publication;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Woo\WooCaseLedger;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers a publication to the catalog, and withdraws it.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */
class CatalogTransport {

	/**
	 * Constructor.
	 *
	 * @param SettingsService       $settingsService The catalog register and publication schema.
	 * @param OpenCatalogiApiClient $apiClient       Thin HTTP client to the catalog's register.
	 * @param LoggerInterface       $logger          Logger.
	 * @param WooCaseLedger         $caseLedger      Writes the delivered set around the send.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly OpenCatalogiApiClient $apiClient,
		private readonly LoggerInterface $logger,
		private readonly WooCaseLedger $caseLedger,
	) {
	}//end __construct()

	/**
	 * Record the delivered set, then create or update the publication with its files.
	 *
	 * THE SET FIRST (woo-delivered-set-is-a-record REQ-WDS-001): what goes
	 * out is recorded before it goes out, frozen after, deleted on failure.
	 *
	 * @param string                           $caseId      The case UUID.
	 * @param string                           $decisionId  The decision UUID.
	 * @param array<int, array<string, mixed>> $items       What the delivered set records of each document.
	 * @param array<string, mixed>             $payload     The publication payload.
	 * @param array<int, array<string, mixed>> $disclosable The disclosable documents.
	 * @param string                           $existingId  A prior publication id, or '' to create new.
	 *
	 * @return array{publicationId: string, setId: string}|array{reason: string} The publication and set, or why not.
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-every-delivery-writes-a-set-with-its-own-identity-and-manifest-req-wds-001
	 */
	public function deliver(string $caseId, string $decisionId, array $items, array $payload, array $disclosable, string $existingId): array {
		try {
			$delivered = $this->caseLedger->deliver(
				caseId: $caseId,
				decisionId: $decisionId,
				items: $items,
				send: fn (): string => $this->sendPublicationToOpenCatalogi(payload: $payload, disclosable: $disclosable, existingId: $existingId),
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'WooPublicationService::publish failed',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'decisionId' => $decisionId, 'error' => $e->getMessage()],
			);
			$reason = 'opencatalogi_api_error';
			if ($e->getMessage() === WooCaseLedger::SET_NOT_WRITTEN) {
				$reason = 'delivered_set_not_written';
			}

			return ['reason' => $reason];
		}

		$this->logger->info(
			'WOO decision published to OpenCatalogi: ' . $delivered['publicationId'] . ' for case ' . $caseId,
			['app' => Application::APP_ID],
		);

		return $delivered;
	}//end deliver()

	/**
	 * End public read access to a publication.
	 *
	 * @param string $publicationId The publication id.
	 * @param string $decisionId    The decision UUID, for the log.
	 *
	 * @return string '' when withdrawn, or the reason it was not.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function depublish(string $publicationId, string $decisionId): string {
		try {
			$this->apiClient->updatePublication(
				register: $this->settingsService->getWooPublicationConfigValue('woo_publication_register'),
				schema: $this->settingsService->getWooPublicationConfigValue('woo_publication_schema'),
				id: $publicationId,
				// The schema's own field: public read access ends when it is past.
				payload: ['depublicationDate' => date('c')],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'WooPublicationService::withdraw failed',
				['app' => Application::APP_ID, 'decisionId' => $decisionId, 'error' => $e->getMessage()],
			);
			return 'opencatalogi_api_error';
		}

		$this->logger->info(
			'WOO publication withdrawn: ' . $publicationId,
			['app' => Application::APP_ID],
		);

		return '';
	}//end depublish()

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

		$publication = null;
		if ($existingId !== '') {
			$publication = $this->apiClient->updatePublication(register: $ocRegister, schema: $ocSchema, id: $existingId, payload: $payload);
		}

		if ($publication === null) {
			$publication = $this->apiClient->createPublication(register: $ocRegister, schema: $ocSchema, payload: $payload);
		}

		$publicationId = (string)($publication['id'] ?? $publication['uuid'] ?? $existingId);

		// Documents are files on the publication itself (attachments-are-files):
		// opencatalogi's register has no `document` schema to create rows in.
		foreach ($disclosable as $document) {
			$this->attachDisclosableFile(
				ocRegister: $ocRegister,
				ocSchema: $ocSchema,
				publicationId: $publicationId,
				document: $document,
			);
		}

		return $publicationId;
	}//end sendPublicationToOpenCatalogi()

	/**
	 * Attach one disclosable document's file to the publication.
	 *
	 * A document without content has nothing to attach and is skipped.
	 *
	 * @param string $ocRegister The OpenCatalogi register slug.
	 * @param string $ocSchema The OpenCatalogi publication schema slug.
	 * @param string $publicationId The publication the file is attached to.
	 * @param array<string, mixed> $document The disclosable document (dossiq shape).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
	 */
	private function attachDisclosableFile(string $ocRegister, string $ocSchema, string $publicationId, array $document): void {
		$content = ($document['content'] ?? null);
		if (empty($content) === true) {
			return;
		}

		$title = (string)($document['title'] ?? $document['fileName'] ?? 'document');
		$this->apiClient->attachFile(
			register: $ocRegister,
			schema: $ocSchema,
			objectId: $publicationId,
			fileName: (string)($document['fileName'] ?? $title),
			base64Content: (string)$content,
			mimeType: (string)($document['format'] ?? 'application/octet-stream'),
		);
	}//end attachDisclosableFile()
}//end class
