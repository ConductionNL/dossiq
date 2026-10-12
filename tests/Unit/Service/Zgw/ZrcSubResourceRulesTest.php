<?php

/**
 * ZrcSubResourceRules Unit Tests
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

use OCA\Dossiq\Service\Zgw\ZrcSubResourceRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The four sub-resource create rules, each against its own type schema.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZrcSubResourceRules
 * @uses \OCA\Dossiq\Service\ZgwRulesBase
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class ZrcSubResourceRulesTest extends TestCase {
	use MakesZrcRuleGroup;

	private const ZAAK = '11111111-1111-4111-8111-111111111111';

	private const TYPE = '22222222-2222-4222-8222-222222222222';

	private const ZAAKTYPE = '33333333-3333-4333-8333-333333333333';

	private const OTHER = '44444444-4444-4444-8444-444444444444';

	/**
	 * Rule method, body field and the config key of the schema its type is read from.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function rules(): array {
		return [
			'status' => ['rulesStatussenCreate', 'statustype', 'status_type_schema'],
			'resultaat' => ['rulesResultatenCreate', 'resultaattype', 'result_type_schema'],
			'rol' => ['rulesRollenCreate', 'roltype', 'role_type_schema'],
			'zaakeigenschap' => ['rulesZaakeigenschappenCreate', 'eigenschap', 'property_definition_schema'],
		];
	}//end rules()

	/**
	 * A type is read from its own schema: the right type in another schema does not count.
	 *
	 * @param string $rule The rule method.
	 * @param string $field The body field naming the type.
	 * @param string $schemaKey The config key of the type's schema.
	 *
	 * @return void
	 */
	#[DataProvider('rules')]
	public function testTheTypeIsReadFromItsOwnSchema(string $rule, string $field, string $schemaKey): void {
		$body = ['case' => 'http://example.com/zaken/' . self::ZAAK, $field => 'http://example.com/types/' . self::TYPE];
		$zaak = [self::ZAAK . '@schema:case_schema' => ['caseType' => 'http://example.com/zaaktypen/' . self::ZAAKTYPE]];

		$own = $this->ruleGroup(ZrcSubResourceRules::class, $zaak + [self::TYPE . '@schema:' . $schemaKey => ['caseType' => self::ZAAKTYPE]]);
		$this->assertSame(['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => $body], $own->{$rule}($body));

		$elsewhere = $this->ruleGroup(ZrcSubResourceRules::class, $zaak + [self::TYPE . '@schema:other' => ['caseType' => self::ZAAKTYPE]]);
		$refused = $elsewhere->{$rule}($body);
		$this->assertFalse($refused['valid']);
		$this->assertSame(400, $refused['status']);
		$this->assertSame("Het {$field} hoort niet bij het zaaktype van de zaak.", $refused['detail']);

		$mismatch = $this->ruleGroup(ZrcSubResourceRules::class, $zaak + [self::TYPE . '@schema:' . $schemaKey => ['caseType' => self::OTHER]]);
		$this->assertFalse($mismatch->{$rule}($body)['valid']);
	}//end testTheTypeIsReadFromItsOwnSchema()

	/**
	 * Without both a zaak and a type there is nothing to compare, and nothing is looked up.
	 *
	 * @param string $rule The rule method.
	 * @param string $field The body field naming the type.
	 *
	 * @return void
	 */
	#[DataProvider('rules')]
	public function testAnIncompleteBodyIsNotChecked(string $rule, string $field): void {
		$rules = $this->ruleGroup(ZrcSubResourceRules::class, []);

		$this->assertTrue($rules->{$rule}([$field => 'http://example.com/types/' . self::TYPE])['valid']);
		$this->assertTrue($rules->{$rule}(['case' => 'http://example.com/zaken/' . self::ZAAK])['valid']);
		$this->assertTrue($rules->{$rule}([])['valid']);
		$this->assertSame(0, $this->finds);

		$noContext = $this->ruleGroup(ZrcSubResourceRules::class, null);
		$this->assertTrue($noContext->{$rule}(['case' => 'http://example.com/zaken/' . self::ZAAK, $field => 'http://example.com/types/' . self::TYPE])['valid']);
	}//end testAnIncompleteBodyIsNotChecked()
}//end class
