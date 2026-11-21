<?php

/**
 * A tender case type says it runs the tender chain, and the case page places
 * the tender's lots, questions, bids, evaluation and award by that flag
 *
 * The flag is a case type property (decision 182), the aanbesteding package
 * sets it, and every widget the case page shows for it reads records of the
 * case on screen, never the records of another case.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Listener\SubmissionClosingDateGuard;
use PHPUnit\Framework\TestCase;

/**
 * @uses \OCA\Dossiq\Listener\SubmissionClosingDateGuard
 */
class AanbestedingTenderChainTest extends TestCase {

	/**
	 * The repository root.
	 */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * The condition every tender widget is shown on.
	 */
	private const GATE = ['field' => '@context.caseType.tenderChain', 'op' => 'eq', 'value' => true];

	/**
	 * The record each tender list reads, and the field that points it at this case.
	 */
	private const LISTS = [
		'case-tender-lots'      => ['case', 'parentCase'],
		'case-tender-questions' => ['tenderQuestion', 'tender'],
		'case-tender-ranking'   => ['evaluation', 'tender'],
	];

	/**
	 * One file of the repository, decoded.
	 *
	 * @param string $path The path under the repository root.
	 *
	 * @return array<string, mixed>
	 */
	private static function json(string $path): array {
		return json_decode((string)file_get_contents(self::ROOT . '/' . $path), true, 512, JSON_THROW_ON_ERROR);
	}//end json()

	/**
	 * The CaseDetail page of a manifest file.
	 *
	 * @param string $path The manifest file under src/.
	 *
	 * @return array<string, mixed>
	 */
	private static function caseDetail(string $path): array {
		foreach ((array)self::json(path: 'src/' . $path)['pages'] as $page) {
			if (($page['id'] ?? null) === 'CaseDetail') {
				return (array)$page;
			}
		}

		self::fail('no CaseDetail page in ' . $path);
	}//end caseDetail()

	/**
	 * The widgets the simple profile appends to the case page, by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function simpleWidgets(): array {
		return array_column((array)self::caseDetail(path: 'menu-layout.simple.json')['configAppend']['widgets'], null, 'id');
	}//end simpleWidgets()

	/**
	 * The case type schema carries the flag as a boolean that is off by default.
	 *
	 * @return void
	 */
	public function testTheCaseTypeDeclaresTheFlagOffByDefault(): void {
		$caseType = (array)self::json(path: 'lib/Settings/register.d/99-case-type-tender-chain.json')['components']['schemas']['caseType'];

		$this->assertSame(['tenderChain'], array_keys((array)$caseType['properties']));
		$this->assertSame('boolean', $caseType['properties']['tenderChain']['type']);
		$this->assertFalse($caseType['properties']['tenderChain']['default']);
		$this->assertSame('1.50.0', $caseType['version']);
	}//end testTheCaseTypeDeclaresTheFlagOffByDefault()

	/**
	 * The aanbesteding package turns the flag on and opts its Overview into the evaluation.
	 *
	 * @return void
	 */
	public function testThePackageRunsItsCaseTypeOnTheTenderChain(): void {
		$caseType = (array)self::json(path: 'lib/Settings/packages/aanbesteding/case-type.json')['caseType'];

		$this->assertTrue($caseType['tenderChain']);
		$this->assertSame(['case-tender-ranking', 'case-overview-sections'], $caseType['overviewCards']);
		$this->assertSame('1.11.0', self::json(path: 'lib/Settings/packages/aanbesteding/package.json')['version']);
	}//end testThePackageRunsItsCaseTypeOnTheTenderChain()

	/**
	 * Every tender list reads its records of the case on screen, behind the flag.
	 *
	 * @return void
	 */
	public function testEveryTenderListReadsTheRecordsOfThisCase(): void {
		$widgets = self::simpleWidgets();
		foreach (self::LISTS as $id => [$schema, $field]) {
			$this->assertArrayHasKey($id, $widgets, $id);
			$this->assertSame(self::GATE, $widgets[$id]['visibleWhen'], $id);
			$this->assertSame('object-list', $widgets[$id]['type'], $id);
			$this->assertSame($schema, $widgets[$id]['content']['schema'], $id);
			$this->assertSame([$field => '@objectId'], $widgets[$id]['content']['filter'], $id);
		}

		$bids = $widgets['case-tender-bids'];
		$this->assertSame('bid', $bids['content']['schema']);
		$this->assertSame(['tender' => '@objectId'], $bids['content']['filter']);
		$this->assertFalse($bids['content']['allowCreate'], 'a bid is registered through its action, which checks the closing date');
	}//end testEveryTenderListReadsTheRecordsOfThisCase()

	/**
	 * The evaluation ranks the bids by the formula's total, highest first (REQ-TND-CASE-01).
	 *
	 * @return void
	 */
	public function testTheEvaluationRanksByTheFormulasTotalHighestFirst(): void {
		$ranking = self::simpleWidgets()['case-tender-ranking'];

		$this->assertSame(['field' => 'weightedTotal', 'dir' => 'desc'], $ranking['content']['sort']);
		$this->assertContains('weightedTotal', array_column($ranking['content']['columns'], 'key'));
	}//end testTheEvaluationRanksByTheFormulasTotalHighestFirst()

