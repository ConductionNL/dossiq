<?php

/**
 * Unit tests for ZgwMappingController.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ZgwMappingController;
use OCA\Dossiq\Repair\LoadDefaultZgwMappings;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwMappingService;
use OCA\Dossiq\Service\Zgw\ZgwSearchScope;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Listing, reading, saving, deleting and resetting the ZGW mappings.
 */
#[CoversClass(ZgwMappingController::class)]
#[UsesClass(ZgwSearchScope::class)]
#[UsesClass(LoadDefaultZgwMappings::class)]
final class ZgwMappingControllerTest extends TestCase {

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The mapping service.
	 *
	 * @var ZgwMappingService&MockObject
	 */
	private ZgwMappingService&MockObject $mappings;

	/**
	 * The settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * The controller.
	 *
	 * @var ZgwMappingController
	 */
	private ZgwMappingController $controller;

	/**
	 * Wire the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->mappings = $this->createMock(ZgwMappingService::class);
		$this->settings = $this->createMock(SettingsService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		$this->controller = new ZgwMappingController(
			request: $this->request,
			zgwMappingService: $this->mappings,
			settingsService: $this->settings,
			logger: $this->createMock(LoggerInterface::class),
			l10n: $l10n,
		);
	}//end setUp()

	/**
	 * index lists every mapping.
	 *
	 * @return void
	 */
	public function testIndexListsTheMappings(): void {
		$this->mappings->method('listMappings')->willReturn(['zaak' => ['enabled' => true]]);

		$response = $this->controller->index();

		self::assertSame(['success' => true, 'mappings' => ['zaak' => ['enabled' => true]]], $response->getData());
	}//end testIndexListsTheMappings()

	/**
	 * show answers the mapping, or says none is configured.
	 *
	 * @return void
	 */
	public function testShowAnswersTheMappingOrSaysNoneIsConfigured(): void {
		$this->mappings->method('getMapping')->willReturnMap(
			[
				['zaak', ['zgwResource' => 'zaken']],
				['besluit', null],
			]
		);

		self::assertSame(['success' => true, 'mapping' => ['zgwResource' => 'zaken']], $this->controller->show('zaak')->getData());
		self::assertSame(
			['success' => false, 'message' => 'No mapping configured for besluit'],
			$this->controller->show('besluit')->getData()
		);
	}//end testShowAnswersTheMappingOrSaysNoneIsConfigured()

	/**
	 * update saves the parameters without the route keys and answers the stored mapping.
	 *
	 * @return void
	 */
	public function testUpdateSavesTheParametersWithoutTheRouteKeys(): void {
		$this->request->method('getParams')->willReturn(
			['_route' => 'dossiq.zgw.update', 'resourceKey' => 'zaak', 'sourceRegister' => '5', 'sourceSchema' => 12, 'enabled' => true]
		);
		$this->mappings->expects(self::once())->method('saveMapping')
			->with(resourceKey: 'zaak', config: ['sourceRegister' => '5', 'sourceSchema' => 12, 'enabled' => true]);
		$this->mappings->method('getMapping')->with('zaak')->willReturn(['stored' => true]);

		$response = $this->controller->update('zaak');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['success' => true, 'mapping' => ['stored' => true]], $response->getData());
	}//end testUpdateSavesTheParametersWithoutTheRouteKeys()

	/**
	 * Parameters that name no source at all are saved as they are.
	 *
	 * @return void
	 */
	public function testUpdateWithoutASourceIsSaved(): void {
		$this->request->method('getParams')->willReturn(['enabled' => false]);
		$this->mappings->expects(self::once())->method('saveMapping')->with(resourceKey: 'zaak', config: ['enabled' => false]);

		self::assertTrue($this->controller->update('zaak')->getData()['success']);
	}//end testUpdateWithoutASourceIsSaved()

	/**
	 * Sources a search cannot use.
	 *
	 * @return array<string,array{array<string,mixed>}>
	 */
	public static function unsearchableSources(): array {
		return [
			'slug register'   => [['sourceRegister' => 'dossiq', 'sourceSchema' => '12']],
			'uuid schema'     => [['sourceRegister' => '5', 'sourceSchema' => '0c2f7a55-6d8e-4f5e-8f0c-1a2b3c4d0001']],
			'zero register'   => [['sourceRegister' => 0, 'sourceSchema' => 12]],
			'only a register' => [['sourceRegister' => '5']],
		];
	}//end unsearchableSources()

	/**
	 * A source given as a slug, uuid or non-positive id is refused with 400 and not saved.
	 *
	 * @param array<string,mixed> $params The parameters.
	 *
	 * @return void
	 */
	#[DataProvider('unsearchableSources')]
	public function testUpdateRefusesASourceASearchCannotUse(array $params): void {
		$this->request->method('getParams')->willReturn($params);
		$this->mappings->expects(self::never())->method('saveMapping');

		$response = $this->controller->update('zaak');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertFalse($response->getData()['success']);
		self::assertStringStartsWith('Give the source register and source schema as numeric ids.', $response->getData()['message']);
	}//end testUpdateRefusesASourceASearchCannotUse()

	/**
	 * destroy deletes the mapping.
	 *
	 * @return void
	 */
	public function testDestroyDeletesTheMapping(): void {
		$this->mappings->expects(self::once())->method('deleteMapping')->with('zaak');

		self::assertSame(['success' => true], $this->controller->destroy('zaak')->getData());
	}//end testDestroyDeletesTheMapping()

	/**
	 * reset without a configured register says so and resets nothing.
	 *
	 * @return void
	 */
	public function testResetWithoutARegisterResetsNothing(): void {
		$this->settings->method('getConfigValue')->with(key: 'register', default: '')->willReturn('');
		$this->mappings->expects(self::never())->method('resetToDefault');

		self::assertSame(
			['success' => false, 'message' => 'No Dossiq register configured'],
			$this->controller->reset('zaak')->getData()
		);
	}//end testResetWithoutARegisterResetsNothing()

	/**
	 * reset hands the defaults for the configured register and schemas to the service.
	 *
	 * @return void
	 */
	public function testResetUsesTheDefaultsForTheConfiguredRegister(): void {
		$this->settings->method('getConfigValue')->willReturn('5');
		$this->settings->method('getSettings')->willReturn(['case_schema' => '21']);
		$this->mappings->expects(self::once())->method('resetToDefault')->with(
			'zaak',
			self::callback(
				static fn (array $defaults): bool => $defaults['zaak']['sourceRegister'] === '5'
					&& $defaults['zaak']['sourceSchema'] === '21'
					&& $defaults['zaak']['enabled'] === true
					&& $defaults['caseType']['sourceSchema'] === ''
			)
		);
		$this->mappings->method('getMapping')->with('zaak')->willReturn(['reset' => true]);

		self::assertSame(['success' => true, 'mapping' => ['reset' => true]], $this->controller->reset('zaak')->getData());
	}//end testResetUsesTheDefaultsForTheConfiguredRegister()
}//end class
