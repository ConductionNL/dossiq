<?php

/**
 * A delivered set is re-verified against the files as they are now.
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
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-set-is-re-verifiable-req-wds-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCA\Dossiq\Woo\WooDeliveredSetVerifier;
use OCA\Dossiq\Woo\WooDeliveredSetWriter;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-WDS-003 on the verifier, over a set the real writer wrote.
 *
 * @covers \OCA\Dossiq\Woo\WooDeliveredSetVerifier
 *
 * @uses \OCA\Dossiq\Woo\WooDeliveredSetWriter
 * @uses \OCA\Dossiq\Woo\WooCaseDocuments
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class WooDeliveredSetVerifierTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The verifier.
	 *
	 * @var WooDeliveredSetVerifier
	 */
	private WooDeliveredSetVerifier $verifier;

	/**
	 * The written set.
	 *
	 * @var array<string, mixed>
	 */
	private array $set = [];

	/**
	 * Two documents delivered and frozen.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'document', uuid: 'doc-1', row: ['fileName' => 'brief.pdf', 'content' => base64_encode('brief')]);
		$this->store->seed(schema: 'document', uuid: 'doc-2-red', row: ['fileName' => 'nota.pdf', 'content' => base64_encode('gelakt')]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'document_schema' => 'document'][$key] ?? $default)
		);

		$writer = new WooDeliveredSetWriter(settings: $settings);
		$written = $writer->open(caseId: 'case-1', decisionId: 'dec-1', delivered: [
			['assessment' => 'as-1', 'classification' => 'openbaar', 'deliveredRef' => 'doc-1', 'originalRef' => 'doc-1', 'content' => base64_encode('brief')],
			['assessment' => 'as-2', 'classification' => 'deels_openbaar', 'deliveredRef' => 'doc-2-red', 'originalRef' => 'doc-2', 'content' => base64_encode('gelakt')],
		]);
		$this->verifier = new WooDeliveredSetVerifier(
			settings: $settings,
			sets: $writer,
			documents: new WooCaseDocuments(settingsService: $settings, rootFolder: $this->createMock(IRootFolder::class), logger: new NullLogger()),
		);
		$this->set = (array)$this->verifier->find(setId: $writer->idOf(row: $written));
	}//end setUp()

	/**
	 * Unchanged files verify, item by item and as a set.
	 *
	 * @return void
	 */
	public function testAnUntouchedSetVerifies(): void {
		$result = $this->verifier->verify(set: $this->set);

		$this->assertTrue($result['verified']);
		$this->assertSame(['match', 'match'], array_column($result['items'], 'status'));
		$this->assertSame($result['setHash']['expected'], $result['setHash']['actual']);
	}//end testAnUntouchedSetVerifies()

	/**
	 * An overwritten redaction is caught with both hashes.
	 *
	 * @return void
	 */
	public function testAReplacedFileIsCaught(): void {
		$this->store->seed(schema: 'document', uuid: 'doc-2-red', row: ['fileName' => 'nota.pdf', 'content' => base64_encode('ongelakt')]);

		$result = $this->verifier->verify(set: $this->set);

		$this->assertFalse($result['verified']);
		$this->assertSame('changed', $result['items'][1]['status']);
		$this->assertSame(hash('sha256', 'gelakt'), $result['items'][1]['expected']);
		$this->assertSame(hash('sha256', 'ongelakt'), $result['items'][1]['actual']);
	}//end testAReplacedFileIsCaught()

	/**
	 * A file that cannot be read is missing, never a match.
	 *
	 * @return void
	 */
	public function testAnUnreadableFileIsMissingNotMatch(): void {
		$this->store->deleteObject(register: 'dossiq', schema: 'document', uuid: 'doc-1');

		$result = $this->verifier->verify(set: $this->set);

		$this->assertFalse($result['verified']);
		$this->assertSame('missing', $result['items'][0]['status']);
		$this->assertSame('', $result['items'][0]['actual']);
	}//end testAnUnreadableFileIsMissingNotMatch()
}//end class
