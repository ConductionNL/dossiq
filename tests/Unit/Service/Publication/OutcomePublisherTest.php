<?php

/**
 * Unit tests for the publisher of a case outcome: availability and payload.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Publication
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use OCA\Dossiq\Service\Publication\OpenCatalogiApiClient;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WooPublication\WooCategoryMapper;
use OCA\Dossiq\Service\WooPublicationService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins the publisher's stored values, its availability answers and the payload it builds.
 *
 * @covers \OCA\Dossiq\Service\WooPublicationService
 *
 * @uses \OCA\Dossiq\Service\Publication\CasePublicationState
 * @uses \OCA\Dossiq\Service\Publication\CatalogTransport
 * @uses \OCA\Dossiq\Service\Publication\PublicationSources
 * @uses \OCA\Dossiq\Service\WooPublication\WooCategoryMapper
 */
class OutcomePublisherTest extends TestCase {

	/**
	 * A publisher over the given app state.
	 *
	 * @param bool $installed Whether opencatalogi is installed.
	 * @param bool $enabled   Whether it is enabled.
	 * @param bool $register  Whether OpenRegister is there.
	 *
	 * @return WooPublicationService
	 */
	private function publisher(bool $installed = true, bool $enabled = true, bool $register = true): WooPublicationService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register === true ? new \stdClass() : null);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->with('opencatalogi')->willReturn($installed);
		$apps->method('isEnabledForUser')->with('opencatalogi')->willReturn($enabled);

		return new WooPublicationService(
			$settings,
			$this->createMock(OpenCatalogiApiClient::class),
			new WooCategoryMapper(),
			$apps,
			new NullLogger(),
		);
	}//end publisher()

	/**
	 * The stored values the publisher writes keep their spelling.
	 *
	 * @return void
	 */
	public function testTheStoredValuesKeepTheirSpelling(): void {
		$this->assertSame('woo-besluit', WooPublicationService::PUBLICATION_KIND);
		$this->assertSame('ready', WooPublicationService::STATUS_READY);
		$this->assertSame('published', WooPublicationService::STATUS_PUBLISHED);
		$this->assertSame('withdrawn', WooPublicationService::STATUS_WITHDRAWN);
	}//end testTheStoredValuesKeepTheirSpelling()

	/**
	 * Availability: opencatalogi installed and enabled, then OpenRegister.
	 *
	 * @return void
	 */
	public function testAvailabilityAnswers(): void {
		$this->assertSame(['available' => true], $this->publisher()->checkAvailability());
		$this->assertSame(['available' => false, 'reason' => 'opencatalogi_not_installed'], $this->publisher(installed: false)->checkAvailability());
		$this->assertSame(['available' => false, 'reason' => 'opencatalogi_not_installed'], $this->publisher(enabled: false)->checkAvailability());
		$this->assertSame(['available' => false, 'reason' => 'openregister_unavailable'], $this->publisher(register: false)->checkAvailability());
	}//end testAvailabilityAnswers()

	/**
	 * The payload carries the case title, the decision texts, the kind, the category and the period.
	 *
	 * @return void
	 */
	public function testThePayloadOfACaseWithAPeriod(): void {
		$payload = $this->publisher()->buildPayload(
			case: ['id' => 'case-1', 'title' => 'Verzoek parkeerbeleid', 'wooRequest' => ['periodeVan' => '2025-01-01', 'periodeTot' => '2025-12-31']],
			decision: ['case' => 'case-1', 'title' => 'Besluit', 'description' => 'Kort', 'explanation' => 'Lang', 'decisionType' => '3c0f5a00-0000-4000-a000-00000000d001'],
		);

		$this->assertNotSame('', $payload['publicationDate']);
		unset($payload['publicationDate']);
		$this->assertSame(
			[
				'title' => 'Verzoek parkeerbeleid',
				'summary' => 'Kort',
				'description' => 'Lang',
				'status' => 'published',
				'publicationKind' => 'woo-besluit',
				'wooCategory' => 'infocat014',
				'caseReference' => 'case-1',
				'period' => ['from' => '2025-01-01', 'to' => '2025-12-31'],
			],
			$payload
		);
	}//end testThePayloadOfACaseWithAPeriod()

	/**
	 * Without a case title the decision's title is used, then a fallback; no period without dates.
	 *
	 * @return void
	 */
	public function testThePayloadFallbacks(): void {
		$publisher = $this->publisher();

		$fromDecision = $publisher->buildPayload(case: ['uuid' => 'case-2'], decision: ['title' => 'Besluit', 'description' => 'Kort']);
		$this->assertSame('Besluit', $fromDecision['title']);
		$this->assertSame('Kort', $fromDecision['description']);
		$this->assertSame('case-2', $fromDecision['caseReference']);
		$this->assertArrayNotHasKey('period', $fromDecision);

		$bare = $publisher->buildPayload(case: [], decision: ['case' => 'case-3', 'decisionType' => 'iets-anders']);
		$this->assertSame('WOO-besluit case-3', $bare['title']);
		$this->assertSame('', $bare['summary']);
		$this->assertSame('', $bare['description']);
		$this->assertSame('infocat014', $bare['wooCategory']);

		$half = $publisher->buildPayload(case: ['id' => 'c', 'wooRequest' => ['periodeVan' => '2025-01-01']], decision: []);
		$this->assertSame(['from' => '2025-01-01', 'to' => ''], $half['period']);
	}//end testThePayloadFallbacks()
}//end class
