<?php

/**
 * PublicationDateRule tests: a public notice is published on the decision
 * date, or on the first Wednesday from it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use DateTimeImmutable;
use OCA\Dossiq\Service\Publication\PublicationDateRule;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PublicationDateRule.
 *
 * @spec openspec/changes/admin-tabs-carry-the-settings-board-blocks/specs/vth-admin-settings/spec.md
 */
class PublicationDateRuleTest extends TestCase {

	/**
	 * The first Wednesday from a Thursday is the next week's; a Wednesday is itself;
	 * any other rule is the decision date.
	 *
	 * @return void
	 */
	public function testThePublicationDate(): void {
		$rule = new PublicationDateRule();
		$thursday = new DateTimeImmutable('2026-11-05 10:00');
		$wednesday = new DateTimeImmutable('2026-11-11 10:00');

		$this->assertSame(expected: '2026-11-11', actual: $rule->publishOn(rule: 'first-wednesday', decided: $thursday)->format('Y-m-d'));
		$this->assertSame(expected: '2026-11-11', actual: $rule->publishOn(rule: 'first-wednesday', decided: $wednesday)->format('Y-m-d'));
		$this->assertSame(expected: '2026-11-05', actual: $rule->publishOn(rule: 'decision-date', decided: $thursday)->format('Y-m-d'));
		$this->assertSame(expected: '2026-11-05', actual: $rule->publishOn(rule: '', decided: $thursday)->format('Y-m-d'));
	}//end testThePublicationDate()
}//end class
