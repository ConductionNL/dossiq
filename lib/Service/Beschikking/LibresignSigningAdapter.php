<?php

/**
 * Procest LibreSign Signing Adapter.
 *
 * Concrete implementation of SigningAdapterInterface backed by LibreSign
 * (LibreCode), the Nextcloud-native eIDAS-aligned digital signing app.
 * Resolves the signer identity from the mandaat-authorised actor UID
 * (design.md §4), creates a LibreSign signature request via
 * LibresignApiClient, performs a short bounded status poll mapping
 * LibreSign's status vocabulary via LibresignStatusMapper, and — once
 * signed — persists the signed PDF through the EXISTING
 * ZgwDocumentService binary storage path (no new storage mechanism).
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

use DateTimeImmutable;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\ZgwDocumentService;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * LibreSign-backed implementation of the beschikking signing adapter.
 *
 * @spec openspec/specs/libresign-besluit-signing/spec.md
 */
class LibresignSigningAdapter implements SigningAdapterInterface
{
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
     * Constructor.
     *
     * @param LibresignApiClient    $apiClient       The thin LibreSign HTTP client.
     * @param IAppManager           $appManager      Feature-gate: is LibreSign enabled.
     * @param IAppConfig            $appConfig       App config (poll attempts/interval, service auth).
     * @param IUserManager          $userManager     Resolves the signer's NC account.
     * @param IRootFolder           $rootFolder      Reads the LibreSign-produced signed file by id.
     * @param ZgwDocumentService    $documentService The EXISTING binary document storage service.
     * @param LoggerInterface       $logger          Structured logger.
     * @param LibresignStatusMapper $statusMapper    Maps LibreSign status values onto the internal vocabulary
     *                                               (stateless; defaults to a fresh instance).
     * @param callable|null         $sleeper         Optional injectable `function(int $s): void` (tests pass a no-op).
     */
    public function __construct(
        private readonly LibresignApiClient $apiClient,
        private readonly IAppManager $appManager,
        private readonly IAppConfig $appConfig,
        private readonly IUserManager $userManager,
        private readonly IRootFolder $rootFolder,
        private readonly ZgwDocumentService $documentService,
        private readonly LoggerInterface $logger,
        private readonly LibresignStatusMapper $statusMapper=new LibresignStatusMapper(),
        ?callable $sleeper=null,
    ) {
        $this->sleeper = ($sleeper ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        });
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @param string $bestandId     The PDF file id.
     * @param string $ondertekenaar The signer UID.
     * @param string $tspProvider   The provider slug (unused for the LibreSign identifier; kept for the interface contract).
     *
     * @return array<string, string>
     *
     * @throws RuntimeException 'libresign_unavailable', 'libresign_signer_unresolvable',
     *                          'libresign_signing_declined', 'libresign_signing_pending', or
     *                          'libresign_signed_file_missing'.
     *
     * @spec openspec/specs/libresign-besluit-signing/spec.md
     */
    public function sign(string $bestandId, string $ondertekenaar, string $tspProvider): array
    {
        $this->assertAvailable();

        $signer = $this->resolveSigner(ondertekenaar: $ondertekenaar);

        $request = $this->apiClient->requestSignature(
            fileId: (int) $bestandId,
            documentName: 'beschikking-'.$bestandId,
            signers: [$signer],
        );

        $uuid = (string) ($request['uuid'] ?? '');
        if ($uuid === '') {
            throw new RuntimeException('libresign_api_error');
        }

        $attempts        = max(
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
            $raw    = (string) ($status['statusText'] ?? ($status['status'] ?? ''));
            $mapped = $this->statusMapper->map($raw);

            if ($mapped === LibresignStatusMapper::UNKNOWN) {
                $this->logger->warning(
                    'LibresignSigningAdapter: unrecognised LibreSign status value',
                    ['app' => Application::APP_ID, 'uuid' => $uuid, 'raw' => $raw],
                );
            }

            if ($mapped === LibresignStatusMapper::SIGNED) {
                return $this->storeSignedResult(
                    bestandId: $bestandId,
                    ondertekenaar: $ondertekenaar,
                    uuid: $uuid,
                    status: $status,
                );
            }

            if ($mapped === LibresignStatusMapper::DECLINED) {
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
    public function fetchValidationReport(string $validatieRapportId): array
    {
        try {
            $status = $this->apiClient->getStatus($validatieRapportId);
            $raw    = (string) ($status['statusText'] ?? ($status['status'] ?? ''));
            $mapped = $this->statusMapper->map($raw);

            return [
                'validatieRapportId' => $validatieRapportId,
                'soort'              => 'libresign-handtekening-rapport',
                'norm'               => 'eIDAS / ETSI EN 319 102-1 (LibreSign)',
                'geldig'             => ($mapped === LibresignStatusMapper::SIGNED),
                'status'             => $mapped,
                'signers'            => (array) ($status['signers'] ?? []),
                'gegenereerdOp'      => (new DateTimeImmutable())->format('c'),
            ];
        } catch (Throwable $e) {
            $this->logger->warning(
                'LibresignSigningAdapter: fetchValidationReport degraded to an invalid report',
                ['app' => Application::APP_ID, 'validatieRapportId' => $validatieRapportId, 'error' => $e->getMessage()],
            );

            return [
                'validatieRapportId' => $validatieRapportId,
                'soort'              => 'libresign-handtekening-rapport',
                'norm'               => 'eIDAS / ETSI EN 319 102-1 (LibreSign)',
                'geldig'             => false,
                'foutmelding'        => 'libresign_api_error',
                'gegenereerdOp'      => (new DateTimeImmutable())->format('c'),
            ];
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
    private function assertAvailable(): void
    {
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
    private function resolveSigner(string $ondertekenaar): array
    {
        $user = $this->userManager->get($ondertekenaar);
        if ($user === null) {
            throw new RuntimeException('libresign_signer_unresolvable');
        }

        $email = $user->getEMailAddress();
        if ($email === null || trim($email) === '') {
            throw new RuntimeException('libresign_signer_unresolvable');
        }

        return [
            'identify'    => ['email' => $email],
            'displayName' => $user->getDisplayName(),
        ];
    }//end resolveSigner()

    /**
     * Download the LibreSign-produced signed PDF and persist it via the existing document
     * storage service, returning the full sign() contract.
     *
     * @param string               $bestandId     The original PDF file id.
     * @param string               $ondertekenaar The signer UID (owns the signed file in NC storage).
     * @param string               $uuid          The LibreSign request uuid.
     * @param array<string, mixed> $status        The last polled LibreSign status payload.
     *
     * @return array<string, string>
     *
     * @throws RuntimeException 'libresign_signed_file_missing' when the signed file cannot be read.
     */
    private function storeSignedResult(string $bestandId, string $ondertekenaar, string $uuid, array $status): array
    {
        $signedFileId = (int) ($status['file']['signedFileId'] ?? 0);
        if ($signedFileId <= 0) {
            throw new RuntimeException('libresign_signed_file_missing');
        }

        $content  = $this->readSignedFileContent(uid: $ondertekenaar, fileId: $signedFileId);
        $fileName = 'beschikking-'.$bestandId.'-signed.pdf';

        // Persist through the EXISTING zaakdossier binary storage path — no new storage
        // mechanism. storeRaw() returns the byte count; getFileId() resolves the id it just
        // wrote, both against the same service.
        $this->documentService->storeRaw(uuid: $bestandId, fileName: $fileName, content: $content);
        $newFileId = $this->documentService->getFileId(uuid: $bestandId, fileName: $fileName);

        return [
            'signedBestandId'        => (string) $newFileId,
            'validatieRapportId'     => $uuid,
            'certificaatSerienummer' => (string) ($status['certificateSerialNumber'] ?? ('libresign-'.$uuid)),
            'tspProviderEidasId'     => 'LibreSign',
            'ondertekeningTijdstip'  => (new DateTimeImmutable())->format('c'),
        ];
    }//end storeSignedResult()

    /**
     * Read the LibreSign-produced signed file's bytes by Nextcloud file id.
     *
     * @param string $uid    The Nextcloud user whose folder holds the signed file.
     * @param int    $fileId The Nextcloud file id.
     *
     * @return string
     *
     * @throws RuntimeException 'libresign_signed_file_missing'.
     */
    private function readSignedFileContent(string $uid, int $fileId): string
    {
        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            $nodes      = $userFolder->getById($fileId);
        } catch (Throwable $e) {
            throw new RuntimeException('libresign_signed_file_missing', 0, $e);
        }

        if (count($nodes) === 0) {
            throw new RuntimeException('libresign_signed_file_missing');
        }

        $node = $nodes[0];
        if (($node instanceof File) === false) {
            throw new RuntimeException('libresign_signed_file_missing');
        }

        return $node->getContent();
    }//end readSignedFileContent()
}//end class
