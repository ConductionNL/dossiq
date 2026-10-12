<?php

/**
 * BrcDocumentLinkRules Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\BrcDocumentLinkRules;
use PHPUnit\Framework\TestCase;

/**
 * The create rules of a besluitinformatieobject, each lookup against its own schema.
 *
 * @covers \OCA\Dossiq\Service\Zgw\BrcDocumentLinkRules
 * @covers \OCA\Dossiq\Service\Zgw\ReadsLinkedObjects
 * @uses \OCA\Dossiq\Service\ZgwRulesBase
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class BrcDocumentLinkRulesTest extends TestCase {
	use MakesZrcRuleGroup;

	private const BESLUIT = '11111111-1111-4111-8111-111111111111';

	private const TYPE = '22222222-2222-4222-8222-222222222222';

	private const DOCUMENT = '33333333-3333-4333-8333-333333333333';

	private const DOCTYPE = '44444444-4444-4444-8444-444444444444';

	private const TEXT = 'Legt vast, omgekeerd: wordt vastgelegd door';

	/**
	 * A besluit, its besluittype, a document and its documenttype, each under its own schema.
	 *
	 * @param mixed $allowed What the besluittype stores as allowed document types.
	 *
	 * @return array<string, array>
	 */
	private function world(mixed $allowed): array {
		return [
			self::BESLUIT . '@schema:decision_schema' => ['decisionType' => 'https://ztc.example.com/besluittypen/' . self::TYPE],
			self::TYPE . '@schema:decision_type_schema' => ['documentTypes' => $allowed],
			self::DOCUMENT . '@schema:document_schema' => ['documentType' => 'https://ztc.example.com/iot/' . self::DOCTYPE],
			self::DOCTYPE . '@schema:document_type_schema' => ['name' => 'Brief'],
		];
	}//end world()

	/**
	 * Link the document to the besluit.
	 *
	 * @param array|null $objects The objects the rules can read.
	 * @param string $document The informatieobject URL.
	 *
	 * @return array The validation result.
	 */
	private function link(?array $objects, string $document = 'https://drc.example.com/eio/' . self::DOCUMENT): array {
		return $this->ruleGroup(class: BrcDocumentLinkRules::class, objects: $objects)->rulesBesluitinformatieobjectenCreate(
			body: ['decision' => 'https://brc.example.com/besluiten/' . self::BESLUIT, 'informatieobject' => $document]
		);
	}//end link()

	/**
	 * Brc-008 passes on the type's name or uuid, from a list or from JSON, and brc-004 adds the text.
	 *
	 * @return void
	 */
	public function testAnAllowedDocumentTypePassesAndGetsTheRelationText(): void {
		foreach ([['Brief'], [self::DOCTYPE], '["Brief"]', json_encode([self::DOCTYPE])] as $allowed) {
			$result = $this->link(objects: $this->world(allowed: $allowed));

			$this->assertTrue($result['valid']);
			$this->assertSame(self::TEXT, $result['enrichedBody']['natureRelationshipDisplay']);
		}
	}//end testAnAllowedDocumentTypePassesAndGetsTheRelationText()

	/**
	 * Brc-008 refuses a type that is not listed, an empty list, and a list that is not one.
	 *
	 * @return void
	 */
	public function testADocumentTypeTheBesluittypeDoesNotAllowIsRefused(): void {
		$detail = 'Het informatieobjecttype van het informatieobject is niet gespecificeerd in het besluittype.informatieobjecttypen.';

		foreach ([['Notitie'], [], 'geen json', 7] as $allowed) {
			$this->assertSame(
				[
					'valid' => false,
					'status' => 400,
					'detail' => $detail,
					'invalidParams' => [['name' => 'nonFieldErrors', 'code' => 'missing-informatieobjecttype', 'reason' => $detail]],
					'enrichedBody' => [],
				],
				$this->link(objects: $this->world(allowed: $allowed))
			);
		}
	}//end testADocumentTypeTheBesluittypeDoesNotAllowIsRefused()

	/**
	 * Brc-003 refuses a URL that is none, or names no document, before anything is looked up.
	 *
	 * @return void
	 */
	public function testAnInformatieobjectUrlThatNamesNoDocumentIsRefused(): void {
		foreach (['geen url', 'https://drc.example.com/eio/geen-uuid'] as $document) {
			$result = $this->link(objects: $this->world(allowed: ['Brief']), document: $document);

			$this->assertFalse($result['valid']);
			$this->assertSame('De informatieobject URL is ongeldig.', $result['detail']);
			$this->assertSame('bad-url', $result['invalidParams'][0]['code']);
		}

		$this->assertSame(0, $this->finds);
	}//end testAnInformatieobjectUrlThatNamesNoDocumentIsRefused()

	/**
	 * A besluit, besluittype, document or documenttype that cannot be read skips brc-008.
	 *
	 * @return void
	 */
	public function testAnythingThatCannotBeResolvedSkipsTheTypeCheck(): void {
		foreach (array_keys($this->world(allowed: ['Notitie'])) as $missing) {
			$objects = $this->world(allowed: ['Notitie']);
			unset($objects[$missing]);

			$this->assertTrue($this->link(objects: $objects)['valid'], $missing);
		}

		$this->assertFalse($this->link(objects: $this->world(allowed: ['Notitie']))['valid']);
	}//end testAnythingThatCannotBeResolvedSkipsTheTypeCheck()

	/**
	 * Without a context nothing is checked and the body only gets its relation text.
	 *
	 * @return void
	 */
	public function testWithoutContextOnlyTheRelationTextIsAdded(): void {
		$result = $this->link(objects: null, document: 'geen url');

		$this->assertTrue($result['valid']);
		$this->assertSame(self::TEXT, $result['enrichedBody']['natureRelationshipDisplay']);
	}//end testWithoutContextOnlyTheRelationTextIsAdded()
}//end class
