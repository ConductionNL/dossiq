<?php

/**
 * SubsidieRegisterExporter Unit Tests.
 *
 * Exercises the Wet open overheid feed shaping (REQ-SUB-006): applicant
 * anonymisation, feed-entry mapping and the paginated JSON-LD envelope.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Subsidie
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
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Subsidie;

use OCA\Procest\Service\Subsidie\SubsidieRegisterExporter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Service\Subsidie\SubsidieRegisterExporter
 *
 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-39
 */
class SubsidieRegisterExporterTest extends TestCase {

	private SubsidieRegisterExporter $exporter;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->exporter = new SubsidieRegisterExporter();
	}//end setUp()

	/**
	 * REQ-SUB-006: legal persons keep their name; natural persons anonymise.
	 *
	 * @return void
	 */
	public function testAnonymisation(): void {
		$legal = ['aanvragerKvkRef' => '12345678', 'aanvragerNaam' => 'Stichting X'];
		$this->assertSame('Stichting X', $this->exporter->publicOntvanger($legal));

		$legalNoName = ['aanvragerKvkRef' => '12345678'];
		$this->assertSame('KvK 12345678', $this->exporter->publicOntvanger($legalNoName));

		// No KvK -> natural person -> anonymised, never leaking name/BSN.
		$natural = ['aanvragerNaam' => 'Jan Jansen', 'aanvragerBsnRef' => '******789'];
		$this->assertSame('Particulier', $this->exporter->publicOntvanger($natural));
	}//end testAnonymisation()

	/**
	 * @return void
	 */
	public function testFeedEntryMapping(): void {
		$request = ['aanvragerKvkRef' => '999', 'aanvragerNaam' => 'BV Y'];
		$regeling = ['regelingNaam' => 'Innovatiefonds 2026', 'doelgroep' => 'MKB'];
		$decision = [
			'beschikkingtype' => 'verleningsbeschikking',
			'verleendBedrag' => 450000,
			'looptijdStart' => '2026-01-01',
			'looptijdEind' => '2028-12-31',
			'wettelijkeGrondslag' => 'AWB titel 4.2',
		];

		$entry = $this->exporter->toFeedEntry($request, $regeling, $decision);
		$this->assertSame('Innovatiefonds 2026', $entry['regeling']);
		$this->assertSame('BV Y', $entry['ontvanger']);
		$this->assertSame(450000.0, $entry['bedrag']);
		$this->assertSame('verleend', $entry['status']);
		$this->assertSame('2028-12-31', $entry['looptijd']['eind']);
	}//end testFeedEntryMapping()

	/**
	 * A vaststellingsbeschikking flips the feed status to "vastgesteld".
	 *
	 * @return void
	 */
	public function testVastgesteldStatus(): void {
		$entry = $this->exporter->toFeedEntry([], [], ['beschikkingtype' => 'vaststellingsbeschikking']);
		$this->assertSame('vastgesteld', $entry['status']);
	}//end testVastgesteldStatus()

	/**
	 * REQ-SUB-006: the feed is a paginated JSON-LD document.
	 *
	 * @return void
	 */
	public function testPaginatedFeed(): void {
		$entries = [];
		for ($i = 0; $i < 5; $i++) {
			$entries[] = ['regeling' => 'R' . $i];
		}

		$feed = $this->exporter->buildFeed($entries, 2, 2);
		$this->assertSame(SubsidieRegisterExporter::JSON_LD_CONTEXT, $feed['@context']);
		$this->assertSame('Subsidieregister', $feed['@type']);
		$this->assertSame(5, $feed['total']);
		$this->assertCount(2, $feed['results']);
		$this->assertSame('R2', $feed['results'][0]['regeling']);
	}//end testPaginatedFeed()
}//end class
