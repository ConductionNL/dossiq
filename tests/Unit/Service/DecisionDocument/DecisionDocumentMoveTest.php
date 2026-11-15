<?php

/**
 * Unit test for DecisionDocumentMove.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\DecisionDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\DecisionDocument;

use DateTimeImmutable;
use OCA\Dossiq\Service\DecisionDocument\DecisionDocumentDefinition;
use OCA\Dossiq\Service\DecisionDocument\DecisionDocumentMove;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\DecisionDocument\DecisionDocumentMove
 */
class DecisionDocumentMoveTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$definition = new DecisionDocumentDefinition(kind: 'notice', block: ['remedyRequired' => false]);
		$document = ['caseId' => 'case-1'];
		$moment = new DateTimeImmutable('2026-11-15T10:00:00+00:00');

		$value = new DecisionDocumentMove(definition: $definition, document: $document, actor: 'alice', moment: $moment);

		$this->assertSame(expected: $definition, actual: $value->definition);
		$this->assertSame(expected: $document, actual: $value->document);
		$this->assertSame(expected: 'alice', actual: $value->actor);
		$this->assertSame(expected: $moment, actual: $value->moment);
	}//end testEveryPropertyReadsBack()
}//end class
