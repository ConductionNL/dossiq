<?php

/**
 * The requester's result link follows the publication, and only the publication.
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
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-sees-where-the-decision-became-public-req-wds-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCA\Dossiq\Woo\WooCaseLedger;
use OCA\Dossiq\Woo\WooResultLink;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-WDS-003 through WooCaseLedger::writeCaseState(), the seam publish() and withdraw() write through.
 *
 * @covers \OCA\Dossiq\Woo\WooResultLink
 * @covers \OCA\Dossiq\Woo\WooCaseLedger
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class WooPortalResultLinkTest extends TestCase {

	/**
	 * The cases.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The ledger over the store.
	 *
	 * @return WooCaseLedger
	 */
	private function ledger(): WooCaseLedger {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? $default)
		);

		return new WooCaseLedger(settingsService: $settings, logger: new NullLogger());
	}//end ledger()

	/**
	 * Seed one Woo case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'case', uuid: 'case-87', row: ['identifier' => '2026-0087', 'wooPublicationStatus' => 'ready']);
	}//end setUp()

	/**
	 * Publishing writes the link with the case's own publication url, valid against the case schema.
	 *
	 * @return void
	 */
	public function testPublishingWritesTheLink(): void {
		$url = 'https://zuiddrecht.nl/apps/opencatalogi/publications/p-87';
		$this->ledger()->writeCaseState(caseId: 'case-87', changes: ['wooPublicationStatus' => 'published', 'wooPublicationUrl' => $url]);

		$case = $this->store->row(schema: 'case', uuid: 'case-87');
		$this->assertSame(['label' => WooResultLink::LABEL, 'url' => $url], $case['resultLink']);
		$this->assertSame($case['wooPublicationUrl'], $case['resultLink']['url']);
		$this->assertSame([], (new RealSchemaValidator())->errors(slug: 'case', payload: ['resultLink' => $case['resultLink']], creating: false));
	}//end testPublishingWritesTheLink()

	/**
	 * Withdrawing clears the link, and the clearing write is valid against the case schema.
	 *
	 * @return void
	 */
	public function testWithdrawingClearsTheLink(): void {
		$this->ledger()->writeCaseState(caseId: 'case-87', changes: ['wooPublicationStatus' => 'published', 'wooPublicationUrl' => 'https://zuiddrecht.nl/p']);
		$this->ledger()->writeCaseState(caseId: 'case-87', changes: ['wooPublicationStatus' => 'withdrawn']);

		$case = $this->store->row(schema: 'case', uuid: 'case-87');
		$this->assertNull($case['resultLink']);
		$this->assertSame([], (new RealSchemaValidator())->errors(slug: 'case', payload: ['resultLink' => null], creating: false));
	}//end testWithdrawingClearsTheLink()

	/**
	 * A ready decision, or a published one without a url, writes no link at all.
	 *
	 * @return void
	 */
	public function testNoLinkWithoutAPublicationUrl(): void {
		$links = new WooResultLink();

		$this->assertSame([], $links->changesFor(state: ['wooPublicationStatus' => 'ready']));
		$this->assertSame([], $links->changesFor(state: ['wooPublicationStatus' => 'published', 'wooPublicationUrl' => '']));
	}//end testNoLinkWithoutAPublicationUrl()

	/**
	 * The case schema keeps the two fields the portal reads (task 2.0): an undeclared key is dropped on save.
	 *
	 * @return void
	 */
	public function testTheCaseDeclaresTheTermNoteAndTheResultLink(): void {
		$validator = new RealSchemaValidator();

		$this->assertSame([], $validator->errors(slug: 'case', payload: [
			'termNote' => 'De termijn staat stil sinds 6 oktober 2026. Hij loopt weer zodra wij uw antwoord hebben.',
			'resultLink' => ['label' => WooResultLink::LABEL, 'url' => 'https://zuiddrecht.nl/woo/publicaties/2026-0087'],
		], creating: false));
		$this->assertNotSame([], $validator->errors(slug: 'case', payload: ['termNote' => str_repeat('a', 201)], creating: false));
		$this->assertNotSame([], $validator->errors(slug: 'case', payload: ['resultLink' => ['url' => 'geen url']], creating: false));
	}//end testTheCaseDeclaresTheTermNoteAndTheResultLink()
}//end class
