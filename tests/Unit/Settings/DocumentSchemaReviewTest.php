<?php

/**
 * The document schema declares who owns a reviewer's decision on its files.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/woo-request-scoped-access/spec.md#requirement-the-redaction-decisions-on-a-woo-cases-documents-follow-the-same-rule-req-wsa-007
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCA\Dossiq\Tests\Unit\Fixtures\ShippedRegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * REQ-WSA-007: the declaration on the shipped document schema, and the key that carries it.
 *
 * @covers \OCA\Dossiq\Service\Settings\SchemaSlugMap
 */
class DocumentSchemaReviewTest extends TestCase {

	/**
	 * The merged document schema says the decider owns a decision, coordinators supervise, nothing is hidden.
	 *
	 * @return void
	 */
	public function testTheDocumentSchemaDeclaresDeciderOwnership(): void {
		$configuration = (array)(ShippedRegisterSchema::schema('document')['configuration'] ?? []);

		self::assertSame(
			['ownership' => 'decider', 'supervisors' => ['dossiq-coordinators'], 'hideOthers' => false],
			$configuration['x-openregister-review'] ?? null
		);
	}//end testTheDocumentSchemaDeclaresDeciderOwnership()

	/**
	 * Without the key in the reconciled list, OpenRegister drops the block on an existing instance in silence.
	 *
	 * @return void
	 */
	public function testTheReviewBlockIsReconciledOntoTheInstance(): void {
		self::assertContains('x-openregister-review', SchemaSlugMap::SCHEMA_ANNOTATION_KEYS);
	}//end testTheReviewBlockIsReconciledOntoTheInstance()

	/**
	 * The schema's hideOthers is the Woo case type's, so the two never disagree about what a reviewer sees.
	 *
	 * @return void
	 */
	public function testHideOthersMatchesTheWooCaseType(): void {
		$raw = file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/81-woo-verzoek.json');
		self::assertIsString($raw);
		$fragment = json_decode($raw, true);
		self::assertIsArray($fragment);

		$hideOthers = null;
		foreach ((array)($fragment['components']['objects'] ?? []) as $object) {
			if (is_array($object) === true && isset($object['reviewerAccess']) === true) {
				$hideOthers = ($object['reviewerAccess']['hideOthers'] ?? false);
			}
		}

		$declared = ShippedRegisterSchema::schema('document')['configuration']['x-openregister-review']['hideOthers'] ?? null;
		self::assertNotNull($hideOthers, 'The Woo case type must declare reviewerAccess');
		self::assertSame($hideOthers, $declared);
	}//end testHideOthersMatchesTheWooCaseType()
}//end class
