<?php

/**
 * The Lindelaan Woo case of the Zuiddrecht example set is portaliq's (the
 * portal resident Sanne de Vries's request), so dossiq's fragment writes no
 * second one, and the Woo document type it keeps is the one portaliq's
 * documents look up.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/example-set-lindelaan-to-portaliq/spec.md#requirement-the-lindelaan-woo-case-is-written-once-by-portaliq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * No duplicate Lindelaan case; a findable Woo document type.
 *
 * @spec openspec/specs/example-set-lindelaan-to-portaliq/spec.md#requirement-the-lindelaan-woo-case-is-written-once-by-portaliq
 */
class ZuiddrechtLindelaanIsPortaliqsTest extends TestCase {

	/**
	 * Dossiq writes no Lindelaan Woo case and nothing that points at one.
	 *
	 * @return void
	 */
	public function testDossiqWritesNoLindelaanCase(): void {
		$text = (string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json');
		$set  = json_decode($text, true);

		$cases  = array_filter($set['objects'], static fn (array $object): bool => $object['schema'] === 'case');
		$titles = array_column(array_column($cases, 'data'), 'title');
		$this->assertNotContains(needle: 'Verlichting fietspad Lindelaan', haystack: $titles);
		$this->assertStringNotContainsString(needle: 'woo-lindelaan', haystack: $text);
		$this->assertDirectoryDoesNotExist(directory: __DIR__.'/../../../lib/Settings/example-sets/zuiddrecht/woo-lindelaan');
	}//end testDossiqWritesNoLindelaanCase()

	/**
	 * The Woo document type carries the description portaliq's documents look it up by, and only it does.
	 *
	 * @return void
	 */
	public function testTheWooDocumentTypeIsFindable(): void {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$types = array_values(array_filter($set['objects'], static fn (array $object): bool => $object['schema'] === 'informatieobjecttype'));

		$this->assertCount(expectedCount: 1, haystack: $types);
		$this->assertSame(expected: 'Document bij een Woo-verzoek', actual: $types[0]['data']['description']);
		$demo = (string)file_get_contents(__DIR__.'/../../../lib/Settings/demo.d/10-work-pages.json');
		$this->assertStringNotContainsString(needle: 'Document bij een Woo-verzoek', haystack: $demo);
	}//end testTheWooDocumentTypeIsFindable()
}//end class
