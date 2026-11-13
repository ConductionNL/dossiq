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
 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Support\FleetAppId;
use OCA\Dossiq\Support\NormalisesObjectRows;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service that generates beschikking documents for DSO vergunningaanvragen.
 *
 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T04
 */
class BeschikkingGenerationService {
	use NormalisesObjectRows;

	private const STUB_NOT_SAVED = 'could not create stub bijlage';
	private const ATTACH_NOT_SAVED = 'could not attach bijlage';

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
	 * @return array<string,mixed> Result with keys: success, bijlageId, message. `success` is false
	 *                             when no bijlage could be saved, and `bijlageId` is then ''.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T04
	 */
	public function generateBeschikking(string $caseId, string $outcome, string $motivation): array {
		$ask = ['caseId' => $caseId, 'outcome' => $outcome, 'motivation' => $motivation];
		$templateId = $this->templateFor(outcome: $outcome);
		$documentService = $this->resolveDocumentService();
		$userId = $this->currentUserId();

		if ($documentService === null || $templateId === '' || $userId === '') {
			return $this->unavailableAnswer(
				ask: $ask,
				found: ['templateId' => $templateId, 'hasDocumentService' => ($documentService !== null), 'hasUser' => ($userId !== '')]
			);
		}

		try {
			$generated = $documentService->generateDocument(
				$templateId,
				[],
				$this->generationOptions(ask: $ask, userId: $userId)
			);

			return $this->savedAnswer(
				bijlageId: $this->attachBijlageToZaak(caseId: $caseId, generated: $generated, outcome: $outcome),
				messages: ['Beschikking generated and attached.', 'Beschikking generated, but the bijlage could not be saved.']
			);
		} catch (\Throwable $e) {
			return $this->failedAnswer(ask: $ask, error: $e);
		}//end try
	}//end generateBeschikking()

	/**
	 * The configured template for an outcome: the refusal template for `refused`, the grant template otherwise.
	 *
	 * @param string $outcome The outcome.
	 *
	 * @return string The template id, or '' when none is configured.
	 */
	private function templateFor(string $outcome): string {
		$templateKey = 'dso_beschikking_template_verleend';
		if ($outcome === 'refused') {
			$templateKey = 'dso_beschikking_template_geweigerd';
		}

		return $this->appConfig->getValueString(
			app: AppIdentity::APP_ID,
			key: $templateKey,
			default: ''
		);
	}//end templateFor()

	/**
	 * What the document app is asked to render: a PDF owned by the session user, written to files.
	 *
	 * @param array{caseId: string, outcome: string, motivation: string} $ask    The case, outcome and motivation.
	 * @param string                                                      $userId The session user.
	 *
	 * @return array<string, mixed> The generation options.
	 */
	private function generationOptions(array $ask, string $userId): array {
		return [
			'format' => 'pdf',
			'caseId' => $ask['caseId'],
			'userId' => $userId,
			'filename' => 'beschikking_' . $ask['outcome'],
			'adHocData' => [
				'caseId' => $ask['caseId'],
				'outcome' => $ask['outcome'],
				'motivation' => $ask['motivation'],
				'date' => date('Y-m-d'),
			],
			'output' => ['mode' => 'files'],
		];
	}//end generationOptions()

	/**
	 * Say why the document could not be generated, and answer with a stub attachment.
	 *
	 * @param array{caseId: string, outcome: string, motivation: string} $ask   The case, outcome and motivation.
	 * @param array<string, mixed>                                        $found The template, and whether the document app and a session were found.
	 *
	 * @return array{success: bool, bijlageId: string, message: string} The answer.
	 */
	private function unavailableAnswer(array $ask, array $found): array {
		$this->logger->warning(
			'Dossiq BeschikkingGenerationService: filinq unavailable, template unconfigured or no session; creating stub bijlage.',
			['app' => AppIdentity::APP_ID, 'caseId' => $ask['caseId'], 'outcome' => $ask['outcome']] + $found
		);

		return $this->stubAnswer(
			ask: $ask,
			messages: [
				'Stub beschikking bijlage created (document generation not available or template not configured).',
				'Document generation is not available, and the stub bijlage could not be saved.',
			]
		);
	}//end unavailableAnswer()

	/**
	 * Log a failed generation, and answer with a stub attachment.
	 *
	 * @param array{caseId: string, outcome: string, motivation: string} $ask   The case, outcome and motivation.
	 * @param \Throwable                                                  $error What the document app threw.
	 *
	 * @return array{success: bool, bijlageId: string, message: string} The answer.
	 */
	private function failedAnswer(array $ask, \Throwable $error): array {
		$this->logger->error(
			'Dossiq BeschikkingGenerationService: document generation failed: ' . $error->getMessage(),
			['app' => AppIdentity::APP_ID, 'caseId' => $ask['caseId']]
		);

		return $this->stubAnswer(
			ask: $ask,
			messages: [
				'Stub beschikking bijlage created (document generation failed).',
				'Beschikking generation failed, and the stub bijlage could not be saved.',
			]
		);
	}//end failedAnswer()

