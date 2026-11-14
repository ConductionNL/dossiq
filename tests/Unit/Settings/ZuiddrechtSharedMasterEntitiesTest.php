<?php

/**
 * Example data shared with pipelinq: persons and companies that are also pipelinq clients carry the same master entity.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Master entities pair dossiq parties with pipelinq clients.
 */
class ZuiddrechtSharedMasterEntitiesTest extends TestCase {

	/**
	 * The fragment with its parts' lists appended, as the loader reads it.
	 *
	 * @return array<string, mixed> The fragment.
	 */
	private function fragment(): array {
		$folder   = __DIR__.'/../../../lib/Settings/example-sets';
		$fragment = json_decode((string)file_get_contents($folder.'/zuiddrecht.json'), true);
		$parts    = glob($folder.'/zuiddrecht.d/*.json');
		if ($parts === false) {
			$parts = [];
		}

		sort($parts);
		foreach ($parts as $file) {
			foreach (json_decode((string)file_get_contents($file), true) as $key => $value) {
				if (is_array($value) === true && array_is_list($value) === true) {
					$fragment[$key] = array_merge(($fragment[$key] ?? []), $value);
				}
			}
		}

		return $fragment;
	}//end fragment()

	/**
	 * Henk Visser and Van Dam carry the master entities pipelinq's clients carry, and no master entity names two objects.
	 *
	 * @return void
	 */
	public function testPartiesShareTheirPipelinqMasterEntity(): void {
		$refs = [];
		foreach ($this->fragment()['objects'] as $object) {
			if (isset($object['data']['masterEntityRef']) === true) {
				$refs[$object['key']] = $object['data']['masterEntityRef'];
			}
		}

		$this->assertSame(expected: '0f5e0000-0000-4000-8000-000000000014', actual: $refs['persoon-henkvisser']);
		$this->assertSame(expected: '0f5e0000-0000-4000-8000-000000000003', actual: $refs['bedrijf-vandam']);
		$this->assertSame(expected: count($refs), actual: count(array_unique($refs)));
	}//end testPartiesShareTheirPipelinqMasterEntity()
}//end class
