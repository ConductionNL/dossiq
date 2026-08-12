<?php

/**
 * Procest LibreSign Signing Adapter.
 *
 * Concrete implementation of SigningAdapterInterface backed by LibreSign
 * (LibreCode), the Nextcloud-native eIDAS-aligned digital signing app.
 * Resolves the signer identity from the mandaat-authorised actor UID
 * (design.md §4), creates a LibreSign signature request via
 * LibresignApiClient, and performs a short bounded status poll. Reading
 * LibreSign's status vocabulary, persisting the signed PDF through the
 * EXISTING ZgwDocumentService binary storage path (no new storage mechanism),
 * and shaping the results procest publishes all belong to
 * LibresignResultAssembler.
 *
 * See openspec/changes/libresign-besluit-signing/design.md for the full
 * rationale, including why sign() stays synchronous against an inherently
 * asynchronous real-world signing flow.
 *
 * @category Service
 * @package  OCA\Procest\Service\Beschikking
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
 * @spec openspec/specs/libresign-besluit-signing/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Beschikking;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\ZgwDocumentService;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * LibreSign-backed implementation of the beschikking signing adapter.
 *
 * Owns the LibreSign conversation only; the shape of what procest hands back —
 * and the signed-file plumbing behind it — belongs to
 * {@see LibresignResultAssembler}.
 *
 * @spec openspec/specs/libresign-besluit-signing/spec.md
 */
class LibresignSigningAdapter implements SigningAdapterInterface {
	/**
	 * Default number of status-poll attempts when unconfigured.
	 *
	 * @var int
	 */
	private const DEFAULT_POLL_ATTEMPTS = 3;

	/**
	 * Default seconds between status-poll attempts when unconfigured.
	 *
	 * @var int
	 */
	private const DEFAULT_POLL_INTERVAL_SECONDS = 2;

	/**
	 * Injectable sleep function: `function (int $seconds): void`.
	 *
	 * @var callable
	 */
	private $sleeper;

	/**
	 * Builds the signed-result and validatierapport contracts.
	 *
	 * @var LibresignResultAssembler
	 */
	private LibresignResultAssembler $assembler;

