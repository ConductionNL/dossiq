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
use OCA\Dossiq\Service\Publication\PublicationCategory;
use OCA\Dossiq\Service\Publication\OutcomePublisher;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins the publisher's stored values, its availability answers and the payload it builds.
 *
 * @covers \OCA\Dossiq\Service\Publication\OutcomePublisher
 *
 * @uses \OCA\Dossiq\Service\Publication\CasePublicationState
 * @uses \OCA\Dossiq\Service\Publication\CatalogTransport
 * @uses \OCA\Dossiq\Service\Publication\PublicationSources
 * @uses \OCA\Dossiq\Service\Publication\PublicationCategory
 */
class OutcomePublisherTest extends TestCase {

	/**
	 * A publisher over the given app state.
	 *
	 * @param bool $installed Whether opencatalogi is installed.
	 * @param bool $enabled   Whether it is enabled.
	 * @param bool $register  Whether OpenRegister is there.
	 *
	 * @return OutcomePublisher
	 */
	private function publisher(bool $installed = true, bool $enabled = true, bool $register = true): OutcomePublisher {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$objectService = null;
		if ($register === true) {
			$objectService = new \stdClass();
		}

		$settings->method('getObjectService')->willReturn($objectService);
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('isInstalled')->with('opencatalogi')->willReturn($installed);
		$apps->method('isEnabledForUser')->with('opencatalogi')->willReturn($enabled);

		return new OutcomePublisher(
			settingsService: $settings,
			apiClient: $this->createMock(originalClassName: OpenCatalogiApiClient::class),
			publicationCategory: new PublicationCategory(),
			appManager: $apps,
			logger: new NullLogger(),
		);
	}//end publisher()

	/**
	 * The stored values the publisher writes keep their spelling.
	 *
	 * @return void
	 */
	public function testTheStoredValuesKeepTheirSpelling(): void {
		$this->assertSame(expected: 'woo-besluit', actual: OutcomePublisher::PUBLICATION_KIND);
		$this->assertSame(expected: 'ready', actual: OutcomePublisher::STATUS_READY);
		$this->assertSame(expected: 'published', actual: OutcomePublisher::STATUS_PUBLISHED);
		$this->assertSame(expected: 'withdrawn', actual: OutcomePublisher::STATUS_WITHDRAWN);
	}//end testTheStoredValuesKeepTheirSpelling()

	/**
	 * Availability: opencatalogi installed and enabled, then OpenRegister.
	 *
	 * @return void
	 */
	public function testAvailabilityAnswers(): void {
		$this->assertSame(expected: ['available' => true], actual: $this->publisher()->checkAvailability());
		$this->assertSame(
			expected: ['available' => false, 'reason' => 'opencatalogi_not_installed'],
			actual: $this->publisher(installed: false)->checkAvailability()
		);
		$this->assertSame(
			expected: ['available' => false, 'reason' => 'opencatalogi_not_installed'],
			actual: $this->publisher(enabled: false)->checkAvailability()
		);
		$this->assertSame(
			expected: ['available' => false, 'reason' => 'openregister_unavailable'],
			actual: $this->publisher(register: false)->checkAvailability()
		);
	}//end testAvailabilityAnswers()

	/**
	 * The payload carries the case title, the decision texts, the kind, the category and the period.
	 *
	 * @return void
	 */
	public function testThePayloadOfACaseWithAPeriod(): void {
		$payload = $this->publisher()->buildPayload(
			case: ['id' => 'case-1', 'title' => 'Verzoek parkeerbeleid', 'wooRequest' => ['periodeVan' => '2025-01-01', 'periodeTot' => '2025-12-31']],
			decision: [
				'case' => 'case-1',
				'title' => 'Besluit',
				'description' => 'Kort',
				'explanation' => 'Lang',
				'decisionType' => '3c0f5a00-0000-4000-a000-00000000d001',
			],
		);

		$this->assertNotSame(expected: '', actual: $payload['publicationDate']);
		unset($payload['publicationDate']);
		$this->assertSame(
			expected: [
				'title' => 'Verzoek parkeerbeleid',
				'summary' => 'Kort',
				'description' => 'Lang',
				'status' => 'published',
				'publicationKind' => 'woo-besluit',
				'wooCategory' => 'infocat014',
				'caseReference' => 'case-1',
				'period' => ['from' => '2025-01-01', 'to' => '2025-12-31'],
			],
			actual: $payload
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
		$this->assertSame(expected: 'Besluit', actual: $fromDecision['title']);
		$this->assertSame(expected: 'Kort', actual: $fromDecision['description']);
		$this->assertSame(expected: 'case-2', actual: $fromDecision['caseReference']);
		$this->assertArrayNotHasKey(key: 'period', array: $fromDecision);

		$bare = $publisher->buildPayload(case: [], decision: ['case' => 'case-3', 'decisionType' => 'iets-anders']);
		$this->assertSame(expected: 'WOO-besluit case-3', actual: $bare['title']);
		$this->assertSame(expected: '', actual: $bare['summary']);
		$this->assertSame(expected: '', actual: $bare['description']);
		$this->assertSame(expected: 'infocat014', actual: $bare['wooCategory']);

		$half = $publisher->buildPayload(case: ['id' => 'c', 'wooRequest' => ['periodeVan' => '2025-01-01']], decision: []);
		$this->assertSame(expected: ['from' => '2025-01-01', 'to' => ''], actual: $half['period']);
	}//end testThePayloadFallbacks()
}//end class
