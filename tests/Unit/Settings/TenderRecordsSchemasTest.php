<?php

/**
 * Tender Records Schemas Test
 *
 * The records a tender is worked with: bids, supplier questions, evaluations
 * and scoring formulas, as the merged register the importer reads declares
 * them. OpenRegister drops an undeclared property in silence, so every field
 * the specs name is asserted, and the states follow declared lifecycles.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/specs/dossiq-procurement-tender-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Scoring\FormulaScorer;
use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Scoring\FormulaScorer
 */
class TenderRecordsSchemasTest extends TestCase {

	private const TENDER = 'b84067ac-35ec-55b5-bf17-975206ce32f2';

	private const SUPPLIER = '21dd1f91-6680-52a4-8cb5-d415b3095060';

	/**
	 * Every field the specs name, per schema, and the required ones (REQ-TND-004, REQ-TND-007, REQ-EVA-001, REQ-EVA-003).
	 */
	private const FIELDS = [
		'bid' => [
			'all' => ['tender', 'lot', 'case', 'supplier', 'submittedAt', 'submissionRef', 'priceAmount', 'responses', 'documents', 'state', 'admissibilityNotes', 'evaluationScore'],
			'required' => ['tender', 'case', 'supplier', 'submittedAt', 'responses', 'state'],
		],
		'tenderQuestion' => [
			'all' => ['tender', 'lot', 'submittedBy', 'submittedAt', 'question', 'answer', 'publishedAt', 'noi', 'state'],
			'required' => ['tender', 'submittedBy', 'submittedAt', 'question', 'state'],
		],
		'evaluation' => [
			'all' => ['bid', 'tender', 'evaluator', 'criterionScores', 'weightedTotal', 'state', 'calibrationNotes', 'attachments'],
			'required' => ['bid', 'tender', 'evaluator', 'criterionScores', 'state'],
		],
		'scoringFormula' => [
			'all' => ['key', 'title', 'expression', 'variables', 'description', 'active'],
			'required' => ['key', 'title', 'expression'],
		],
	];

	/**
	 * The states each lifecycle has, as the specs list them.
	 */
	private const STATES = [
		'bid' => ['received', 'admissible', 'inadmissible', 'excluded', 'evaluated', 'withdrawn'],
		'tenderQuestion' => ['received', 'under-review', 'answered', 'published', 'rejected'],
		'evaluation' => ['draft', 'submitted', 'calibrated', 'finalised', 'withdrawn'],
	];

	private RealSchemaValidator $real;

	protected function setUp(): void {
		$this->real = new RealSchemaValidator();
	}//end setUp()

	/**
	 * Each schema declares every field its spec names and requires the required ones, and has a config key.
	 *
	 * @return void
	 */
	public function testEveryNamedFieldIsDeclared(): void {
		foreach (self::FIELDS as $slug => $fields) {
			$this->assertArrayHasKey($slug, $this->real->schemas, $slug . ' is in the merged register');
			$schema = $this->real->schemas[$slug];
			foreach ($fields['all'] as $field) {
				$this->assertArrayHasKey($field, $schema['properties'], $slug . '.' . $field . ' is declared');
			}

			$required = $schema['required'];
			sort($required);
			$expected = $fields['required'];
			sort($expected);
			$this->assertSame($expected, $required, $slug . ' requires what the spec requires');
			$this->assertArrayHasKey($slug, SchemaSlugMap::SLUG_TO_CONFIG_KEY, $slug . ' has a config key');
		}
	}//end testEveryNamedFieldIsDeclared()

	/**
	 * Each state is an enum value and every transition of the lifecycle moves between them; a bid
	 * can go from received straight to inadmissible, the way a late bid is refused (REQ-TND-007).
	 *
	 * @return void
	 */
	public function testTheStatesFollowADeclaredLifecycle(): void {
		foreach (self::STATES as $slug => $states) {
			$schema = $this->real->schemas[$slug];
			$this->assertSame($states, $schema['properties']['state']['enum']);
			$lifecycle = $schema['configuration']['x-openregister-lifecycle'];
			$this->assertSame('state', $lifecycle['field']);
			$this->assertSame($states[0], $lifecycle['initial']);
			foreach ($lifecycle['transitions'] as $name => $transition) {
				$this->assertContains($transition['to'], $states, $slug . '.' . $name);
				foreach ($transition['from'] as $from) {
					$this->assertContains($from, $states, $slug . '.' . $name);
					$this->assertNotContains($from, $lifecycle['final'], $slug . '.' . $name . ' leaves no final state');
				}
			}
		}

		$late = $this->real->schemas['bid']['configuration']['x-openregister-lifecycle']['transitions']['declareInadmissible'];
		$this->assertSame([['received'], 'inadmissible'], [$late['from'], $late['to']]);
	}//end testTheStatesFollowADeclaredLifecycle()

