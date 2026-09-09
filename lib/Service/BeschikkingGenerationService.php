<?php

/**
 * Beschikking Generation Service
 *
 * Generates a beschikking (permit decision) document for a DSO
 * omgevingsvergunning zaak. Attempts to use filinq for PDF generation
 * when available; falls back to a lightweight stub bijlage when filinq
 * is not installed or the template is unconfigured.
 *
 * FILINQ IS `docudesk` RENAMED, and the rename moved the PHP namespace with
 * the app id. This file resolved `OCA\Docudesk\Service\DocumentService`
 * from the container and caught the resulting failure, so on every current
 * instance the document app was reported "not available" and every
 * beschikking silently became a text stub. The name is resolved through
 * {@see FleetAppId} now, which tries each namespace the app has shipped
 * under.
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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Support\FleetAppId;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service that generates beschikking documents for DSO vergunningaanvragen.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
 */
class BeschikkingGenerationService {
	/**
	 * The document-generating app, by its CANONICAL (current) name.
	 *
	 * Passed to {@see FleetAppId}, never written into a class name directly.
	 */
	private const DOCUMENT_APP = 'filinq';

	/**
	 * The service that renders a template, relative to the app's namespace root.
	 */
	private const DOCUMENT_SERVICE = 'Service\\DocumentService';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The application config service
	 * @param ContainerInterface $container The DI container
	 * @param IUserSession $userSession Supplies the owner of the generated file
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Generate a beschikking document for the given zaak.
	 *
	 * Selects the appropriate template (verleend/geweigerd) from config,
	 * attempts filinq PDF generation, and attaches the result as a
	 * bijlage on the vergunningaanvraag. Returns a result array with
	 * success status, bijlage ID, and a human-readable message.
	 *
	 * @param string $caseId The UUID of the zaak
	 * @param string $outcome Either 'granted' or 'refused'
	 * @param string $motivation The motivation text for the beslissing
	 *
	 * @return array<string,mixed> Result with keys: success, bijlageId, message
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
	 */
	public function generateBeschikking(string $caseId, string $outcome, string $motivation): array {
		$templateKey = 'dso_beschikking_template_verleend';
		if ($outcome === 'refused') {
			$templateKey = 'dso_beschikking_template_geweigerd';
		}

		$templateId = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: $templateKey,
			default: ''
		);

		$documentService = $this->resolveDocumentService();
		$userId = $this->currentUserId();

		if ($documentService === null || $templateId === '' || $userId === '') {
			$this->logger->warning(
				'Dossiq BeschikkingGenerationService: filinq unavailable, template unconfigured or no session; creating stub bijlage.',
				[
					'app' => Application::APP_ID,
					'caseId' => $caseId,
					'outcome' => $outcome,
					'templateId' => $templateId,
					'hasDocumentService' => ($documentService !== null),
					'hasUser' => ($userId !== ''),
				]
			);

			$bijlageId = $this->createStubBijlage(
				caseId: $caseId,
				outcome: $outcome,
				motivation: $motivation
			);

			return [
				'success' => true,
				'bijlageId' => $bijlageId,
				'message' => 'Stub beschikking bijlage created (document generation not available or template not configured).',
			];
		}//end if

