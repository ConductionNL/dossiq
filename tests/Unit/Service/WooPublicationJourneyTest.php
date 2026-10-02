<?php

/**
 * Publishing from the case: the case's Woo decision, the journey fields, files on
 * the publication, the case state and the way back to the dossier.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WooPublication\OpenCatalogiApiClient;
use OCA\Dossiq\Service\WooPublication\WooCategoryMapper;
use OCA\Dossiq\Service\WooPublicationService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseLedger;
use OCA\Dossiq\Woo\WooDecisionNotice;
use OCA\Dossiq\Woo\WooDossierReturn;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Runs publish and withdraw against an in-memory dossiq register and a recording OpenCatalogi client.
 *
 * @covers \OCA\Dossiq\Service\WooPublicationService
 * @covers \OCA\Dossiq\Woo\WooCaseLedger
 * @uses   \OCA\Dossiq\Service\WooPublication\WooCategoryMapper
 * @uses   \OCA\Dossiq\Woo\WooDecisionNotice
 */
class WooPublicationJourneyTest extends TestCase {

	private const CASE = 'case-1';

	/**
	 * The dossiq store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The OpenCatalogi client.
	 *
	 * @var OpenCatalogiApiClient&MockObject
	 */
	private OpenCatalogiApiClient&MockObject $client;

	/**
	 * The dossier return.
	 *
	 * @var WooDossierReturn&MockObject
	 */
	private WooDossierReturn&MockObject $return;

	/**
	 * The resident's decision notice.
	 *
	 * @var WooDecisionNotice&MockObject
	 */
	private WooDecisionNotice&MockObject $notice;

	/**
	 * The service under test.
	 *
	 * @var WooPublicationService
	 */
	private WooPublicationService $service;

