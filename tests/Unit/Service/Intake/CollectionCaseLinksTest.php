<?php

/**
 * A resident's own collection is read, linked to the case item by item, and records the case once.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Intake
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-dossier-records-the-request-it-started-req-wri-004
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\CollectionCaseLinks;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Intake\CollectionCaseLinks
 * @uses   \OCA\Dossiq\Service\Support\UnscopedObjectReader
 */
class CollectionCaseLinksTest extends TestCase {

	private const OWNER = 'subject-ref-anna';

	private const COLLECTION = '0c0c0c0c-0000-4000-a000-000000000001';

	/**
	 * The register the links read and write.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * The class under test.
	 *
	 * @var CollectionCaseLinks
	 */
	private CollectionCaseLinks $links;

	/**
	 * Seed two publications and one collection that holds them.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->register = new InMemoryRegister();
		$this->register->seed(schema: 'publication', uuid: 'pub-a', row: ['title' => 'Besluit']);
		$this->register->seed(schema: 'publication', uuid: 'pub-b', row: ['title' => '']);
		$this->register->seed(
			schema: 'collection',
			uuid: self::COLLECTION,
			row: [
				'owner' => self::OWNER,
				'items' => [
					['publication' => 'pub-a', 'attachment' => null, 'note' => 'Het besluit'],
					['publication' => 'pub-b', 'attachment' => 4711, 'note' => 'Een bijlage'],
					['publication' => '', 'note' => 'skipped'],
					'not an item',
				],
				'sourceOf' => [],
			]
		);

		/** @var SettingsService&MockObject $settings */
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => ['case_object_schema' => 'caseObject'][$key] ?? $default
		);
		$settings->method('getWooPublicationConfigValue')->willReturnCallback(
			fn (string $key): string => SettingsService::WOO_PUBLICATION_DEFAULTS[$key] ?? ''
		);

		/** @var IURLGenerator&MockObject $urls */
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'https://gemeente.test' . $path);

		$this->links = new CollectionCaseLinks(settingsService: $settings, urlGenerator: $urls, logger: $this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * The owner reads their collection with its id; anyone else reads nothing.
	 *
	 * @return void
	 */
	public function testOnlyTheOwnerReadsTheCollection(): void {
		$owned = $this->links->owned(objectService: $this->register, collectionId: self::COLLECTION, subjectRef: self::OWNER);

		self::assertNotNull($owned);
		self::assertSame(self::COLLECTION, $owned['id']);
		self::assertNull($this->links->owned(objectService: $this->register, collectionId: self::COLLECTION, subjectRef: 'someone-else'));
		self::assertNull($this->links->owned(objectService: $this->register, collectionId: 'missing', subjectRef: self::OWNER));
	}//end testOnlyTheOwnerReadsTheCollection()

	/**
	 * Each item that names a publication becomes one case object; the collection records the case once.
	 *
	 * @return void
	 */
	public function testTheCollectionIsLinkedToTheCaseOnce(): void {
		$owned = $this->links->owned(objectService: $this->register, collectionId: self::COLLECTION, subjectRef: self::OWNER);
		self::assertNotNull($owned);

		$this->links->link(objectService: $this->register, register: 'dossiq', caseId: 'case-1', collection: $owned);

		$objects = array_values($this->register->all(schema: 'caseObject'));
		self::assertCount(2, $objects);
		self::assertSame(CollectionCaseLinks::OBJECT_TYPE, $objects[0]['objectType']);
		self::assertSame('case-1', $objects[0]['case']);
		self::assertSame('https://gemeente.test/index.php/apps/opencatalogi/publication/pub-a', $objects[0]['objectUrl']);
		self::assertSame('Besluit: Het besluit', $objects[0]['description']);
		self::assertSame('{"publication":"pub-b","attachment":"4711"}', $objects[1]['objectIdentification']);
		self::assertSame('Een bijlage', $objects[1]['description']);

		$collection = $this->register->row(schema: 'collection', uuid: self::COLLECTION);
		self::assertSame([CollectionCaseLinks::SOURCE_PREFIX . 'case-1'], $collection['sourceOf']);

		$writes = $this->register->writes;
		$this->links->link(objectService: $this->register, register: 'dossiq', caseId: 'case-1', collection: $collection + ['id' => self::COLLECTION]);
		self::assertSame([CollectionCaseLinks::SOURCE_PREFIX . 'case-1'], $this->register->row(schema: 'collection', uuid: self::COLLECTION)['sourceOf']);
		self::assertSame($writes + 2, $this->register->writes);
	}//end testTheCollectionIsLinkedToTheCaseOnce()

	/**
	 * The publication path is the one the publish side links to.
	 *
	 * @return void
	 */
	public function testThePublicationPath(): void {
		self::assertSame('/index.php/apps/opencatalogi/publication/pub-a', $this->links->publicationPath(publicationId: 'pub-a'));
	}//end testThePublicationPath()
}//end class