	/**
	 * A bid, a question and an evaluation as a buyer records them fit their schema; an unknown state does not.
	 *
	 * @return void
	 */
	public function testRecordsAsTheBuyerWritesThemFitTheirSchema(): void {
		$bid = ['tender' => self::TENDER, 'case' => self::TENDER, 'supplier' => self::SUPPLIER, 'submittedAt' => '2026-11-02T10:00:00+01:00', 'priceAmount' => 1148000, 'responses' => ['kwaliteit' => 'Plan van aanpak'], 'state' => 'received'];
		$question = ['tender' => self::TENDER, 'submittedBy' => 'Inschrijver 3', 'submittedAt' => '2026-10-20T09:00:00+02:00', 'question' => 'Mag een onderaannemer worden ingezet?', 'state' => 'received'];
		$evaluation = ['bid' => '996a7906-08ee-5eea-a70b-5a9d7d630eb1', 'tender' => self::TENDER, 'evaluator' => 'p.devries', 'criterionScores' => ['kwaliteit' => ['score' => 8, 'reasoning' => 'Concreet plan']], 'state' => 'draft'];

		$this->assertSame([], $this->real->errors('bid', $bid));
		$this->assertSame([], $this->real->errors('tenderQuestion', $question));
		$this->assertSame([], $this->real->errors('evaluation', $evaluation));
		$this->assertNotSame([], $this->real->errors('bid', array_replace($bid, ['state' => 'gegund'])));
		$this->assertNotSame([], $this->real->errors('bid', array_diff_key($bid, ['supplier' => true])));
	}//end testRecordsAsTheBuyerWritesThemFitTheirSchema()

	/**
	 * The award is a decision on the case, so the register holds no award schema (REQ-EVA-001).
	 *
	 * @return void
	 */
	public function testThereIsNoAwardSchema(): void {
		foreach (array_keys($this->real->schemas) as $slug) {
			$this->assertNotContains(strtolower((string)$slug), ['award', 'gunning', 'gunningsbesluit']);
		}
	}//end testThereIsNoAwardSchema()

	/**
	 * The three seeded formulas fit their schema and score as their names say (REQ-EVA-003): the
	 * lowest price gets full points, a bid 10% dearer gets less, and price-quality adds the weights.
	 *
	 * @return void
	 */
	public function testTheSeededFormulasScore(): void {
		$formulas = [];
		foreach ($this->real->objects as $object) {
			if (($object['@self']['schema'] ?? '') === 'scoringFormula') {
				$record = array_diff_key($object, ['@self' => true]);
				$this->assertSame([], $this->real->errors('scoringFormula', $record));
				$formulas[$record['key']] = $record;
			}
		}

		ksort($formulas);
		$this->assertSame(['lowest-price', 'price-quality', 'relative-price'], array_keys($formulas));
		$scorer = new FormulaScorer();
		$this->assertSame(40.0, $scorer->score($formulas['lowest-price'], ['maxPoints' => 40, 'lowestPrice' => 1000, 'price' => 1000]));
		$this->assertEqualsWithDelta(36.3636, $scorer->score($formulas['lowest-price'], ['maxPoints' => 40, 'lowestPrice' => 1000, 'price' => 1100]), 0.0001);
		$this->assertEqualsWithDelta(36.0, $scorer->score($formulas['relative-price'], ['maxPoints' => 40, 'lowestPrice' => 1000, 'price' => 1100]), 0.0001);
		$this->assertSame(0.0, $scorer->score($formulas['relative-price'], ['maxPoints' => 40, 'lowestPrice' => 1000, 'price' => 2500]));
		$this->assertSame(84.36, $scorer->score($formulas['price-quality'], ['qualityWeight' => 0.6, 'quality' => 80, 'priceWeight' => 0.4, 'maxPoints' => 100, 'lowestPrice' => 1000, 'price' => 1100]));
		foreach ($formulas as $formula) {
			sort($formula['variables']);
			preg_match_all('/[A-Za-z][A-Za-z0-9]*/', $formula['expression'], $names);
			$used = array_values(array_diff(array_unique($names[0]), ['max', 'min', 'round', 'abs']));
			sort($used);
			$this->assertSame($used, $formula['variables'], $formula['key'] . ' names the values it uses');
		}
	}//end testTheSeededFormulasScore()
}//end class

