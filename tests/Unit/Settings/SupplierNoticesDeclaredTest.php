<?php

/**
 * The four supplier notices are declared on the supplier schemas.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Portal\PortalContributionProvider;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * portal-contribution T3: every notice the supplier portal announces is a
 * rule that mails the supplier's own contact address through `supplierRef`.
 *
 * @spec openspec/changes/portal-contribution/tasks.md#T3
 */
class SupplierNoticesDeclaredTest extends TestCase {

	/**
	 * Where each notice lives.
	 */
	private const RULES = [
		'newSupplierMessage' => 'supplierMessage',
		'contractExpiring' => 'supplierContract',
		'invoiceDue' => 'caseSupplierInvoice',
		'tenderPublished' => 'supplierTender',
	];

	/**
	 * The merged schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		$root = dirname(__DIR__, 3);
		$base = json_decode((string)file_get_contents($root . '/lib/Settings/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $root . '/lib/Settings/register.d');
		return $merged['components']['schemas'];
	}//end schemas()

	/**
	 * Each rule mails `supplierRef.contactEmail` over the email channel, in
	 * Dutch and English, and the hop it takes exists on both ends.
	 *
	 * @return void
	 */
	public function testEachNoticeMailsTheSupplierThroughItsReference(): void {
		$schemas = $this->schemas();
		$this->assertSame('email', ($schemas['supplier']['properties']['contactEmail']['format'] ?? null));

		foreach (self::RULES as $key => $slug) {
			$rule = ($schemas[$slug]['x-openregister-notifications'][$key] ?? null);
			$this->assertIsArray($rule, $slug . '.' . $key . ' is not declared');
			$this->assertSame(['email'], $rule['channels'], $key);
			$this->assertSame([['kind' => 'email', 'field' => 'supplierRef.contactEmail']], $rule['recipients'], $key);
			$this->assertSame('supplier', ($schemas[$slug]['properties']['supplierRef']['$ref'] ?? null), $key);
			foreach (['subject', 'message'] as $text) {
				$this->assertNotSame('', trim((string)($rule[$text]['nl'] ?? '')), $key . ' ' . $text . ' nl');
				$this->assertNotSame('', trim((string)($rule[$text]['en'] ?? '')), $key . ' ' . $text . ' en');
				$this->assertStringNotContainsString('—', $rule[$text]['nl'] . $rule[$text]['en'], $key);
			}
		}
	}//end testEachNoticeMailsTheSupplierThroughItsReference()

	/**
	 * Every placeholder a notice uses is a property of its own schema, so no
	 * mail goes out with an empty `{{...}}`.
	 *
	 * @return void
	 */
	public function testEveryPlaceholderIsAPropertyOfItsSchema(): void {
		$schemas = $this->schemas();
		foreach (self::RULES as $key => $slug) {
			$rule = $schemas[$slug]['x-openregister-notifications'][$key];
			$texts = implode(' ', array_merge(array_values($rule['subject']), array_values($rule['message'])));
			preg_match_all('/\{\{\s*([a-zA-Z_]+)\s*\}\}/', $texts, $m);
			foreach (array_unique($m[1]) as $field) {
				$this->assertArrayHasKey($field, $schemas[$slug]['properties'], $key . ' uses {{' . $field . '}}');
			}
		}
	}//end testEveryPlaceholderIsAPropertyOfItsSchema()

	/**
	 * The three notices the supplier audience announces are all declared.
	 *
	 * @return void
	 */
	public function testTheAnnouncedNoticesAreDeclared(): void {
		$provider = new PortalContributionProvider();
		$contribution = $provider->getContribution(['audience' => 'supplier', 'subjectRef' => 's1', 'organisation' => 'org-1', 'trust' => 'low']);
		foreach ((array)($contribution['notifications'] ?? []) as $key) {
			$this->assertArrayHasKey($key, self::RULES, $key . ' is announced but not declared');
		}
	}//end testTheAnnouncedNoticesAreDeclared()
}//end class