	/**
	 * Constructor.
	 *
	 * `$rootFolder` and `$documentService` are accepted (rather than resolved
	 * from an injected assembler) so the DI factory's named-argument call site
	 * stays unchanged; both are handed straight to the assembler that owns
	 * them.
	 *
	 * @param LibresignApiClient $apiClient The thin LibreSign HTTP client.
	 * @param IAppManager $appManager Feature-gate: is LibreSign enabled.
	 * @param IAppConfig $appConfig App config (poll attempts/interval, service auth).
	 * @param IUserManager $userManager Resolves the signer's NC account.
	 * @param IRootFolder $rootFolder Reads the LibreSign-produced signed file by id.
	 * @param ZgwDocumentService $documentService The EXISTING binary document storage service.
	 * @param LoggerInterface $logger Structured logger.
	 * @param callable|null $sleeper Optional injectable `function(int $s): void` (tests pass a no-op).
	 */
	public function __construct(
		private readonly LibresignApiClient $apiClient,
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
		private readonly IUserManager $userManager,
		IRootFolder $rootFolder,
		ZgwDocumentService $documentService,
		private readonly LoggerInterface $logger,
		?callable $sleeper = null,
	) {
		$this->assembler = new LibresignResultAssembler(
			rootFolder: $rootFolder,
			documentService: $documentService,
		);

		$this->sleeper = ($sleeper ?? static function (int $seconds): void {
			if ($seconds > 0) {
				sleep($seconds);
			}
		});
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $bestandId The PDF file id.
	 * @param string $ondertekenaar The signer UID.
	 * @param string $tspProvider The provider slug (unused for the LibreSign identifier; kept for the interface contract).
	 *
	 * @return array<string, string>
	 *
	 * @throws RuntimeException 'libresign_unavailable', 'libresign_signer_unresolvable',
	 *                          'libresign_signing_declined', 'libresign_signing_pending', or
	 *                          'libresign_signed_file_missing'.
	 *
	 * @spec openspec/specs/libresign-besluit-signing/spec.md
	 */
	public function sign(string $bestandId, string $ondertekenaar, string $tspProvider): array {
		$this->assertAvailable();

		$signer = $this->resolveSigner(ondertekenaar: $ondertekenaar);

		$request = $this->apiClient->requestSignature(
			fileId: (int)$bestandId,
			documentName: 'beschikking-' . $bestandId,
			signers: [$signer],
		);

		$uuid = (string)($request['uuid'] ?? '');
		if ($uuid === '') {
			throw new RuntimeException('libresign_api_error');
		}

		$attempts = max(
			1,
			$this->appConfig->getValueInt(Application::APP_ID, 'libresign_poll_attempts', self::DEFAULT_POLL_ATTEMPTS)
		);
		$intervalSeconds = max(
			0,
			$this->appConfig->getValueInt(
				Application::APP_ID,
				'libresign_poll_interval_seconds',
				self::DEFAULT_POLL_INTERVAL_SECONDS
			)
		);

		for ($attempt = 0; $attempt < $attempts; $attempt++) {
			$status = $this->apiClient->getStatus($uuid);
			$mapped = $this->assembler->mapStatus(status: $status);

			if ($mapped === LibresignResultAssembler::UNKNOWN) {
				$raw = (string)($status['statusText'] ?? ($status['status'] ?? ''));
				$this->logger->warning(
					'LibresignSigningAdapter: unrecognised LibreSign status value',
					['app' => Application::APP_ID, 'uuid' => $uuid, 'raw' => $raw],
				);
			}

			if ($mapped === LibresignResultAssembler::SIGNED) {
				return $this->assembler->assembleSignedResult(
					bestandId: $bestandId,
					ondertekenaar: $ondertekenaar,
					uuid: $uuid,
					status: $status,
				);
			}

			if ($mapped === LibresignResultAssembler::DECLINED) {
				throw new RuntimeException('libresign_signing_declined');
			}

			if (($attempt + 1) < $attempts) {
				($this->sleeper)($intervalSeconds);
			}
		}//end for

		throw new RuntimeException('libresign_signing_pending');
	}//end sign()

	/**
	 * {@inheritDoc}
	 *
	 * Degrades to a structured-but-invalid report on transport failure rather than throwing,
	 * matching MockSigningAdapter's always-answers shape.
	 *
	 * @param string $validatieRapportId The LibreSign request uuid (procest stores it as the validatierapport id).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/libresign-besluit-signing/spec.md
	 */
	public function fetchValidationReport(string $validatieRapportId): array {
		try {
			return $this->assembler->assembleValidationReport(
				validatieRapportId: $validatieRapportId,
				status: $this->apiClient->getStatus($validatieRapportId),
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'LibresignSigningAdapter: fetchValidationReport degraded to an invalid report',
				['app' => Application::APP_ID, 'validatieRapportId' => $validatieRapportId, 'error' => $e->getMessage()],
			);

			return $this->assembler->assembleFailedValidationReport(validatieRapportId: $validatieRapportId);
		}//end try
	}//end fetchValidationReport()

	/**
	 * Re-check LibreSign availability at call time (defends against a mid-session toggle race;
	 * the DI factory already avoids binding this adapter when LibreSign is disabled).
	 *
	 * @return void
	 *
	 * @throws RuntimeException 'libresign_unavailable'.
	 */
	private function assertAvailable(): void {
		if ($this->appManager->isEnabledForUser('libresign') === false) {
			throw new RuntimeException('libresign_unavailable');
		}
	}//end assertAvailable()

	/**
	 * Resolve the LibreSign signer identity from the mandaat-authorised actor's NC account.
	 *
	 * @param string $ondertekenaar The Nextcloud UID.
	 *
	 * @return array<string, mixed> `{identify: {email: string}, displayName: string}`.
	 *
	 * @throws RuntimeException 'libresign_signer_unresolvable' when the UID does not resolve to
	 *                          an account, or the account has no configured email.
	 */
	private function resolveSigner(string $ondertekenaar): array {
		$user = $this->userManager->get($ondertekenaar);
		if ($user === null) {
			throw new RuntimeException('libresign_signer_unresolvable');
		}

		$email = $user->getEMailAddress();
		if ($email === null || trim($email) === '') {
			throw new RuntimeException('libresign_signer_unresolvable');
		}

		return [
			'identify' => ['email' => $email],
			'displayName' => $user->getDisplayName(),
		];
	}//end resolveSigner()
}//end class
