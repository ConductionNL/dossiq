<?php

/**
 * The delivered set's manifest and hash follow the rule exactly.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-every-delivery-writes-a-set-with-its-own-identity-and-manifest-req-wds-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCA\Dossiq\Woo\WooDeliveredSetWriter;
use PHPUnit\Framework\TestCase;

/**
 * REQ-WDS-001 and the supersede and withdraw halves of REQ-WDS-002 on the writer.
 *
 * @covers \OCA\Dossiq\Woo\WooDeliveredSetWriter
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class WooDeliveredSetWriterTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The writer over the store.
	 *
	 * @return WooDeliveredSetWriter
	 */
	private function writer(): WooDeliveredSetWriter {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq'][$key] ?? $default)
		);

		return new WooDeliveredSetWriter(settings: $settings);
	}//end writer()

	/**
	 * Reset.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
	}//end setUp()

	/**
	 * The set hash over three items, against a hash computed by hand outside PHP.
	 *
	 * @return void
	 */
	public function testTheSetHashFollowsTheRule(): void {
		$items = [
			['deliveredRef' => 'doc-c', 'sha256' => str_repeat('c', 64)],
			['deliveredRef' => 'doc-a', 'sha256' => str_repeat('a', 64)],
			['deliveredRef' => 'doc-b', 'sha256' => str_repeat('b', 64)],
		];

		// sha256 of "aaa…  doc-a\nbbb…  doc-b\nccc…  doc-c", computed with python's hashlib.
		$this->assertSame('dfb452540af954ecaf8e71d737ac7079412fd6ea9545fa7e452256d81fbc05f6', $this->writer()->setHash(items: $items));
	}//end testTheSetHashFollowsTheRule()

	/**
	 * A partly public document goes out as its redaction, hashed over the redacted bytes.
	 *
	 * @return void
	 */
	public function testTheDeelsOpenbaarItemCarriesTheRedactedBytesHash(): void {
		$set = $this->writer()->open(caseId: 'case-1', decisionId: 'dec-1', delivered: [
			['assessment' => 'as-1', 'classification' => 'openbaar', 'deliveredRef' => 'doc-1', 'originalRef' => 'doc-1', 'fileName' => 'brief.pdf', 'content' => base64_encode('public bytes')],
			['assessment' => 'as-2', 'classification' => 'deels_openbaar', 'deliveredRef' => 'doc-2-red', 'originalRef' => 'doc-2', 'fileName' => 'nota.pdf', 'content' => base64_encode('redacted bytes')],
		]);

		$this->assertSame('pending', $set['status']);
		$this->assertSame('doc-2-red', $set['items'][1]['deliveredRef']);
		$this->assertSame('doc-2', $set['items'][1]['originalRef']);
		$this->assertSame('bb66fd151ee279d603b557e392d15932461689df706d178681a95f4a844b1700', $set['items'][1]['sha256']);
		$this->assertSame(strlen('redacted bytes'), $set['items'][1]['size']);
		$this->assertSame($this->writer()->setHash(items: $set['items']), $set['setHash']);
		$this->assertSame([], (new RealSchemaValidator())->errors(slug: 'wooDeliveredSet', payload: $set, creating: true));
	}//end testTheDeelsOpenbaarItemCarriesTheRedactedBytesHash()

	/**
	 * A second delivery supersedes the frozen set, and a withdraw stamps it without unfreezing.
	 *
	 * @return void
	 */
	public function testASecondDeliveryIsANewSetAndAWithdrawKeepsTheFirstFrozen(): void {
		$writer = $this->writer();
		$first = $writer->open(caseId: 'case-1', decisionId: 'dec-1', delivered: []);
		$writer->freeze(setId: $writer->idOf(row: $first), publicationId: 'pub-1');
		$writer->markWithdrawn(caseId: 'case-1', publicationId: 'pub-1');

		$second = $writer->open(caseId: 'case-1', decisionId: 'dec-1', delivered: []);

		$stored = $this->store->row(schema: 'wooDeliveredSet', uuid: $writer->idOf(row: $first));
		$this->assertSame('frozen', $stored['status']);
		$this->assertNotEmpty($stored['withdrawnAt']);
		$this->assertSame($writer->idOf(row: $first), $second['supersedes']);
	}//end testASecondDeliveryIsANewSetAndAWithdrawKeepsTheFirstFrozen()

	/**
	 * A discarded pending set is gone.
	 *
	 * @return void
	 */
	public function testADiscardedSetIsGone(): void {
		$writer = $this->writer();
		$set = $writer->open(caseId: 'case-1', decisionId: 'dec-1', delivered: []);
		$writer->discard(setId: $writer->idOf(row: $set));

		$this->assertSame([], $this->store->all(schema: 'wooDeliveredSet'));
	}//end testADiscardedSetIsGone()

	/**
	 * deliver() freezes after a good send, deletes after a failed one, and sends nothing when no set can be written.
	 *
	 * @return void
	 */
	public function testDeliverFreezesOrDiscardsAroundTheSend(): void {
		$writer = $this->writer();
		$good = $writer->deliver(caseId: 'case-1', decisionId: 'dec-1', delivered: [], send: static fn (): string => 'pub-1');
		$this->assertSame('frozen', $this->store->row(schema: 'wooDeliveredSet', uuid: $good['setId'])['status']);

		try {
			$writer->deliver(caseId: 'case-2', decisionId: 'dec-2', delivered: [], send: static function (): string {
				throw new \RuntimeException('opencatalogi down');
			});
			$this->fail('a failed send must be rethrown');
		} catch (\RuntimeException $e) {
			$this->assertSame('opencatalogi down', $e->getMessage());
		}

		$this->assertCount(1, $this->store->all(schema: 'wooDeliveredSet'));

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$sent = false;
		try {
			(new WooDeliveredSetWriter(settings: $settings))->deliver(caseId: 'case-3', decisionId: 'dec-3', delivered: [], send: static function () use (&$sent): string {
				$sent = true;
				return 'pub-3';
			});
			$this->fail('no store must refuse the delivery');
		} catch (\RuntimeException $e) {
			$this->assertSame(WooDeliveredSetWriter::SET_NOT_WRITTEN, $e->getMessage());
		}

		$this->assertFalse($sent);
	}//end testDeliverFreezesOrDiscardsAroundTheSend()
}//end class
