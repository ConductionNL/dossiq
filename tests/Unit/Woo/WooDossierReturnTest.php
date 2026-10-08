<?php

/**
 * A published decision comes back to the dossier the request was started from, once.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Woo
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-decision-comes-back-to-the-dossier-it-was-asked-from-req-wpi-008
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooDecisionNotice;
use OCA\Dossiq\Woo\WooDossierReturn;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Runs the return against an in-memory register holding a C1-shaped dossier.
 *
 * @covers \OCA\Dossiq\Woo\WooDossierReturn
 */
class WooDossierReturnTest extends TestCase {

	private const COLLECTION = '0c0c0c0c-0000-4000-a000-000000000001';

	/**
	 * The store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The service under test.
	 *
	 * @var WooDossierReturn
	 */
	private WooDossierReturn $return;

	/**
	 * Seed one dossier with one item.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryRegister();
		$this->store->seed(
			schema: 'collection',
			uuid: self::COLLECTION,
			row: [
				'title' => 'Parkeren',
				'owner' => 'subject-ref-anna',
				'items' => [
					['id' => 'item-1', 'publication' => 'pub-a', 'attachment' => null, 'note' => 'Het besluit', 'addedAt' => '2026-09-01T10:00:00+00:00', 'addedBy' => 'resident'],
				],
				'share' => ['token' => 'tok', 'createdAt' => '2026-09-02T10:00:00+00:00'],
				'sourceOf' => ['dossiq:case:case-1'],
			]
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getWooPublicationConfigValue')->willReturnCallback(
			fn (string $key): string => SettingsService::WOO_PUBLICATION_DEFAULTS[$key] ?? ''
		);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('0123456789abcdef0123456789abcdef');

		$this->return = new WooDossierReturn(settingsService: $settings, random: $random, logger: $this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * The case of a request started from the dossier.
	 *
	 * @return array<string, mixed>
	 */
	private function case(): array {
		return ['id' => 'case-1', 'wooRequest' => ['collectionId' => self::COLLECTION]];
	}//end case()

	/**
	 * The publication is appended as a dossiq item; nothing else changes.
	 *
	 * @return void
	 */
	public function testThePublicationIsAddedByDossiq(): void {
		$before = $this->store->row(schema: 'collection', uuid: self::COLLECTION);

		self::assertTrue($this->return->append(case: $this->case(), publicationId: 'pub-new', title: 'Besluit op uw Woo-verzoek'));

		$after = $this->store->row(schema: 'collection', uuid: self::COLLECTION);
		self::assertCount(2, $after['items']);
		self::assertSame($before['items'][0], $after['items'][0]);
		$item = $after['items'][1];
		self::assertSame('01234567-89ab-4def-8123-456789abcdef', $item['id']);
		self::assertSame('pub-new', $item['publication']);
		self::assertArrayNotHasKey('attachment', $item);
		self::assertSame('Besluit op uw Woo-verzoek', $item['note']);
		self::assertSame('dossiq', $item['addedBy']);
		self::assertNotSame('', $item['addedAt']);
		foreach (['title', 'owner', 'share', 'sourceOf'] as $field) {
			self::assertSame($before[$field], $after[$field], $field);
		}
	}//end testThePublicationIsAddedByDossiq()

	/**
	 * The appended item fits opencatalogi's real `collection.items[]` fragment
	 * (register.d/citizen-collections.json): `id` is a uuid, every property is
	 * a string, and a whole-publication item leaves `attachment` out rather
	 * than writing null. OpenRegister refused the null on :8080, so the
	 * decision never came back to the dossier (Woo journey e2e J5, 1 Oct 2026).
	 *
	 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-decision-comes-back-to-the-dossier-it-was-asked-from-req-wpi-008
	 *
	 * @return void
	 */
	public function testTheItemFitsTheCollectionItemSchema(): void {
		$this->return->append(case: $this->case(), publicationId: 'pub-new', title: 'Besluit op uw Woo-verzoek');

		$item = $this->store->row(schema: 'collection', uuid: self::COLLECTION)['items'][1];
		foreach (['id', 'publication', 'addedAt', 'addedBy'] as $required) {
			self::assertArrayHasKey($required, $item);
		}

		foreach ($item as $key => $value) {
			self::assertIsString($value, 'items[].' . $key . ' is typed string in the collection schema');
		}

		self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $item['id']);
	}//end testTheItemFitsTheCollectionItemSchema()

	/**
	 * Republishing does not add the item twice.
	 *
	 * @return void
	 */
	public function testRepublishingAddsNothing(): void {
		$this->return->append(case: $this->case(), publicationId: 'pub-new', title: 'x');
		$writes = $this->store->writes;

		self::assertTrue($this->return->append(case: $this->case(), publicationId: 'pub-new', title: 'x'));

		self::assertSame($writes, $this->store->writes);
		self::assertCount(2, $this->store->row(schema: 'collection', uuid: self::COLLECTION)['items']);
	}//end testRepublishingAddsNothing()

	/**
	 * A request without a dossier writes no collection.
	 *
	 * @return void
	 */
	public function testARequestWithoutADossierWritesNothing(): void {
		self::assertFalse($this->return->append(case: ['id' => 'case-2'], publicationId: 'pub-new', title: 'x'));
		self::assertSame(0, $this->store->writes);
	}//end testARequestWithoutADossierWritesNothing()

	/**
	 * A dossier that is gone is logged and answered false, never thrown.
	 *
	 * @return void
	 */
	public function testAGoneDossierIsNotAFailure(): void {
		unset($this->store->rows['collection'][self::COLLECTION]);
		self::assertFalse($this->return->append(case: $this->case(), publicationId: 'pub-new', title: 'x'));
	}//end testAGoneDossierIsNotAFailure()
	/**
	 * The first publish tells the resident; a republish, or a return without a notice, tells nobody.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-pages-in-resident-groups/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
	 */
	public function testOnlyTheFirstPublishTellsTheResident(): void {
		$notice = $this->createMock(WooDecisionNotice::class);
		$notice->expects(self::once())->method('tell')->with($this->case(), 'case-1', 'pub-new')->willReturn(true);
		$settings = $this->createMock(SettingsService::class);
		$return = new WooDossierReturn(
			settingsService: $settings,
			random: $this->createMock(ISecureRandom::class),
			logger: $this->createMock(LoggerInterface::class),
			decisionNotice: $notice,
		);

		self::assertTrue($return->tellTheResident(case: $this->case(), caseId: 'case-1', publicationId: 'pub-new'));
		self::assertFalse($return->tellTheResident(case: $this->case() + ['wooPublicationUrl' => 'https://gemeente.test/p'], caseId: 'case-1', publicationId: 'pub-new'));
		self::assertFalse($this->return->tellTheResident(case: $this->case(), caseId: 'case-1', publicationId: 'pub-new'));
	}//end testOnlyTheFirstPublishTellsTheResident()
}//end class
