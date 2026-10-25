<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-every-path-that-returns-the-document-or-its-file-honours-the-exception
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Portal\PortalCaseDocuments;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\InformatieobjectAccessGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DocumentAccessExceptionRule;
use OCA\Dossiq\Service\Zaakdossier\DocumentCaseIds;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Service\ZaakdossierService;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCA\Dossiq\Service\ZipManifestBuilder;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A reader of the case who is not listed gets the restricted document from no dossiq path; the listed people, a
 * member of a listed group, the case's handler and an administrator get it from every path.
 *
 * Every dossiq document read goes through InformatieobjectAccessGuard (canRead for one document, filterDossierForUser
 * for a listing), so these paths are exercised with the real guard and the real exception rule; only the stores
 * behind them are doubled.
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-every-path-that-returns-the-document-or-its-file-honours-the-exception
 */
class DocumentAccessExceptionPathsTest extends TestCase {

	private const CASE_ID = 'case-0082';

	/**
	 * The board's two rows: one with the exception, one without.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $docs;

	private InformatieobjectAccessGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->docs = [
			'offerte' => [
				'id' => 'doc-offerte',
				'title' => 'Offerte verlichting.pdf',
				'fileName' => 'Offerte verlichting.pdf',
				'fileId' => 42,
				'vertrouwelijkheidaanduiding' => 'openbaar',
				'informatieobjecttype' => 'offerte',
				'status' => 'final',
				'direction' => 'outgoing',
				'accessException' => ['users' => ['pieter'], 'groups' => ['woo-team'], 'reason' => 'Bedrijfsgevoelige prijzen'],
			],
			'verzoek' => [
				'id' => 'doc-verzoek',
				'title' => 'Verzoek om informatie.pdf',
				'fileName' => 'Verzoek om informatie.pdf',
				'fileId' => 43,
				'vertrouwelijkheidaanduiding' => 'openbaar',
				'informatieobjecttype' => 'verzoek',
				'status' => 'final',
				'direction' => 'outgoing',
			],
		];

		$groups = ['kees' => ['woo-team'], 'root' => ['admin']];
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');
		$groupManager->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $user): array => ($groups[$user->getUID()] ?? [])
		);

		$store = $this->createMock(DocumentCaseIds::class);
		$store->method('caseIdsOf')->willReturn([self::CASE_ID]);
		$cases = $this->createMock(CaseAccessGuard::class);
		$cases->method('hasCaseMutationAccess')->willReturnCallback(
			static fn (string $caseId, IUser $user): bool => in_array($user->getUID(), ['hanna', 'root'], true)
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnMap([
			['dossier_default_clearance', 'intern', 'intern'],
			['dossier_clearance_group_map', '', ''],
		]);

		$this->guard = new InformatieobjectAccessGuard(
			settingsService: $settings,
			groupManager: $groupManager,
			logger: new NullLogger(),
			exceptions: new DocumentAccessExceptionRule(caseIds: $store, cases: $cases, groupManager: $groupManager),
		);
	}//end setUp()

	/**
	 * A user.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	public function testTheCaseListingLeavesTheDocumentOutForAReaderWhoIsNotListed(): void {
		$rows = $this->guard->filterDossierForUser(user: $this->user('anna'), informatieobjecten: array_values($this->docs));

		$this->assertSame(['Verzoek om informatie.pdf'], array_column($rows, 'title'));
	}//end testTheCaseListingLeavesTheDocumentOutForAReaderWhoIsNotListed()

	public function testListedPeopleTheHandlerAndAnAdministratorSeeItInTheListing(): void {
		foreach (['pieter', 'kees', 'hanna', 'root'] as $uid) {
			$rows = $this->guard->filterDossierForUser(user: $this->user($uid), informatieobjecten: array_values($this->docs));
			$this->assertCount(2, $rows, $uid . ' should see both documents');
		}
	}//end testListedPeopleTheHandlerAndAnAdministratorSeeItInTheListing()

	public function testDetailAndDownloadAnswer403ForAReaderWhoIsNotListed(): void {
		$service = $this->createMock(ZaakdossierService::class);
		$service->method('getInformatieobject')->willReturn($this->docs['offerte']);
		$documents = $this->createMock(ZgwDocumentService::class);
		$documents->expects($this->never())->method('getContent');
		$reader = new InformatieobjectReader($service, $this->guard, $documents);

		$refused = $reader->loadReadable(user: $this->user('anna'), infoObjectId: 'doc-offerte');
		$this->assertInstanceOf(JSONResponse::class, $refused);
		$this->assertSame(403, $refused->getStatus());
		$this->assertNotNull($reader->guardReadable(user: $this->user('anna'), infoObjectId: 'doc-offerte'));

		$this->assertSame($this->docs['offerte'], $reader->loadReadable(user: $this->user('pieter'), infoObjectId: 'doc-offerte'));
		$this->assertNull($reader->guardReadable(user: $this->user('hanna'), infoObjectId: 'doc-offerte'));
	}//end testDetailAndDownloadAnswer403ForAReaderWhoIsNotListed()

	public function testTheCaseZipLeavesTheDocumentOut(): void {
		$documents = $this->createMock(ZgwDocumentService::class);
		$documents->method('getContent')->willReturnCallback(static fn (string $uuid): string => 'bytes-of-' . $uuid);
		$builder = new ZipManifestBuilder(
			documentService: $documents,
			accessGuard: $this->guard,
			logger: $this->createMock(LoggerInterface::class),
		);

		$kept = $builder->filterByClearance(user: $this->user('anna'), documents: array_values($this->docs));
		$this->assertSame(['doc-verzoek'], array_column($kept, 'id'));

		$path = tempnam(sys_get_temp_dir(), 'dq-zip-');
		try {
			$builder->buildZip(targetPath: $path, user: $this->user('anna'), documents: array_values($this->docs));
			$zip = new \ZipArchive();
			$zip->open($path);
			$names = [];
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$names[] = (string)$zip->getNameIndex($i);
			}

			$manifest = (string)$zip->getFromName('manifest.csv');
			$zip->close();
		} finally {
			@unlink($path);
		}

		$this->assertSame([], array_filter($names, static fn (string $name): bool => str_contains($name, 'Offerte')));
		$this->assertStringNotContainsString('Offerte', $manifest);
	}//end testTheCaseZipLeavesTheDocumentOut()

	public function testTheResidentPortalNeverShowsIt(): void {
		$portal = new PortalCaseDocuments($this->createMock(SettingsService::class), new NullLogger());

		$this->assertFalse($portal->isPublished(document: $this->docs['offerte'], isDecision: true));
		$this->assertTrue($portal->isPublished(document: $this->docs['verzoek'], isDecision: false));
	}//end testTheResidentPortalNeverShowsIt()

	public function testTheClearanceStillAppliesToAListedPerson(): void {
		$secret = $this->docs['offerte'];
		$secret['vertrouwelijkheidaanduiding'] = 'geheim';

		$this->assertFalse($this->guard->canRead(user: $this->user('pieter'), informatieobject: $secret));
	}//end testTheClearanceStillAppliesToAListedPerson()
}//end class
