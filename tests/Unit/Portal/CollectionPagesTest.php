<?php

/**
 * CollectionPages tests: one page per listable collection, with the create
 * action for its schema, the list and the selected row.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\CollectionPages;
use OCA\Dossiq\Portal\PortalPages;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CollectionPages.
 *
 * @covers \OCA\Dossiq\Portal\CollectionPages
 * @uses   \OCA\Dossiq\Portal\PortalPages
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */
class CollectionPagesTest extends TestCase {

	/**
	 * A listable collection gets its schema's first create action, the list and the detail; an unlisted one none.
	 *
	 * @return void
	 */
	public function testEachListableCollectionIsAPageWithItsCreateListAndDetail(): void {
		$collections = [
			['id' => 'tenders', 'label' => 'Aanbestedingen', 'schema' => 'tender'],
			['id' => 'hidden', 'label' => 'Verborgen', 'schema' => 'tender', 'listable' => false],
			['id' => 'contracts', 'schema' => 'contract'],
		];
		$actions = [
			['id' => 'editTender', 'type' => 'update', 'schema' => 'tender'],
			['id' => 'createTender', 'type' => 'create', 'schema' => 'tender'],
			['id' => 'createTenderAgain', 'type' => 'create', 'schema' => 'tender'],
		];

		$pages = (new CollectionPages())->forCollections(
			collections: $collections,
			actions: $actions,
			group: 'Leveranciers',
			labels: ['contracts' => 'Mijn contracten']
		);

		$this->assertSame(
			expected: [
				[
					'id' => 'tenders',
					'label' => 'Aanbestedingen',
					'group' => 'Leveranciers',
					'blocks' => [
						['type' => 'action', 'action' => 'createTender'],
						['type' => 'collection', 'collection' => 'tenders'],
						['type' => 'detail', 'collection' => 'tenders'],
					],
				],
				[
					'id' => 'contracts',
					'label' => 'Mijn contracten',
					'group' => 'Leveranciers',
					'blocks' => [
						['type' => 'collection', 'collection' => 'contracts'],
						['type' => 'detail', 'collection' => 'contracts'],
					],
				],
			],
			actual: $pages
		);
	}//end testEachListableCollectionIsAPageWithItsCreateListAndDetail()

	/**
	 * A collection without a label is named by its id, and PortalPages answers the same pages.
	 *
	 * @return void
	 */
	public function testPortalPagesAnswersTheSamePages(): void {
		$collections = [['id' => 'bids', 'schema' => 'bid']];

		$pages = (new CollectionPages())->forCollections(collections: $collections, actions: [], group: 'G');

		$this->assertSame(expected: 'bids', actual: $pages[0]['label']);
		$this->assertSame(
			expected: $pages,
			actual: (new PortalPages())->forCollections(collections: $collections, actions: [], group: 'G')
		);
	}//end testPortalPagesAnswersTheSamePages()
}//end class
