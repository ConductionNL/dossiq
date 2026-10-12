<?php

/**
 * A publication goes to the catalog with its files after the set is written, and a withdraw ends read access.
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
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use OCA\Dossiq\Service\Publication\CatalogTransport;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WooPublication\OpenCatalogiApiClient;
use OCA\Dossiq\Woo\WooCaseLedger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Publication\CatalogTransport
 */
class CatalogTransportTest extends TestCase {

	/**
	 * The catalog's side.
	 *
	 * @var OpenCatalogiApiClient&MockObject
	 */
	private OpenCatalogiApiClient&MockObject $api;

	/**
	 * The ledger that writes the set around the send.
	 *
	 * @var WooCaseLedger&MockObject
	 */
	private WooCaseLedger&MockObject $ledger;

	/**
	 * The transport under test.
	 *
	 * @var CatalogTransport
	 */
	private CatalogTransport $transport;

	/**
	 * A transport over doubled catalog and ledger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		/** @var SettingsService&MockObject $settings */
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getWooPublicationConfigValue')->willReturn('publication');
		$this->api = $this->createMock(OpenCatalogiApiClient::class);
		$this->ledger = $this->createMock(WooCaseLedger::class);
		$this->transport = new CatalogTransport(settingsService: $settings, apiClient: $this->api, logger: new NullLogger(), caseLedger: $this->ledger);
	}//end setUp()

	/**
	 * The ledger runs the send: a new publication is created and every file with content is attached.
	 *
	 * @return void
	 */
	public function testANewPublicationIsCreatedWithItsFiles(): void {
		$this->ledger->method('deliver')->willReturnCallback(
			static fn (string $caseId, string $decisionId, array $items, callable $send): array => ['publicationId' => $send(), 'setId' => 'set-1']
		);
		$this->api->expects(self::never())->method('updatePublication');
		$this->api->expects(self::once())->method('createPublication')->willReturn(['id' => 'pub-1']);
		$this->api->expects(self::once())->method('attachFile')->with(
			'publication',
			'publication',
			'pub-1',
			'a.pdf',
			'Ynl0ZXM=',
			'application/pdf'
		)->willReturn([]);

		$delivered = $this->transport->deliver(
			caseId: 'case-1',
			decisionId: 'dec-1',
			items: [],
			payload: ['title' => 'Besluit'],
			disclosable: [['fileName' => 'a.pdf', 'format' => 'application/pdf', 'content' => 'Ynl0ZXM='], ['fileName' => 'empty.pdf', 'content' => '']],
			existingId: ''
		);

		self::assertSame(['publicationId' => 'pub-1', 'setId' => 'set-1'], $delivered);
	}//end testANewPublicationIsCreatedWithItsFiles()

	/**
	 * A republish updates the existing publication.
	 *
	 * @return void
	 */
	public function testARepublishUpdatesTheExistingPublication(): void {
		$this->ledger->method('deliver')->willReturnCallback(
			static fn (string $caseId, string $decisionId, array $items, callable $send): array => ['publicationId' => $send(), 'setId' => 'set-2']
		);
		$this->api->expects(self::once())->method('updatePublication')->willReturn(['id' => 'pub-1']);
		$this->api->expects(self::never())->method('createPublication');

		$delivered = $this->transport->deliver(caseId: 'case-1', decisionId: 'dec-1', items: [], payload: [], disclosable: [], existingId: 'pub-1');

		self::assertSame('pub-1', $delivered['publicationId'] ?? null);
	}//end testARepublishUpdatesTheExistingPublication()

	/**
	 * A failed send answers the API reason; a set that could not be written answers its own.
	 *
	 * @return void
	 */
	public function testAFailedDeliveryAnswersItsReason(): void {
		$failures = ['timeout', WooCaseLedger::SET_NOT_WRITTEN];
		$this->ledger->method('deliver')->willReturnCallback(
			static function () use (&$failures): array {
				throw new RuntimeException((string)array_shift($failures));
			}
		);

		self::assertSame(['reason' => 'opencatalogi_api_error'], $this->transport->deliver(caseId: 'c', decisionId: 'd', items: [], payload: [], disclosable: [], existingId: ''));
		self::assertSame(['reason' => 'delivered_set_not_written'], $this->transport->deliver(caseId: 'c', decisionId: 'd', items: [], payload: [], disclosable: [], existingId: ''));
	}//end testAFailedDeliveryAnswersItsReason()

	/**
	 * A withdraw sets the depublication date, and a failure answers its reason.
	 *
	 * @return void
	 */
	public function testAWithdrawEndsReadAccess(): void {
		$this->api->expects(self::exactly(2))->method('updatePublication')->willReturnCallback(
			static function (string $register, string $schema, string $id, array $payload): array {
				self::assertArrayHasKey('depublicationDate', $payload);
				if ($id === 'pub-broken') {
					throw new RuntimeException('down');
				}

				return ['id' => $id];
			}
		);

		self::assertSame('', $this->transport->depublish(publicationId: 'pub-1', decisionId: 'dec-1'));
		self::assertSame('opencatalogi_api_error', $this->transport->depublish(publicationId: 'pub-broken', decisionId: 'dec-1'));
	}//end testAWithdrawEndsReadAccess()
}//end class
