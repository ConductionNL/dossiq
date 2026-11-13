<?php

/**
 * Unit tests for the filter outcome vocabulary.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email\Filters
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email\Filters;

use OCA\Dossiq\Service\Email\Filters\FilterOutcome;
use PHPUnit\Framework\TestCase;

/**
 * Which outcomes end the pipeline.
 *
 * @covers \OCA\Dossiq\Service\Email\Filters\FilterOutcome
 */
class FilterOutcomeTest extends TestCase {

	/**
	 * Every outcome but pass is decisive.
	 *
	 * @return void
	 */
	public function testEveryOutcomeButPassIsDecisive(): void {
		self::assertTrue(FilterOutcome::isDecisive(outcome: FilterOutcome::ACCEPT));
		self::assertTrue(FilterOutcome::isDecisive(outcome: FilterOutcome::REJECT));
		self::assertTrue(FilterOutcome::isDecisive(outcome: FilterOutcome::QUARANTINE));
		self::assertTrue(FilterOutcome::isDecisive(outcome: FilterOutcome::FORWARD));
		self::assertFalse(FilterOutcome::isDecisive(outcome: FilterOutcome::PASS));
	}//end testEveryOutcomeButPassIsDecisive()

	/**
	 * A value outside the vocabulary is never decisive.
	 *
	 * @return void
	 */
	public function testAnUnknownOutcomeIsNotDecisive(): void {
		self::assertFalse(FilterOutcome::isDecisive(outcome: 'drop'));
		self::assertFalse(FilterOutcome::isDecisive(outcome: ''));
		self::assertFalse(FilterOutcome::isDecisive(outcome: 'REJECT'));
	}//end testAnUnknownOutcomeIsNotDecisive()

	/**
	 * The default is accept, and the vocabulary lists exactly five outcomes.
	 *
	 * @return void
	 */
	public function testTheDefaultIsAcceptAndTheVocabularyIsClosed(): void {
		self::assertSame('accept', FilterOutcome::DEFAULT_OUTCOME);
		self::assertSame(['accept', 'reject', 'quarantine', 'forward', 'pass'], FilterOutcome::ALL);
	}//end testTheDefaultIsAcceptAndTheVocabularyIsClosed()
}//end class
