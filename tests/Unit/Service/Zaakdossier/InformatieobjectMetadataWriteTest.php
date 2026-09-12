<?php

/**
 * ZaakdossierService::updateMetadata() against an object service that replaces.
 *
 * The same defect as the status transition, one method over: the metadata
 * edit saved only the fields it changed, with the document's uuid. OpenRegister
 * replaces on that save, so an edit to the title alone dropped the file name,
 * the confidentiality and the document type, and the schema refused it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\InformatieobjectAccessGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectMetadataNormaliser;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectStatusLifecycle;
use OCA\Dossiq\Service\ZaakdossierService;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCA\Dossiq\Tests\Unit\Fixtures\PatchingObjectService;
use OCA\Dossiq\Tests\Unit\Fixtures\ReplacingObjectService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A metadata edit keeps the rest of the document.
 *
 * @covers \OCA\Dossiq\Service\ZaakdossierService
 * @uses \OCA\Dossiq\Service\InformatieobjectAccessGuard
 * @uses \OCA\Dossiq\Service\Zaakdossier\InformatieobjectMetadataNormaliser
 * @uses \OCA\Dossiq\Service\Zaakdossier\InformatieobjectStatusLifecycle
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class InformatieobjectMetadataWriteTest extends TestCase {

	/**
	 * A document as the upload stores it.
	 *
	 * @var array<string, mixed>
	 */
	private const DOCUMENT = [
		'title' => 'Aanvraagformulier',
		'fileName' => 'aanvraag.pdf',
		'vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk',
		'informatieobjecttype' => 'iot-1',
		'auteur' => 'behandelaar',
		'fileId' => 4711,
		'direction' => 'incoming',
		'status' => 'draft',
	];

	/**
	 * The two object-service shapes the service has to work against.
	 *
	 * @return array<string, array{0: class-string<ReplacingObjectService>}>
	 */
	public static function objectServices(): array {
		return [
			'OpenRegister with patchObject' => [PatchingObjectService::class],
			'OpenRegister without patchObject' => [ReplacingObjectService::class],
		];
	}//end objectServices()

	/**
	 * Editing the title changes the title and keeps everything else.
	 *
	 * @dataProvider objectServices
	 *
	 * @param class-string<ReplacingObjectService> $serviceClass The object-service shape.
	 *
	 * @return void
	 */
	public function testATitleEditKeepsTheRestOfTheDocument(string $serviceClass): void {
		$objectService = $serviceClass::forShippedSchema('informatieobject');
		$objectService->stored['inf-1'] = self::DOCUMENT;

		$result = $this->service(objectService: $objectService)->updateMetadata(
			'inf-1',
			['title' => 'Aanvraagformulier, herzien', 'keywords' => ['bouw']]
		);

		$this->assertTrue($result['updated']);
		$stored = $objectService->stored['inf-1'];
		$this->assertSame('Aanvraagformulier, herzien', $stored['title']);
		$this->assertSame(['bouw'], $stored['keywords']);
		foreach (array_diff_key(self::DOCUMENT, ['title' => true]) as $field => $value) {
			$this->assertSame($value, $stored[$field], sprintf('the metadata edit must keep %s', $field));
		}

	}//end testATitleEditKeepsTheRestOfTheDocument()

	/**
	 * The service under test, over mocked settings and real collaborators.
	 *
	 * @param object $objectService The object service it should reach.
	 *
	 * @return ZaakdossierService
	 */
	private function service(object $objectService): ZaakdossierService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'dossier_informatieobject_schema' => 'informatieobject',
			][$key] ?? $default
		);
		$logger = $this->createMock(LoggerInterface::class);

		return new ZaakdossierService(
			$settings,
			$this->createMock(ZgwDocumentService::class),
			new InformatieobjectAccessGuard($settings, $this->createMock(IGroupManager::class), $logger),
			new InformatieobjectStatusLifecycle($settings, $logger),
			new InformatieobjectMetadataNormaliser(),
			$logger,
		);

	}//end service()
}//end class
