<?php

/**
 * Mail Parties Report Test
 *
 * The collected mail of a case counted per sender, recipient and domain, read
 * through the real CaseDocumentProvenance over an in-memory register.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\CaseDocumentProvenance;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Service\Zaakdossier\MailPartiesReport;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\MailPartiesReport
 * @covers \OCA\Dossiq\Service\Zaakdossier\CaseDocumentProvenance
 * @uses   \OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore
 * @uses   \OCA\Dossiq\Woo\WooCaseDocuments
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class MailPartiesReportTest extends TestCase {

	private const CASE_ID = '22222222-2222-4222-8222-222222222222';

	private InMemoryRegister $register;

	private MailPartiesReport $report;

	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'dossier_informatieobject_schema' => 'informatieobject',
				'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
			][$key] ?? $default
		);

		$this->report = new MailPartiesReport(
			provenance: new CaseDocumentProvenance(
				store: new DocumentRecordStore(settingsService: $settings),
				caseDocuments: new WooCaseDocuments(
					settingsService: $settings,
					rootFolder: $this->createMock(IRootFolder::class),
					logger: $this->createMock(LoggerInterface::class)
				),
			)
		);
	}//end setUp()

	/**
	 * A document on the case with the given provenance.
	 *
	 * @param string               $id         The document uuid.
	 * @param array<string, mixed> $provenance The provenance.
	 *
	 * @return void
	 */
	private function onCase(string $id, array $provenance): void {
		$this->register->seed('informatieobject', $id, ['titel' => $id, 'provenance' => $provenance]);
		$this->register->seed('zaakinformatieobject', 'join-' . $id, ['case' => self::CASE_ID, 'informatieobject' => $id]);
	}//end onCase()

	/**
	 * REQ-WRR-004 "By sender, recipient and domain", the scenario fixture.
	 *
	 * @return void
	 */
	public function testCountsPerSenderRecipientAndDomain(): void {
		$this->onCase(id: 'm1', provenance: ['mail' => ['from' => 'a.jansen@gemeente.nl', 'to' => ['b@aannemer.nl']]]);
		$this->onCase(id: 'm2', provenance: ['mail' => ['from' => 'A.Jansen@gemeente.nl', 'to' => ['b@aannemer.nl'], 'cc' => ['b@aannemer.nl']]]);
		$this->onCase(id: 'm3', provenance: ['mail' => ['from' => 'b@aannemer.nl', 'to' => ['a.jansen@gemeente.nl']]]);
		$this->onCase(id: 'pdf', provenance: ['source' => 'files']);

		$report = $this->report->forCase(caseId: self::CASE_ID);

		self::assertSame(3, $report['mails']);
		self::assertSame(0, $report['unreadable']);
		self::assertSame([['address' => 'a.jansen@gemeente.nl', 'count' => 2], ['address' => 'b@aannemer.nl', 'count' => 1]], $report['senders']);
		self::assertSame([['address' => 'b@aannemer.nl', 'count' => 2], ['address' => 'a.jansen@gemeente.nl', 'count' => 1]], $report['recipients']);
		self::assertSame([['domain' => 'aannemer.nl', 'count' => 3], ['domain' => 'gemeente.nl', 'count' => 3]], $report['domains']);
	}//end testCountsPerSenderRecipientAndDomain()

	/**
	 * REQ-WRR-003/004: a mail whose headers could not be read is counted under unreadable, not left out.
	 *
	 * @return void
	 */
	public function testUnreadableIsCounted(): void {
		$this->onCase(id: 'm1', provenance: ['mail' => ['from' => 'a@gemeente.nl', 'to' => ['b@aannemer.nl']]]);
		$this->onCase(id: 'm2', provenance: ['mail' => ['unreadable' => true]]);

		$report = $this->report->forCase(caseId: self::CASE_ID);

		self::assertSame(2, $report['mails']);
		self::assertSame(1, $report['unreadable']);
		self::assertSame([['address' => 'a@gemeente.nl', 'count' => 1]], $report['senders']);
	}//end testUnreadableIsCounted()

	/**
	 * The CSV holds the same rows, one per party, unreadable last.
	 *
	 * @return void
	 */
	public function testTheCsvHasTheSameRows(): void {
		$this->onCase(id: 'm1', provenance: ['mail' => ['from' => 'a@gemeente.nl', 'to' => ['b@aannemer.nl']]]);
		$this->onCase(id: 'm2', provenance: ['mail' => ['unreadable' => true]]);

		$csv = $this->report->toCsv(report: $this->report->forCase(caseId: self::CASE_ID));

		self::assertSame(
			"kind,party,count\nsender,a@gemeente.nl,1\nrecipient,b@aannemer.nl,1\ndomain,aannemer.nl,1\ndomain,gemeente.nl,1\nunreadable,,1\n",
			$csv
		);
	}//end testTheCsvHasTheSameRows()
}//end class