	/**
	 * Save a stub attachment in place of the document, and answer with it.
	 *
	 * @param array{caseId: string, outcome: string, motivation: string} $ask      The case, outcome and motivation.
	 * @param array{0: string, 1: string}                                 $messages Why a stub was saved, and the message when it could not be.
	 *
	 * @return array{success: bool, bijlageId: string, message: string} The answer.
	 */
	private function stubAnswer(array $ask, array $messages): array {
		return $this->savedAnswer(
			bijlageId: $this->createStubBijlage(caseId: $ask['caseId'], outcome: $ask['outcome'], motivation: $ask['motivation']),
			messages: $messages
		);
	}//end stubAnswer()

	/**
	 * Answer with the saved bijlage, or with a failure when the save threw.
	 *
	 * @param string|null                 $bijlageId The saved bijlage's id, or null when it could not be saved.
	 * @param array{0: string, 1: string} $messages  The message when saved, and the message when not.
	 *
	 * @return array{success: bool, bijlageId: string, message: string} The answer.
	 */
	private function savedAnswer(?string $bijlageId, array $messages): array {
		if ($bijlageId === null) {
			return ['success' => false, 'bijlageId' => '', 'message' => $messages[1]];
		}

		return ['success' => true, 'bijlageId' => $bijlageId, 'message' => $messages[0]];
	}//end savedAnswer()

	/**
	 * The id of a saved object, which OpenRegister answers as an ObjectEntity.
	 *
	 * @param mixed  $saved    What saveObject() answered.
	 * @param string $fallback The id to answer when it carries none.
	 *
	 * @return string The id, uuid, or fallback.
	 */
	private function savedId(mixed $saved, string $fallback): string {
		$row = $this->objectToArray(row: $saved);

		return (string)($row['id'] ?? ($row['uuid'] ?? $fallback));
	}//end savedId()

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
				['app' => AppIdentity::APP_ID]
			);
			return null;
		}

		if (method_exists($service, 'generateDocument') === false) {
			$this->logger->warning(
				'Dossiq BeschikkingGenerationService: the resolved DocumentService has no generateDocument(); '
				. 'filinq has changed its contract.',
				['app' => AppIdentity::APP_ID, 'class' => get_class($service)]
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
	 * @return string|null The UUID of the created stub bijlage, or null when it could not be saved
	 */
	private function createStubBijlage(string $caseId, string $outcome, string $motivation): ?string {
		return $this->storeBijlage(
			object: [
				'caseId' => $caseId,
				'type' => 'beschikking',
				'outcome' => $outcome,
				'motivation' => $motivation,
				'stub' => true,
				'createdAt' => date('c'),
				'title' => 'Beschikking ' . ucfirst($outcome) . ' (stub)',
			],
			fallback: 'stub-' . $caseId,
			failure: self::STUB_NOT_SAVED
		);
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
	 * @return string|null The bijlage UUID, or null when it could not be saved
	 */
	private function attachBijlageToZaak(string $caseId, array $generated, string $outcome): ?string {
		$output = [];
		if (is_array($generated['output'] ?? null) === true) {
			$output = $generated['output'];
		}

		return $this->storeBijlage(
			object: [
				'caseId' => $caseId,
				'type' => 'beschikking',
				'outcome' => $outcome,
				'fileId' => ($output['fileId'] ?? ($generated['fileId'] ?? '')),
				'fileName' => ($output['name'] ?? ($generated['fileName'] ?? ('beschikking_' . $outcome . '.pdf'))),
				'createdAt' => date('c'),
				'title' => 'Beschikking ' . ucfirst($outcome),
			],
			fallback: '',
			failure: self::ATTACH_NOT_SAVED
		);
	}//end attachBijlageToZaak()

	/**
	 * Save a bijlage record in the configured register.
	 *
	 * @param array<string,mixed> $object   The bijlage record.
	 * @param string              $fallback The id answered when the store returns none.
	 * @param string              $failure  The log line for a save that fails.
	 *
	 * @return string|null The bijlage UUID, or null when it could not be saved
	 */
	private function storeBijlage(array $object, string $fallback, string $failure): ?string {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$register = $this->appConfig->getValueString(
				app: AppIdentity::APP_ID,
				key: 'register',
				default: ''
			);

			$bijlage = $objectService->saveObject(
				register: $register,
				schema: 'beschikking_bijlage',
				object: $object
			);

			return $this->savedId(saved: $bijlage, fallback: $fallback);
		} catch (\Throwable $e) {
			$this->logBijlageNotSaved(failure: $failure, error: $e);
			return null;
		}//end try
	}//end storeBijlage()

	/**
	 * Log a bijlage that could not be saved: an error for a generated document, a warning for a stub.
	 *
	 * @param string     $failure What could not be done.
	 * @param \Throwable $error   Why.
	 *
	 * @return void
	 */
	private function logBijlageNotSaved(string $failure, \Throwable $error): void {
		$message = 'Dossiq BeschikkingGenerationService: ' . $failure . ': ' . $error->getMessage();
		if ($failure === self::ATTACH_NOT_SAVED) {
			$this->logger->error($message, ['app' => AppIdentity::APP_ID]);
			return;
		}

		$this->logger->warning($message, ['app' => AppIdentity::APP_ID]);
	}//end logBijlageNotSaved()
}//end class