		try {
			$generated = $documentService->generateDocument(
				$templateId,
				[],
				[
					'format' => 'pdf',
					'caseId' => $caseId,
					'userId' => $userId,
					'filename' => 'beschikking_' . $outcome,
					'adHocData' => [
						'caseId' => $caseId,
						'outcome' => $outcome,
						'motivation' => $motivation,
						'date' => date('Y-m-d'),
					],
					'output' => ['mode' => 'files'],
				]
			);

			$bijlageId = $this->attachBijlageToZaak(
				caseId: $caseId,
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
				'Dossiq BeschikkingGenerationService: document generation failed: ' . $e->getMessage(),
				[
					'app' => Application::APP_ID,
					'caseId' => $caseId,
				]
			);

			$bijlageId = $this->createStubBijlage(
				caseId: $caseId,
				outcome: $outcome,
				motivation: $motivation
			);

			return [
				'success' => true,
				'bijlageId' => $bijlageId,
				'message' => 'Stub beschikking bijlage created (document generation failed).',
			];
		}//end try
	}//end generateBeschikking()

	/**
	 * Resolve filinq's DocumentService, whatever namespace it ships under.
	 *
	 * Returns null when filinq is not installed or the service cannot be
	 * resolved, so callers can fall back gracefully. That graceful fallback is
	 * exactly why the name has to be resolved rather than written: this method
	 * used to ask the container for `OCA\Docudesk\Service\DocumentService`,
	 * which no current instance registers, and the catch below turned the
	 * miss into a debug line nobody reads and a text stub in place of every
	 * beschikking PDF.
	 *
	 * @return object|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is a stateless resolver
	 *      over the app-id and namespace rename map; the answer depends on the
	 *      instance, not on any state this service holds.
	 *
	 * @psalm-suppress MixedReturnStatement
	 * @psalm-suppress MixedInferredReturnType
	 */
	private function resolveDocumentService(): ?object {
		$service = FleetAppId::getService(
			container: $this->container,
			canonical: self::DOCUMENT_APP,
			relative: self::DOCUMENT_SERVICE
		);

		if ($service === null) {
			$this->logger->debug(
				'Dossiq BeschikkingGenerationService: no DocumentService under any name filinq has shipped under.',
				['app' => Application::APP_ID]
			);
			return null;
		}

		if (method_exists($service, 'generateDocument') === false) {
			$this->logger->warning(
				'Dossiq BeschikkingGenerationService: the resolved DocumentService has no generateDocument(); '
				. 'filinq has changed its contract.',
				['app' => Application::APP_ID, 'class' => get_class($service)]
			);
			return null;
		}

		return $service;
	}//end resolveDocumentService()

	/**
	 * The uid of the user this generation runs for, or '' when there is none.
	 *
	 * Filinq stores the generated PDF in Files and requires an owner for it;
	 * a background caller without a session cannot generate one.
	 *
	 * @return string The uid, or '' when no user is signed in.
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end currentUserId()

	/**
	 * Create a stub bijlage record when PDF generation is not available.
	 *
	 * Attaches a text-based placeholder bijlage to the vergunningaanvraag
	 * via ObjectService so that the workflow can continue without a PDF.
	 *
	 * @param string $caseId The zaak UUID
	 * @param string $outcome The decision outcome
	 * @param string $motivation The motivation text
	 *
	 * @return string The UUID of the created stub bijlage
	 */
	private function createStubBijlage(string $caseId, string $outcome, string $motivation): string {
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
					'caseId' => $caseId,
					'type' => 'beschikking',
					'outcome' => $outcome,
					'motivation' => $motivation,
					'stub' => true,
					'createdAt' => date('c'),
					'title' => 'Beschikking ' . ucfirst($outcome) . ' (stub)',
				]
			);

			return (string)($bijlage['id'] ?? ($bijlage['uuid'] ?? 'stub-' . $caseId));
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq BeschikkingGenerationService: could not create stub bijlage: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return 'stub-' . $caseId;
		}//end try
	}//end createStubBijlage()

	/**
	 * Attach the generated document as a bijlage to the zaak.
	 *
	 * Filinq returns `{content, format, metadata, warnings, output}` and the
	 * stored file lives under `output` as `{mode, fileId, path, name, size}`.
	 * The top-level `fileId` / `fileName` this method used to read have never
	 * been keys of that array, so they are kept only as a fallback for a
	 * caller that hands over an already-flattened shape.
	 *
	 * @param string $caseId The zaak UUID
	 * @param array<string,mixed> $generated The generation envelope from filinq
	 * @param string $outcome The decision outcome
	 *
	 * @return string The bijlage UUID
	 */
	private function attachBijlageToZaak(string $caseId, array $generated, string $outcome): string {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$register = $this->appConfig->getValueString(
				app: Application::APP_ID,
				key: 'register',
				default: ''
			);

			$output = [];
			if (is_array($generated['output'] ?? null) === true) {
				$output = $generated['output'];
			}

			$bijlage = $objectService->saveObject(
				register: $register,
				schema: 'beschikking_bijlage',
				object: [
					'caseId' => $caseId,
					'type' => 'beschikking',
					'outcome' => $outcome,
					'fileId' => ($output['fileId'] ?? ($generated['fileId'] ?? '')),
					'fileName' => ($output['name'] ?? ($generated['fileName'] ?? ('beschikking_' . $outcome . '.pdf'))),
					'createdAt' => date('c'),
					'title' => 'Beschikking ' . ucfirst($outcome),
				]
			);

			return (string)($bijlage['id'] ?? ($bijlage['uuid'] ?? ''));
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq BeschikkingGenerationService: could not attach bijlage: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return '';
		}//end try
	}//end attachBijlageToZaak()
}//end class
