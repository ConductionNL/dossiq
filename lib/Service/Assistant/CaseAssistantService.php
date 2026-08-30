<?php

/**
 * Dossiq Case Assistant Service.
 *
 * Orchestrates one conversational turn on a case: loads the case via the
 * SAME OpenRegister read path (and therefore the same authorization
 * scoping) every other dossiq service uses, builds a bounded context of
 * ONLY the fields already shown on the case-detail page's own widgets (never
 * documents, contacts, or initiator PII), forwards it to Hermiq via
 * `HermiqAssistantClient`, persists the Hermiq session per (user, case) so
 * follow-up turns keep continuity, and records the exchange through the
 * existing `AiAuditService` audit sink.
 *
 * FLEET RULE: this class contains NO LLM/prompt logic — Hermiq owns the
 * conversation. This is context assembly + authorization + audit plumbing.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Assistant
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
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Assistant;

use Exception;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Ai\AiAuditService;
use OCA\Dossiq\Service\SettingsService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Orchestrates a case-assistant conversational turn.
 *
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */
class CaseAssistantService {
	/**
	 * Maximum accepted `message` length (characters).
	 *
	 * @var int
	 */
	private const MAX_MESSAGE_LENGTH = 4000;

	/**
	 * Maximum length of the `description` field included in the case
	 * summary sent to Hermiq (truncated, never the full field, to keep the
	 * forwarded context small and predictable).
	 *
	 * @var int
	 */
	private const MAX_DESCRIPTION_LENGTH = 500;

	/**
	 * Per-user config key prefix under which the last Hermiq session UUID for
	 * a case is stored (`IConfig::setUserValue`/`getUserValue`) — no new
	 * OpenRegister schema needed for this, and it is scoped per (user, case)
	 * by construction, so one user can never resume another user's Hermiq
	 * conversation via this surface.
	 *
	 * @var string
	 */
	private const SESSION_CONFIG_PREFIX = 'assistant_session_';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the OpenRegister ObjectService + config.
	 * @param HermiqAssistantClient $hermiqClient Thin HTTP client to Hermiq's assistant surface.
	 * @param AiAuditService $auditService Existing AI oversight audit sink.
	 * @param IConfig $config Per-user Hermiq session continuity storage.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly HermiqAssistantClient $hermiqClient,
		private readonly AiAuditService $auditService,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run one conversational turn on a case.
	 *
	 * @param string $userId The authenticated caller's user id.
	 * @param string $caseId The case id (must be readable by `$userId`).
	 * @param string $message The user's message text.
	 *
	 * @return array{reply: string, usage: array<string, int|float>}
	 *
	 * @throws Exception On validation failure (400) or an unreadable/unknown case (404).
	 * @throws HermiqAssistantException When Hermiq is unavailable or refuses the turn.
	 *
	 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
	 */
	public function converse(string $userId, string $caseId, string $message): array {
		$this->validateMessage(message: $message);

		$caseData = $this->loadReadableCase(caseId: $caseId);
		$summary = $this->buildCaseSummary(caseData: $caseData);

		$sessionKey = self::SESSION_CONFIG_PREFIX . $caseId;
		$sessionId = $this->config->getUserValue($userId, Application::APP_ID, $sessionKey, '');
		if ($sessionId === '') {
			$sessionId = null;
		}

		$startTime = microtime(true);

		$result = $this->hermiqClient->converse(
			sessionId: $sessionId,
			message: $message,
			context: [
				// FROZEN at `procest`: this is this app's id AS HERMIQ KNOWS IT,
				// not our own app id. hermiq is not being renamed and matches this
				// value exactly on stored conversation context; a mismatch is a
				// silently dropped association, not an error. It moves only in a
				// coordinated pass that moves sender and receiver together.
				'app' => 'procest',
				'objectType' => 'case',
				'objectRef' => $caseId,
				'contextData' => $summary,
			]
		);

		$responseTimeMs = (int)((microtime(true) - $startTime) * 1000);

		if ($result['sessionId'] !== '') {
			$this->config->setUserValue($userId, Application::APP_ID, $sessionKey, $result['sessionId']);
		}

		$this->auditService->recordAssistantAuditEntry(
			entry: [
				'type' => 'assistant',
				'action' => 'conversation',
				'caseId' => $caseId,
				'documentId' => '',
				'model' => 'hermiq',
				'prompt' => $message,
				'suggestion' => ['reply' => $result['reply']],
				'confidence' => 0.0,
				'userId' => $userId,
				'timestamp' => date('c'),
				'responseTimeMs' => $responseTimeMs,
			]
		);

		return ['reply' => $result['reply'], 'usage' => $result['usage']];
	}//end converse()

