<?php

/**
 * LibresignSigningAdapter Unit Tests.
 *
 * Mocks LibresignApiClient (the sole HTTP boundary — no real HTTP happens
 * here), IAppManager, IUserManager, IRootFolder, and ZgwDocumentService.
 * Covers: request-payload building (file id + signer list), signer
 * resolution from the mandaat-authorised UID (including missing/incomplete
 * account data), status-mapping-driven branches (signed/declined/pending/
 * unknown), the unavailable-app fallback, and the signed-file storage call
 * hitting the EXISTING ZgwDocumentService, not a new path.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/libresign-besluit-signing/specs/libresign-besluit-signing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Service\Beschikking\LibresignApiClient;
use OCA\Dossiq\Service\Beschikking\LibresignSigningAdapter;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Beschikking\LibresignSigningAdapter
 *
 * @uses \OCA\Dossiq\Service\Beschikking\LibresignResultAssembler
 * @uses \OCA\Dossiq\Service\Beschikking\LibresignStatusMapper
 */
class LibresignSigningAdapterTest extends TestCase {
	/**
	 * No-op sleeper so poll-window tests run instantly.
	 *
	 * @return callable
	 */
	private function noopSleeper(): callable {
		return static function (int $seconds): void {
			// Intentionally does nothing — keeps tests fast/deterministic.
		};
	}//end noopSleeper()

	/**
	 * An IAppConfig stub returning the given int/string overrides, default otherwise.
	 *
	 * @param array<string, int> $intOverrides Keyed poll-config overrides.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(array $intOverrides = []): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static function (string $app, string $key, int $default = 0) use ($intOverrides): int {
				return ($intOverrides[$key] ?? $default);
			}
		);
		$appConfig->method('getValueString')->willReturn('');

		return $appConfig;
	}//end appConfig()

	/**
	 * An IAppManager stub reporting LibreSign as enabled.
	 *
	 * @return IAppManager
	 */
	private function enabledAppManager(): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(true);

