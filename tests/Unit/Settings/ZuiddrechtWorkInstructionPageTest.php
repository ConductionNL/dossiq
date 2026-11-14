<?php

/**
 * The Woo case type's work instruction is a Collectives page the example set
 * writes, so the Werkinstructie card opens a page that exists.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 * @spec openspec/specs/case-knowledge-base/spec.md
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The work-instruction page of the Woo case type.
 */
class ZuiddrechtWorkInstructionPageTest extends TestCase {

	/**
	 * Every page a case type names is a page the set writes, in a collective the team's group can read.
	 *
	 * @return void
	 */
	public function testTheNamedWorkInstructionIsAPageTheSetWrites(): void {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$pages = [];
		foreach ($set['collectivesPages'] ?? [] as $row) {
			$pages[$row['data']['collective'].'/'.$row['data']['title']] = $row['data'];
		}

		$named = [];
		foreach ($set['changes'] as $change) {
			if (isset($change['set']['knowledgeBasePage']) === true) {
				$named[] = $change['set']['knowledgeBasePage'];
			}
		}

		$this->assertNotSame([], $named);
		foreach ($named as $path) {
			$this->assertArrayHasKey($path, $pages, $path);
			$this->assertSame(['{{cast:woo-en-informatie.gid}}'], $pages[$path]['shareWith']);
			$this->assertStringNotContainsString('—', $pages[$path]['content']);
			$this->assertStringStartsWith('# ', $pages[$path]['content']);
		}
	}//end testTheNamedWorkInstructionIsAPageTheSetWrites()
}//end class
