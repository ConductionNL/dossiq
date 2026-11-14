<?php

/**
 * Portal pages test
 *
 * One page per listable collection, opening with the create action that
 * writes its schema, and the case page that also mounts portaliq's case
 * screen so a resident can withdraw a case from the page it opens on.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-every-dossiq-portal-page-names-its-menu-group
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalPages;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Portal\PortalPages
 */
class PortalPagesTest extends TestCase {

	/**
	 * A listable collection gets its create action, list and detail; an unlistable one no page.
	 *
	 * @return void
	 */
	public function testEveryListableCollectionGetsAPageInTheGroup(): void {
		$pages = (new PortalPages())->forCollections(
			collections: [
				['id' => 'zaken', 'schema' => 'case', 'label' => 'Zaken'],
				['id' => 'hidden', 'schema' => 'case', 'listable' => false],
				['id' => 'notes', 'schema' => 'note'],
			],
			actions: [
				['id' => 'updateCase', 'type' => 'update', 'schema' => 'case'],
				['id' => 'createCase', 'type' => 'create', 'schema' => 'case'],
				['id' => 'createCaseAgain', 'type' => 'create', 'schema' => 'case'],
			],
			group: 'Leverancier',
			labels: ['notes' => 'Notities']
		);

		$this->assertSame(
			[
				[
					'id' => 'zaken',
					'label' => 'Zaken',
					'group' => 'Leverancier',
					'blocks' => [
						['type' => 'action', 'action' => 'createCase'],
						['type' => 'collection', 'collection' => 'zaken'],
						['type' => 'detail', 'collection' => 'zaken'],
					],
				],
				[
					'id' => 'notes',
					'label' => 'Notities',
					'group' => 'Leverancier',
					'blocks' => [
						['type' => 'collection', 'collection' => 'notes'],
						['type' => 'detail', 'collection' => 'notes'],
					],
				],
			],
			$pages
		);
	}//end testEveryListableCollectionGetsAPageInTheGroup()

	/**
	 * A collection without a label is named by its id.
	 *
	 * @return void
	 */
	public function testACollectionWithoutALabelIsNamedByItsId(): void {
		$pages = (new PortalPages())->forCollections([['id' => 'notes']], [], 'G');

		$this->assertSame('notes', $pages[0]['label']);
	}//end testACollectionWithoutALabelIsNamedByItsId()

	/**
	 * Only the case page gets the case screen, after its own blocks.
	 *
	 * @return void
	 */
	public function testOnlyTheCasePageMountsTheCaseScreen(): void {
		$pages = (new PortalPages())->withCaseScreen(
			[
				['id' => 'overzicht', 'blocks' => [['type' => 'tasks']]],
				['id' => 'mijnZaken', 'blocks' => [['type' => 'detail', 'collection' => 'mijnZaken']]],
			]
		);

		$this->assertSame([['type' => 'tasks']], $pages[0]['blocks']);
		$this->assertSame(
			[
				['type' => 'detail', 'collection' => 'mijnZaken'],
				['type' => 'citizenCase', 'collection' => 'mijnZaken'],
			],
			$pages[1]['blocks']
		);
	}//end testOnlyTheCasePageMountsTheCaseScreen()
}//end class