		return $appManager;
	}//end enabledAppManager()

	/**
	 * An IUserManager stub resolving $uid to a user with the given email/display name.
	 *
	 * @param string $uid The UID to resolve.
	 * @param string|null $email The email, or null when unresolvable/absent.
	 * @param string $name The display name.
	 *
	 * @return IUserManager
	 */
	private function userManager(string $uid, ?string $email, string $name = 'J. Jansen'): IUserManager {
		$userManager = $this->createMock(IUserManager::class);

		if ($email === null) {
			$userManager->method('get')->with($uid)->willReturn(null);
			return $userManager;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn($email);
		$user->method('getDisplayName')->willReturn($name);

		$userManager->method('get')->with($uid)->willReturn($user);

		return $userManager;
	}//end userManager()

	/**
	 * Build an adapter with the given collaborators, defaulting the rest to
	 * permissive/empty stubs.
	 *
	 * @param LibresignApiClient $apiClient The (mock) API client.
	 * @param IAppManager|null $appManager Optional IAppManager override.
	 * @param IUserManager|null $userManager Optional IUserManager override.
	 * @param IRootFolder|null $rootFolder Optional IRootFolder override.
	 * @param ZgwDocumentService|null $documentService Optional ZgwDocumentService override.
	 * @param array<string, int> $pollConfig Poll attempts/interval overrides.
	 *
	 * @return LibresignSigningAdapter
	 */
	private function makeAdapter(
		LibresignApiClient $apiClient,
		?IAppManager $appManager = null,
		?IUserManager $userManager = null,
		?IRootFolder $rootFolder = null,
		?ZgwDocumentService $documentService = null,
		array $pollConfig = [],
	): LibresignSigningAdapter {
		return new LibresignSigningAdapter(
			apiClient: $apiClient,
			appManager: ($appManager ?? $this->enabledAppManager()),
			appConfig: $this->appConfig($pollConfig),
			userManager: ($userManager ?? $this->userManager('medewerker1', 'j.jansen@example.nl')),
			rootFolder: ($rootFolder ?? $this->createMock(IRootFolder::class)),
			documentService: ($documentService ?? $this->createMock(ZgwDocumentService::class)),
			logger: $this->createMock(LoggerInterface::class),
			sleeper: $this->noopSleeper(),
		);
	}//end makeAdapter()

	/**
	 * sign() sends the correct file id and a signer list resolved from the NC account.
	 *
	 * @return void
	 */
	public function testSignBuildsRequestWithCorrectFileIdAndSigner(): void {
		$apiClient = $this->createMock(LibresignApiClient::class);
		$apiClient->expects($this->once())
			->method('requestSignature')
			->with(
				12345,
				'beschikking-12345',
				[['identify' => ['email' => 'j.jansen@example.nl'], 'displayName' => 'J. Jansen']]
			)
			->willReturn(['uuid' => 'req-1']);
		$apiClient->method('getStatus')->willReturn(['statusText' => 'able_to_sign']);

		$adapter = $this->makeAdapter(apiClient: $apiClient, pollConfig: ['libresign_poll_attempts' => 1]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('libresign_signing_pending');
		$adapter->sign('12345', 'medewerker1', 'nvt');
	}//end testSignBuildsRequestWithCorrectFileIdAndSigner()

	/**
	 * Missing NC account for the signer UID is rejected before any API call.
	 *
	 * @return void
	 */
	public function testMissingSignerAccountThrowsUnresolvable(): void {
		$apiClient = $this->createMock(LibresignApiClient::class);
		$apiClient->expects($this->never())->method('requestSignature');

		$adapter = $this->makeAdapter(
			apiClient: $apiClient,
			userManager: $this->userManager('ghost-user', null),
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('libresign_signer_unresolvable');
		$adapter->sign('12345', 'ghost-user', 'nvt');
	}//end testMissingSignerAccountThrowsUnresolvable()

	/**
	 * An NC account that resolves but has no configured email is rejected the same way
	 * (incomplete mandaat/user data).
	 *
	 * @return void
	 */
	public function testAccountWithoutEmailThrowsUnresolvable(): void {
		$apiClient = $this->createMock(LibresignApiClient::class);
		$apiClient->expects($this->never())->method('requestSignature');

		$adapter = $this->makeAdapter(
			apiClient: $apiClient,
			userManager: $this->userManager('medewerker1', ''),
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('libresign_signer_unresolvable');
		$adapter->sign('12345', 'medewerker1', 'nvt');
	}//end testAccountWithoutEmailThrowsUnresolvable()

	/**
	 * LibreSign disabled at call time is reported as unavailable; no API call is made.
	 *
	 * @return void
	 */
	public function testUnavailableLibresignThrowsBeforeAnyApiCall(): void {
		$apiClient = $this->createMock(LibresignApiClient::class);
		$apiClient->expects($this->never())->method('requestSignature');

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(false);

		$adapter = $this->makeAdapter(apiClient: $apiClient, appManager: $appManager);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('libresign_unavailable');
		$adapter->sign('12345', 'medewerker1', 'nvt');
	}//end testUnavailableLibresignThrowsBeforeAnyApiCall()

	/**
	 * A DECLINED status is mapped to the declined domain exception.
	 *
	 * @return void
	 */
	public function testDeclinedStatusThrowsDeclinedException(): void {
		$apiClient = $this->createMock(LibresignApiClient::class);
		$apiClient->method('requestSignature')->willReturn(['uuid' => 'req-1']);
		$apiClient->method('getStatus')->willReturn(['statusText' => 'deleted']);

		$adapter = $this->makeAdapter(apiClient: $apiClient, pollConfig: ['libresign_poll_attempts' => 3]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('libresign_signing_declined');
		$adapter->sign('12345', 'medewerker1', 'nvt');
	}//end testDeclinedStatusThrowsDeclinedException()

	/**
	 * An UNKNOWN status is treated as pending — the poll window still exhausts to
	 * libresign_signing_pending, never an implicit sign.
	 *
	 * @return void
	 */
	public function testUnknownStatusIsTreatedAsPending(): void {
		$apiClient = $this->createMock(LibresignApiClient::class);
		$apiClient->method('requestSignature')->willReturn(['uuid' => 'req-1']);
		$apiClient->method('getStatus')->willReturn(['statusText' => 'something-new']);

		$adapter = $this->makeAdapter(apiClient: $apiClient, pollConfig: ['libresign_poll_attempts' => 2]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('libresign_signing_pending');
		$adapter->sign('12345', 'medewerker1', 'nvt');
	}//end testUnknownStatusIsTreatedAsPending()

	/**
	 * A SIGNED status downloads the signed file and stores it via the EXISTING
	 * ZgwDocumentService::storeRaw() — not a new storage path — returning the full contract.
	 *
	 * @return void
	 */
	public function testSignedStatusStoresFileViaExistingDocumentServiceAndReturnsContract(): void {
		$apiClient = $this->createMock(LibresignApiClient::class);
		$apiClient->method('requestSignature')->willReturn(['uuid' => 'req-1']);
		$apiClient->method('getStatus')->willReturn([
			'statusText' => 'signed',
			'file' => ['signedFileId' => 999],
		]);

		$signedFile = $this->createMock(File::class);
		$signedFile->method('getContent')->willReturn('%PDF-signed-bytes%');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(999)->willReturn([$signedFile]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('medewerker1')->willReturn($userFolder);

		$documentService = $this->createMock(ZgwDocumentService::class);
		$documentService->expects($this->once())
			->method('storeRaw')
			->with('12345', 'beschikking-12345-signed.pdf', '%PDF-signed-bytes%')
			->willReturn(strlen('%PDF-signed-bytes%'));
		$documentService->expects($this->once())
			->method('getFileId')
			->with('12345', 'beschikking-12345-signed.pdf')
			->willReturn(67890);

		$adapter = $this->makeAdapter(
			apiClient: $apiClient,
			rootFolder: $rootFolder,
			documentService: $documentService,
		);

		$result = $adapter->sign('12345', 'medewerker1', 'nvt');

		$this->assertSame('67890', $result['signedBestandId']);
		$this->assertSame('req-1', $result['validationRapportId']);
		$this->assertSame('LibreSign', $result['tspProviderEidasId']);
		$this->assertNotEmpty($result['signingMoment']);
		$this->assertNotEmpty($result['certificateSerialNumber']);
	}//end testSignedStatusStoresFileViaExistingDocumentServiceAndReturnsContract()

	/**
	 * fetchValidationReport() degrades to an invalid-but-structured report on transport failure.
	 *
	 * @return void
	 */
	public function testFetchValidationReportDegradesOnFailure(): void {
		$apiClient = $this->createMock(LibresignApiClient::class);
		$apiClient->method('getStatus')->willThrowException(new RuntimeException('libresign_api_error'));

		$adapter = $this->makeAdapter(apiClient: $apiClient);

		$report = $adapter->fetchValidationReport('req-1');

		$this->assertSame('req-1', $report['validationRapportId']);
		$this->assertFalse($report['valid']);
	}//end testFetchValidationReportDegradesOnFailure()

	/**
	 * fetchValidationReport() reports geldig=true for a signed request.
	 *
	 * @return void
	 */
	public function testFetchValidationReportReportsValidForSignedRequest(): void {
		$apiClient = $this->createMock(LibresignApiClient::class);
		$apiClient->method('getStatus')->willReturn(['statusText' => 'signed', 'signers' => [['email' => 'j.jansen@example.nl', 'status' => 'signed']]]);

		$adapter = $this->makeAdapter(apiClient: $apiClient);

		$report = $adapter->fetchValidationReport('req-1');

		$this->assertTrue($report['valid']);
		$this->assertSame('req-1', $report['validationRapportId']);
	}//end testFetchValidationReportReportsValidForSignedRequest()
}//end class
