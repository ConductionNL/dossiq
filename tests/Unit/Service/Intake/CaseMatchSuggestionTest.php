<?php

/**
 * Unit tests for CaseMatchSuggestion.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
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

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\CaseMatchSuggestion;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A queued message is matched to the open case its subject quotes.
 */
class CaseMatchSuggestionTest extends TestCase {
	/**
	 * The fake object service's searches, so a test can count them.
	 *
	 * @var array<int, array>
	 */
	private array $queries = [];

	/**
	 * The suggester over a fake object service that knows two open cases.
	 *
	 * @param boolean $configured Whether the register and case schema are set.
	 * @param boolean $throws     Whether every search fails.
	 *
	 * @return CaseMatchSuggestion The service under test.
	 */
	private function suggester(bool $configured = true, bool $throws = false): CaseMatchSuggestion {
		$cases = [
			['id' => 'c-79', 'identifier' => '2026-0079', 'externalIdentifier' => '', 'title' => 'Kapvergunning Molenweg 4'],
			['@self' => ['id' => 'c-82'], 'identifier' => '2026-0082', 'externalIdentifier' => '2025-9001', 'title' => 'Verlichting fietspad'],
		];
		$queries = &$this->queries;
		$objectService = new class($cases, $throws, $queries) {
			/**
			 * @param array $cases   The open cases.
			 * @param bool  $throws  Whether every search fails.
			 * @param array $queries The searches made, by reference.
			 */
			public function __construct(private array $cases, private bool $throws, private array &$queries) {
			}

			/**
			 * Search on one field, as the numeric path of SearchesObjects calls it.
			 *
			 * @param array $query The query.
			 *
			 * @return array The matching cases.
			 */
			public function searchObjects(array $query): array {
				if ($this->throws === true) {
					throw new \RuntimeException('down');
				}

				$this->queries[] = $query;
				foreach (['identifier', 'externalIdentifier'] as $field) {
					if (isset($query[$field]) === true) {
						return array_values(array_filter($this->cases, static fn (array $case): bool => $case[$field] === $query[$field]));
					}
				}

				return [];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($configured === true) ? ['register' => '5', 'case_schema' => '7'][$key] ?? '' : ''
		);

		return new CaseMatchSuggestion(settingsService: $settings, logger: new NullLogger());
	}//end suggester()

	/**
	 * A quoted case number names its case; an external reference names the case that carries it.
	 *
	 * @return void
	 */
	public function testAQuotedReferenceNamesItsOpenCase(): void {
		$items = $this->suggester()->annotate(
			items: [
				['id' => 'e1', 'subject' => 'Re: uw zaak 2026-0079, aanvulling'],
				['id' => 'e2', 'subject' => 'Vraag over 2026-1234'],
				['id' => 'e3', 'subject' => 'Factuur 2026-118'],
				['id' => 'e4', 'subject' => 'Geen nummer'],
			]
		);

		$this->assertSame(
			['case' => 'c-79', 'identifier' => '2026-0079', 'title' => 'Kapvergunning Molenweg 4', 'matchedOn' => 'identifier'],
			$items[0]['suggestion']
		);
		$this->assertNull($items[1]['suggestion'], 'a number no open case carries suggests nothing.');
		$this->assertNull($items[2]['suggestion']);
		$this->assertNull($items[3]['suggestion']);
		$this->assertFalse($this->queries[0]['isFinalStatus'], 'only an open case is suggested.');
	}//end testAQuotedReferenceNamesItsOpenCase()

	/**
	 * The reference a case carries from elsewhere matches when the case number does not.
	 *
	 * @return void
	 */
	public function testTheExternalReferenceMatchesSecond(): void {
		$items = $this->suggester()->annotate(items: [['subject' => 'Uw kenmerk 2025-9001']]);

		$this->assertSame('c-82', $items[0]['suggestion']['case']);
		$this->assertSame('externalIdentifier', $items[0]['suggestion']['matchedOn']);
	}//end testTheExternalReferenceMatchesSecond()

	/**
	 * One reference quoted by several messages is looked up once.
	 *
	 * @return void
	 */
	public function testAReferenceIsLookedUpOncePerQueue(): void {
		$this->suggester()->annotate(items: [['subject' => '2026-0079'], ['subject' => 'nogmaals 2026-0079']]);

		$this->assertCount(1, $this->queries);
	}//end testAReferenceIsLookedUpOncePerQueue()

	/**
	 * The two chips of the mailbox split the queue on whether a case was suggested.
	 *
	 * @return void
	 */
	public function testNarrowSplitsOnTheSuggestion(): void {
		$items = [['id' => 'a', 'suggestion' => ['case' => 'c']], ['id' => 'b', 'suggestion' => null]];
		$suggester = $this->suggester();

		$this->assertSame([$items[0]], $suggester->narrow(items: $items, suggestion: 'IS NOT NULL'));
		$this->assertSame([$items[1]], $suggester->narrow(items: $items, suggestion: 'is null'));
		$this->assertSame($items, $suggester->narrow(items: $items, suggestion: ''));
	}//end testNarrowSplitsOnTheSuggestion()

	/**
	 * Without a configured register, or when OpenRegister fails, nothing is suggested and nothing breaks.
	 *
	 * @return void
	 */
	public function testNoRegisterOrAFailingSearchSuggestsNothing(): void {
		$this->assertNull($this->suggester(configured: false)->annotate(items: [['subject' => '2026-0079']])[0]['suggestion']);
		$this->assertNull($this->suggester(throws: true)->annotate(items: [['subject' => '2026-0079']])[0]['suggestion']);
	}//end testNoRegisterOrAFailingSearchSuggestsNothing()
}//end class