	/**
	 * The award card reads the dates the flow's guards read.
	 *
	 * @return void
	 */
	public function testTheAwardCardReadsTheDatesTheFlowGuards(): void {
		$award = self::simpleWidgets()['case-tender-award'];
		$paths = array_column($award['content']['fields'], 'path');

		$this->assertSame(self::GATE, $award['visibleWhen']);
		$this->assertSame(['vragenTermijnEinde', 'sluiting', 'gunningsdatum', 'opschortendeTermijnEinde'], $paths);

		$names = array_column((array)self::json(path: 'lib/Settings/packages/aanbesteding/case-type.json')['propertyDefinitions'], 'name');
		foreach ($paths as $path) {
			$this->assertContains($path, $names, $path . ' is a date the case type declares');
		}
	}//end testTheAwardCardReadsTheDatesTheFlowGuards()

	/**
	 * The simple profile shows the lots, questions and bids as tabs behind the flag.
	 *
	 * @return void
	 */
	public function testTheSimpleProfileShowsTheTenderTabsBehindTheFlag(): void {
		$tabs = (array)self::caseDetail(path: 'menu-layout.simple.json')['configPatch']['widgets']['case-panels']['content']['tabs'];
		$byWidget = [];
		foreach ($tabs as $tab) {
			if (isset($tab['widgetId']) === true) {
				$byWidget[$tab['widgetId']] = $tab;
			}
		}

		foreach (['case-tender-lots', 'case-tender-questions', 'case-tender-bids'] as $id) {
			$this->assertArrayHasKey($id, $byWidget, $id);
			$this->assertSame(self::GATE, $byWidget[$id]['visibleWhen'], $id);
		}

		$overview = (array)$tabs[0]['widgetIds'];
		$this->assertContains('case-tender-ranking', $overview);
		$this->assertContains('case-tender-award', $overview);
	}//end testTheSimpleProfileShowsTheTenderTabsBehindTheFlag()

	/**
	 * The full profile shows the same five parts as one panel of the page grid.
	 *
	 * @return void
	 */
	public function testTheFullProfileShowsOnePanelBehindTheFlag(): void {
		$config = (array)self::caseDetail(path: 'manifest.json')['config'];
		$panel = array_column((array)$config['widgets'], null, 'id')['case-tender-panel'];

		$this->assertSame(self::GATE, $panel['visibleWhen']);
		$this->assertSame(
			['case-tender-lots', 'case-tender-questions', 'case-tender-bid-list', 'case-tender-ranking', 'case-tender-award'],
			array_map(static fn (array $section): string => (string)$section['widget']['id'], (array)$panel['content']['sections'])
		);
		$this->assertContains('case-tender-panel', array_column((array)$config['layout'], 'widgetId'));
	}//end testTheFullProfileShowsOnePanelBehindTheFlag()

	/**
	 * Registering a bid writes a received bid on this tender, behind the flag.
	 *
	 * @return void
	 */
	public function testRegisteringABidWritesAReceivedBidOnThisTender(): void {
		$actions = array_column((array)self::caseDetail(path: 'manifest.json')['config']['headerActions'], null, 'id');
		$action = $actions['bid-register'];

		$this->assertSame('open-form', $action['type']);
		$this->assertSame('bid', $action['schema']);
		$this->assertSame(['tender' => '@objectId', 'case' => '@objectId', 'state' => 'received', 'responses' => []], $action['props']);
		$this->assertSame(['all' => [self::GATE, ['field' => '@self.archived', 'op' => 'empty']]], $action['visibleWhen']);

		$bid = (array)self::json(path: 'lib/Settings/register.d/88-tender-records.json')['components']['schemas']['bid'];
		foreach (array_merge($action['includeFields'], array_keys($action['props'])) as $field) {
			$this->assertArrayHasKey($field, $bid['properties'], $field . ' is a field of the bid schema');
		}

		foreach ($bid['required'] as $required) {
			$this->assertContains($required, array_merge($action['includeFields'], array_keys($action['props'])), $required . ' is required on a bid');
		}
	}//end testRegisteringABidWritesAReceivedBidOnThisTender()

	/**
	 * The closing date guard reads fields the bid and the tender case type have.
	 *
	 * @return void
	 */
	public function testTheClosingDateGuardReadsFieldsTheBidAndTheTenderHave(): void {
		$fields = SubmissionClosingDateGuard::SUBMISSIONS['bid_schema'];
		$bid = (array)self::json(path: 'lib/Settings/register.d/88-tender-records.json')['components']['schemas']['bid'];
		$names = array_column((array)self::json(path: 'lib/Settings/packages/aanbesteding/case-type.json')['propertyDefinitions'], 'name');

		$this->assertArrayHasKey($fields['case'], $bid['properties']);
		$this->assertArrayHasKey($fields['receivedAt'], $bid['properties']);
		$this->assertContains($fields['closesOn'], $names, 'the closing day is a date the tender case type declares');
	}//end testTheClosingDateGuardReadsFieldsTheBidAndTheTenderHave()
}//end class