	/**
	 * A decided Woo request case started from a dossier, with one public document.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'case', uuid: self::CASE, row: [
			'title' => 'Parkeerbeleid centrum',
			'wooRequest' => ['periodeVan' => '2025-01-01', 'periodeTot' => '2025-12-31', 'collectionId' => 'col-1'],
			'wooPublicationStatus' => 'ready',
		]);
		$this->store->seed(schema: 'decision', uuid: 'dec-1', row: [
			'case' => self::CASE, 'decisionType' => 'WOO-besluit', 'description' => 'Besluit', 'wooSummary' => ['openbaar' => 1],
		]);
		$this->store->seed(schema: 'wooAssessment', uuid: 'as-1', row: ['caseRef' => self::CASE, 'documentRef' => 'doc-1', 'classification' => 'openbaar']);
		$this->store->seed(schema: 'document', uuid: 'doc-1', row: ['title' => 'Nota', 'fileName' => 'nota.pdf', 'format' => 'application/pdf', 'content' => base64_encode('pdf')]);

		/** @var SettingsService&MockObject $settings */
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'case_schema' => 'case',
				'decision_schema' => 'decision',
				'woo_assessment_schema' => 'wooAssessment',
				'document_schema' => 'document',
			][$key] ?? $default
		);
		$settings->method('getWooPublicationConfigValue')->willReturnCallback(
			fn (string $key): string => SettingsService::WOO_PUBLICATION_DEFAULTS[$key] ?? ''
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$apps->method('isEnabledForUser')->willReturn(true);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'https://gemeente.test' . $path);

		$this->client = $this->createMock(OpenCatalogiApiClient::class);
		$this->return = $this->createMock(WooDossierReturn::class);
		$this->notice = $this->createMock(WooDecisionNotice::class);

		$this->service = new WooPublicationService(
			$settings,
			$this->client,
			new WooCategoryMapper(),
			$apps,
			$this->createMock(LoggerInterface::class),
			$this->return,
			new WooCaseLedger(settingsService: $settings, logger: $this->createMock(LoggerInterface::class), urlGenerator: $urls),
			null,
			$this->notice,
		);
	}//end setUp()

	/**
	 * The first publish tells the resident, with the case as it was and the publication.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-pages-in-resident-groups/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
	 */
	public function testTheFirstPublishTellsTheResident(): void {
		$this->client->method('createPublication')->willReturn(['id' => 'pub-1']);
		$this->notice->expects(self::once())->method('tell')->with(
			self::callback(fn (array $case): bool => ($case['title'] ?? '') === 'Parkeerbeleid centrum'),
			self::CASE,
			'pub-1',
		)->willReturn(true);

		$this->service->publish(self::CASE, 'dec-1');
	}//end testTheFirstPublishTellsTheResident()

	/**
	 * A republish keeps the link the resident already has, so it tells nobody again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-pages-in-resident-groups/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
	 */
	public function testARepublishDoesNotTellTheResidentAgain(): void {
		$this->store->seed(schema: 'case', uuid: self::CASE, row: [
			'title' => 'Parkeerbeleid centrum',
			'wooRequest' => ['collectionId' => 'col-1'],
			'wooPublicationStatus' => 'published',
			'wooPublicationUrl' => 'https://gemeente.test/index.php/apps/opencatalogi/publication/pub-1',
		]);
		$this->client->method('createPublication')->willReturn(['id' => 'pub-1']);
		$this->client->method('updatePublication')->willReturn(['id' => 'pub-1']);
		$this->notice->expects(self::never())->method('tell');

		$this->service->publish(self::CASE, 'dec-1');
	}//end testARepublishDoesNotTellTheResidentAgain()

	/**
	 * Publishing without a decision id uses the case's Woo decision and writes the journey fields.
	 *
	 * @return void
	 */
	public function testPublishWithoutADecisionIdSendsTheJourneyFields(): void {
		$sent = [];
		$this->client->expects(self::once())->method('createPublication')->willReturnCallback(
			function (string $register, string $schema, array $payload) use (&$sent): array {
				$sent = $payload;
				return ['id' => 'pub-1'];
			}
		);
		$this->client->expects(self::never())->method('attachDocument');
		$this->client->expects(self::once())->method('attachFile')->with(
			'publication',
			'publication',
			'pub-1',
			'nota.pdf',
			base64_encode('pdf'),
			'application/pdf',
		);

		$result = $this->service->publish(self::CASE);

		self::assertTrue($result['available']);
		self::assertSame('woo-besluit', $sent['publicationKind']);
		self::assertSame('infocat014', $sent['wooCategory']);
		self::assertSame(self::CASE, $sent['caseReference']);
		self::assertSame(['from' => '2025-01-01', 'to' => '2025-12-31'], $sent['period']);
		self::assertLessThanOrEqual(time(), strtotime($sent['publicationDate']));
		foreach (['tooiCategorieUri', 'tooiCategorieNaam', 'documentCount', 'informatiecategorie'] as $undeclared) {
			self::assertArrayNotHasKey($undeclared, $sent);
		}

		self::assertSame('published', $this->store->row(schema: 'decision', uuid: 'dec-1')['wooPublication']['status']);
	}//end testPublishWithoutADecisionIdSendsTheJourneyFields()

	/**
	 * After publishing the case reads published with an absolute link, and the dossier is told.
	 *
	 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-decision-comes-back-to-the-dossier-it-was-asked-from-req-wpi-008
	 *
	 * @return void
	 */
	public function testPublishingUpdatesTheCaseAndReturnsToTheDossier(): void {
		$this->client->method('createPublication')->willReturn(['id' => 'pub-1']);
		$this->return->expects(self::once())->method('append')->with(
			self::callback(fn (array $case): bool => ($case['wooRequest']['collectionId'] ?? '') === 'col-1'),
			'pub-1',
			'Parkeerbeleid centrum',
		)->willReturn(true);

		$this->service->publish(self::CASE, 'dec-1');

		$case = $this->store->row(schema: 'case', uuid: self::CASE);
		self::assertSame('published', $case['wooPublicationStatus']);
		self::assertSame('https://gemeente.test/index.php/apps/opencatalogi/publication/pub-1', $case['wooPublicationUrl']);
		self::assertSame('Parkeerbeleid centrum', $case['title']);
	}//end testPublishingUpdatesTheCaseAndReturnsToTheDossier()

	/**
	 * A failed publication neither changes the case nor touches the dossier.
	 *
	 * @return void
	 */
	public function testAFailedPublicationLeavesTheCaseReady(): void {
		$this->client->method('createPublication')->willThrowException(new \RuntimeException('opencatalogi_api_error'));
		$this->return->expects(self::never())->method('append');

		$result = $this->service->publish(self::CASE);

		self::assertSame('opencatalogi_api_error', $result['reason']);
		self::assertSame('ready', $this->store->row(schema: 'case', uuid: self::CASE)['wooPublicationStatus']);
	}//end testAFailedPublicationLeavesTheCaseReady()

	/**
	 * No Woo decision, and more than one, are named refusals, and nothing is sent.
	 *
	 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publish-endpoints-find-the-cases-woo-decision-req-wpi-005
	 *
	 * @return void
	 */
	public function testTheCaseMustHaveExactlyOneWooDecision(): void {
		$this->client->expects(self::never())->method('createPublication');

		$this->store->seed(schema: 'decision', uuid: 'dec-2', row: ['case' => self::CASE, 'wooSummary' => ['openbaar' => 2]]);
		$several = $this->service->publish(self::CASE);
		self::assertSame('several_woo_decisions', $several['reason']);
		self::assertSame(['dec-1', 'dec-2'], $several['decisionIds']);

		unset($this->store->rows['decision']);
		$this->store->seed(schema: 'decision', uuid: 'dec-3', row: ['case' => self::CASE, 'decisionType' => 'other']);
		self::assertSame('no_woo_decision', $this->service->publish(self::CASE)['reason']);
	}//end testTheCaseMustHaveExactlyOneWooDecision()

	/**
	 * Withdrawing sets the schema's depublicationDate and marks the case withdrawn.
	 *
	 * @return void
	 */
	public function testWithdrawSetsDepublicationDate(): void {
		$this->store->rows['decision']['dec-1']['wooPublication'] = ['publicationId' => 'pub-1', 'status' => 'published'];
		$this->client->expects(self::once())->method('updatePublication')->with(
			'publication',
			'publication',
			'pub-1',
			self::callback(fn (array $payload): bool => array_keys($payload) === ['depublicationDate']),
		)->willReturn(['id' => 'pub-1']);

		$result = $this->service->withdraw('', self::CASE);

		self::assertTrue($result['available']);
		self::assertSame('withdrawn', $this->store->row(schema: 'case', uuid: self::CASE)['wooPublicationStatus']);
		self::assertSame('withdrawn', $this->store->row(schema: 'decision', uuid: 'dec-1')['wooPublication']['status']);
	}//end testWithdrawSetsDepublicationDate()
}//end class