	/**
	 * Validate the `message` field.
	 *
	 * @param string $message The message text.
	 *
	 * @return void
	 *
	 * @throws Exception (code 400) When empty or over the length cap.
	 */
	private function validateMessage(string $message): void {
		if (trim($message) === '') {
			throw new Exception('message is required', 400);
		}

		if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
			throw new Exception(
				'message exceeds the maximum length of ' . self::MAX_MESSAGE_LENGTH . ' characters',
				400
			);
		}
	}//end validateMessage()

	/**
	 * Load a case via the standard OpenRegister read path, scoped to the
	 * caller's own session/permissions exactly like every other dossiq
	 * service (`PublicationService`, `DsoCaseService`, …). A missing OR
	 * install, an unknown case, and a case the caller is not authorized to
	 * read all fail closed to the SAME 404 — never distinguished, so this
	 * endpoint cannot be used to probe for the existence of a case the
	 * caller cannot see (matches Hermiq's own 404-not-403 IDOR convention).
	 *
	 * @param string $caseId The case id.
	 *
	 * @return array<string, mixed> The case payload.
	 *
	 * @throws Exception (code 404) When the case cannot be read.
	 */
	private function loadReadableCase(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new Exception('Case not found: ' . $caseId, 404);
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');

		try {
			$case = $objectService->find(id: $caseId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->info(
				'CaseAssistantService: case load failed',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'error' => $e->getMessage()]
			);
			throw new Exception('Case not found: ' . $caseId, 404);
		}

		if ($case === null) {
			throw new Exception('Case not found: ' . $caseId, 404);
		}

		if (is_object($case) === true && method_exists($case, 'jsonSerialize') === true) {
			return $case->jsonSerialize();
		}

		return (array)$case;
	}//end loadReadableCase()

	/**
	 * Build a bounded, safe case-context summary — ONLY fields already shown
	 * on the CaseDetail page's own "Core case data"/"Process" widgets
	 * (manifest `src/manifest.json` CaseDetail page), truncated. Deliberately
	 * excludes documents, contacts, and initiator PII — those are a NON-goal
	 * for this surface (design.md).
	 *
	 * @param array<string, mixed> $caseData The full case payload.
	 *
	 * @return array<string, mixed> The bounded summary.
	 */
	private function buildCaseSummary(array $caseData): array {
		$description = (string)($caseData['description'] ?? '');
		if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
			// Use mb_substr() — a byte-based substr() would risk splitting a
			// multi-byte UTF-8 character (Dutch diacritics are common in
			// case descriptions) and corrupting the text sent to Hermiq.
			$description = mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH) . '…';
		}

		return array_filter(
			[
				'title' => ($caseData['title'] ?? null),
				'identifier' => ($caseData['identifier'] ?? null),
				'description' => $description,
				'caseType' => ($caseData['caseType'] ?? null),
				'status' => ($caseData['status'] ?? null),
				'confidentiality' => ($caseData['confidentiality'] ?? null),
				'startDate' => ($caseData['startDate'] ?? null),
				'deadline' => ($caseData['deadline'] ?? null),
				'isFinalStatus' => ($caseData['isFinalStatus'] ?? null),
			],
			static fn ($value): bool => $value !== null && $value !== ''
		);
	}//end buildCaseSummary()
}//end class
