<?php

/**
 * ZrcCaseFieldRules acceptance edges
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

use OCA\Dossiq\Service\Zgw\ZrcCaseFieldRules;
use PHPUnit\Framework\TestCase;

/**
 * Each field rule that is given a value and accepts it lets the sequence go on.
 *
 * Every case pairs the accepted field with an archiefstatus that lacks its
 * archiefnominatie, the last rule in the sequence: the refusal that comes back
 * names archiefnominatie, which proves the earlier rule answered "acceptable"
 * rather than refusing or stopping the sequence.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZrcCaseFieldRules
 * @uses \OCA\Dossiq\Service\Zgw\ZrcCaseRelationRules
 * @uses \OCA\Dossiq\Service\ZgwRulesBase
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleLookups
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 * @uses \OCA\Dossiq\Service\FieldValidator
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class ZrcCaseFieldRulesEdgesTest extends TestCase {
	use MakesZrcRuleGroup;

	private const RESOURCE = 'https://example.com/zaken/11111111-1111-4111-8111-111111111111';

	/**
	 * A rules result around a body, as the entry rules hand it over.
	 *
	 * @param array $body The enriched body.
	 *
	 * @return array
	 */
	private function passing(array $body): array {
		return ['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => $body];
	}//end passing()

	/**
	 * The bodies whose one field rule accepts what it is given.
	 *
	 * @return array<string, array{0: array, 1: array|null}>
	 */
	public static function acceptedFields(): array {
		return [
			'zrc-002 unchanged identificatie' => [['identificatie' => 'Z-1'], ['identifier' => 'Z-1']],
			'zrc-002 identificatie on a zaak without one' => [['identificatie' => 'Z-1'], ['title' => 'x']],
			'zrc-010 communicatiekanaal of a resource' => [['communicatiekanaal' => self::RESOURCE], null],
			'zrc-011 relevanteAndereZaken of resources' => [['relevanteAndereZaken' => [['url' => self::RESOURCE], ['aardRelatie' => 'vervolg']]], null],
			'zrc-012 complete opschorting' => [['suspension' => ['indicatie' => false, 'reason' => 'wacht']], null],
			'zrc-012 complete verlenging' => [['verlenging' => ['reason' => 'later', 'duur' => 'P5D']], null],
		];
	}//end acceptedFields()

	/**
	 * An accepted field lets the sequence reach zrc-022, which then refuses.
	 *
	 * @param array      $field    The field the rule accepts.
	 * @param array|null $existing The stored zaak, null on create.
	 *
	 * @dataProvider acceptedFields
	 *
	 * @return void
	 */
	public function testAnAcceptedFieldLetsTheLaterRulesRun(array $field, ?array $existing): void {
		$refused = $this->ruleGroup(ZrcCaseFieldRules::class, null)->validateCaseFields(
			$this->passing($field + ['archiefstatus' => 'gearchiveerd']),
			$existing
		);

		$this->assertSame(
			[
				'valid' => false,
				'status' => 400,
				'detail' => 'archiefnominatie is vereist als archiefstatus niet "nog_te_archiveren" is.',
				'invalidParams' => [['name' => 'archiefnominatie', 'code' => 'archiefnominatie-not-set', 'reason' => 'Vereist.']],
				'enrichedBody' => [],
			],
			$refused
		);
	}//end testAnAcceptedFieldLetsTheLaterRulesRun()

	/**
	 * Zrc-022 names archiefactiedatum when only that one is missing, and accepts both present.
	 *
	 * @return void
	 */
	public function testAnArchiefstatusWithBothFieldsPassesWithTheBodyUnchanged(): void {
		$rules = $this->ruleGroup(ZrcCaseFieldRules::class, null);

		$missingDate = $rules->validateCaseFields($this->passing(['archiefstatus' => 'gearchiveerd', 'archiefnominatie' => 'vernietigen']), null);
		$this->assertSame('archiefactiedatum', $missingDate['invalidParams'][0]['name']);
		$this->assertSame('archiefactiedatum-not-set', $missingDate['invalidParams'][0]['code']);

		$body = [
			'identificatie' => 'Z-1',
			'communicatiekanaal' => self::RESOURCE,
			'relevanteAndereZaken' => [['url' => self::RESOURCE]],
			'suspension' => ['indicatie' => true, 'reason' => 'wacht'],
			'verlenging' => ['reason' => 'later', 'duur' => 'P5D'],
			'archiefstatus' => 'gearchiveerd',
			'archiefnominatie' => 'vernietigen',
			'archiefactiedatum' => '2030-01-01',
		];
		$result = $this->passing($body);

		$this->assertSame($result, $rules->validateCaseFields($result, ['identificatie' => 'Z-1']));
	}//end testAnArchiefstatusWithBothFieldsPassesWithTheBodyUnchanged()
}//end class
