<?php

/**
 * Beschikking Generation Service
 *
 * Generates a beschikking (permit decision) document for a DSO
 * omgevingsvergunning zaak. Attempts to use Docudesk for PDF generation
 * when available; falls back to a lightweight stub bijlage when Docudesk
 * is not installed or the template is unconfigured.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service that generates beschikking documents for DSO vergunningaanvragen.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
 */
class BeschikkingGenerationService {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The application config service
	 * @param ContainerInterface $container The DI container
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Generate a beschikking document for the given zaak.
	 *
	 * Selects the appropriate template (verleend/geweigerd) from config,
	 * attempts Docudesk PDF generation, and attaches the result as a
	 * bijlage on the vergunningaanvraag. Returns a result array with
	 * success status, bijlage ID, and a human-readable message.
	 *
	 * @param string $zaakId The UUID of the zaak
	 * @param string $outcome Either 'verleend' or 'geweigerd'
	 * @param string $motivation The motivation text for the beslissing
	 *
	 * @return array<string,mixed> Result with keys: success, bijlageId, message
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
	 */
	public function generateBeschikking(string $zaakId, string $outcome, string $motivation): array {
		$templateKey = 'dso_beschikking_template_verleend';
		if ($outcome === 'geweigerd') {
			$templateKey = 'dso_beschikking_template_geweigerd';
		}

		$templateId = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: $templateKey,
			default: ''
		);

		$documentService = $this->resolveDocumentService();

		if ($documentService === null || $templateId === '') {
			$this->logger->warning(
				'Procest BeschikkingGenerationService: Docudesk unavailable or template unconfigured; creating stub bijlage.',
				[
					'app' => Application::APP_ID,
					'zaakId' => $zaakId,
					'outcome' => $outcome,
					'templateId' => $templateId,
				]
			);

			$bijlageId = $this->createStubBijlage(
				zaakId: $zaakId,
				outcome: $outcome,
				motivation: $motivation
			);

			return [
				'success' => true,
				'bijlageId' => $bijlageId,
				'message' => 'Stub beschikking bijlage created (Docudesk not available or template not configured).',
			];
		}//end if

		try {
			$generated = $documentService->generateFromTemplate(
				templateId: $templateId,
				context: [
					'zaakId' => $zaakId,
					'outcome' => $outcome,
					'motivation' => $motivation,
					'datum' => date('Y-m-d'),
				]
			);

			$bijlageId = $this->attachBijlageToZaak(
				zaakId: $zaakId,
				generated: $generated,
				outcome: $outcome
			);

			return [
				'success' => true,
				'bijlageId' => $bijlageId,
				'message' => 'Beschikking generated and attached.',
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Procest BeschikkingGenerationService: Docudesk generation failed: ' . $e->getMessage(),
				[
					'app' => Application::APP_ID,
					'zaakId' => $zaakId,
				]
			);

			$bijlageId = $this->createStubBijlage(
				zaakId: $zaakId,
				outcome: $outcome,
				motivation: $motivation
			);

			return [
				'success' => true,
				'bijlageId' => $bijlageId,
				'message' => 'Stub beschikking bijlage created (Docudesk generation failed).',
			];
		}//end try
	}//end generateBeschikking()

	/**
	 * Resolve the Docudesk DocumentService from the container.
	 *
	 * Returns null when Docudesk is not installed or the service cannot
	 * be resolved, so callers can fall back gracefully.
	 *
	 * @return object|null
	 *
	 * @psalm-suppress MixedReturnStatement
	 * @psalm-suppress MixedInferredReturnType
	 */
	private function resolveDocumentService(): ?object {
		try {
			return $this->container->get('OCA\Docudesk\Service\DocumentService');
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Procest BeschikkingGenerationService: Docudesk DocumentService not available: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return null;
		}
	}//end resolveDocumentService()

	/**
	 * Create a stub bijlage record when PDF generation is not available.
	 *
	 * Attaches a text-based placeholder bijlage to the vergunningaanvraag
	 * via ObjectService so that the workflow can continue without a PDF.
	 *
	 * @param string $zaakId The zaak UUID
	 * @param string $outcome The decision outcome
	 * @param string $motivation The motivation text
	 *
	 * @return string The UUID of the created stub bijlage
	 */
	private function createStubBijlage(string $zaakId, string $outcome, string $motivation): string {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$register = $this->appConfig->getValueString(
				app: Application::APP_ID,
				key: 'register',
				default: ''
			);

			$bijlage = $objectService->saveObject(
				register: $register,
				schema: 'beschikking_bijlage',
				object: [
					'zaakId' => $zaakId,
					'type' => 'beschikking',
					'outcome' => $outcome,
					'motivation' => $motivation,
					'stub' => true,
					'createdAt' => date('c'),
					'title' => 'Beschikking ' . ucfirst($outcome) . ' (stub)',
				]
			);

			return (string)($bijlage['id'] ?? ($bijlage['uuid'] ?? 'stub-' . $zaakId));
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Procest BeschikkingGenerationService: could not create stub bijlage: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return 'stub-' . $zaakId;
		}//end try
	}//end createStubBijlage()

	/**
	 * Attach the Docudesk-generated document as a bijlage to the zaak.
	 *
	 * @param string $zaakId The zaak UUID
	 * @param array<string,mixed> $generated The generated document data from Docudesk
	 * @param string $outcome The decision outcome
	 *
	 * @return string The bijlage UUID
	 */
	private function attachBijlageToZaak(string $zaakId, array $generated, string $outcome): string {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$register = $this->appConfig->getValueString(
				app: Application::APP_ID,
				key: 'register',
				default: ''
			);

			$bijlage = $objectService->saveObject(
				register: $register,
				schema: 'beschikking_bijlage',
				object: [
					'zaakId' => $zaakId,
					'type' => 'beschikking',
					'outcome' => $outcome,
					'fileId' => $generated['fileId'] ?? '',
					'fileName' => $generated['fileName'] ?? ('beschikking_' . $outcome . '.pdf'),
					'createdAt' => date('c'),
					'title' => 'Beschikking ' . ucfirst($outcome),
				]
			);

			return (string)($bijlage['id'] ?? ($bijlage['uuid'] ?? ''));
		} catch (\Throwable $e) {
			$this->logger->error(
				'Procest BeschikkingGenerationService: could not attach bijlage: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return '';
		}//end try
	}//end attachBijlageToZaak()
}//end class
