<?php

/**
 * ZrcCaseTypeDefaults Unit Tests
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

use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use OCA\Dossiq\Service\Zgw\ZrcCaseTypeDefaults;
use PHPUnit\Framework\TestCase;

/**
 * What a zaak takes from its zaaktype, on the class that now owns it.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZrcCaseTypeDefaults
 * @uses \OCA\Dossiq\Service\ZgwRulesBase
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class ZrcCaseTypeDefaultsTest extends TestCase {
	use MakesZrcRuleGroup;

	private const UUID = 'aabbccdd-1111-2222-3333-444455556666';

	private const URL = 'http://example.com/zaaktypen/' . self::UUID;

	/**
	 * The declared handling block beats the legacy field.
	 *
	 * @return void
	 */
	public function testTheDeclaredHandlerBeatsTheLegacyField(): void {
		$rules = $this->ruleGroup(
			ZrcCaseTypeDefaults::class,
			[self::UUID => [CaseTypeHandling::PROPERTY => ['defaultHandler' => 'team-a'], 'defaultAssignee' => 'team-b']]
		);

		$this->assertSame(['assignee' => 'team-a'], $rules->applyDefaultAssignee([], self::URL));
	}//end testTheDeclaredHandlerBeatsTheLegacyField()

	/**
	 * A zaaktype that comes back as an object is read through jsonSerialize().
	 *
	 * @return void
	 */
	public function testAnEntityIsReadThroughJsonSerialize(): void {
		$entity = new class() {
			public function jsonSerialize(): array {
				return ['defaultAssignee' => 'team-b', 'confidentiality' => 'intern'];
			}
		};
		$rules = $this->ruleGroup(ZrcCaseTypeDefaults::class, [self::UUID => $entity]);

		$this->assertSame(['assignee' => 'team-b'], $rules->applyDefaultAssignee([], self::URL));
		$this->assertSame(['vertrouwelijkheidaanduiding' => 'intern'], $rules->deriveVertrouwelijkheidaanduiding([], self::URL));
	}//end testAnEntityIsReadThroughJsonSerialize()

	/**
	 * Every way of having nothing to stamp leaves the body as it was, without a lookup where none is needed.
	 *
	 * @return void
	 */
	public function testNothingToStampLeavesTheBodyAlone(): void {
		$stored = [self::UUID => ['defaultAssignee' => 'team-b']];

		$this->assertSame(['assignee' => 'jan'], $this->ruleGroup(ZrcCaseTypeDefaults::class, $stored)->applyDefaultAssignee(['assignee' => 'jan'], self::URL));
		$this->assertSame([], $this->ruleGroup(ZrcCaseTypeDefaults::class, $stored)->applyDefaultAssignee([], ''));
		$this->assertSame([], $this->ruleGroup(ZrcCaseTypeDefaults::class, $stored)->applyDefaultAssignee([], 'zaaktype-7'));
		$this->assertSame([], $this->ruleGroup(ZrcCaseTypeDefaults::class, $stored, [])->applyDefaultAssignee([], self::URL));
		$this->assertSame(0, $this->finds);

		$this->assertSame([], $this->ruleGroup(ZrcCaseTypeDefaults::class, null)->applyDefaultAssignee([], self::URL));
		$this->assertSame([], $this->ruleGroup(ZrcCaseTypeDefaults::class, [self::UUID => new \RuntimeException('gone')])->applyDefaultAssignee([], self::URL));
		$this->assertSame([], $this->ruleGroup(ZrcCaseTypeDefaults::class, [self::UUID => ['defaultAssignee' => '']])->applyDefaultAssignee([], self::URL));
	}//end testNothingToStampLeavesTheBodyAlone()

	/**
	 * Zrc-009: the zaaktype decides when it has a value; otherwise the incoming one stays.
	 *
	 * @return void
	 */
	public function testTheZaaktypeDecidesConfidentialityWhenItHasOne(): void {
		$incoming = ['vertrouwelijkheidaanduiding' => 'openbaar'];

		$decides = $this->ruleGroup(ZrcCaseTypeDefaults::class, [self::UUID => ['confidentiality' => 'geheim']]);
		$this->assertSame(['vertrouwelijkheidaanduiding' => 'geheim'], $decides->deriveVertrouwelijkheidaanduiding($incoming, self::URL));

		$silent = $this->ruleGroup(ZrcCaseTypeDefaults::class, [self::UUID => ['confidentiality' => '']]);
		$this->assertSame($incoming, $silent->deriveVertrouwelijkheidaanduiding($incoming, self::URL));

		$unknown = $this->ruleGroup(ZrcCaseTypeDefaults::class, []);
		$this->assertSame($incoming, $unknown->deriveVertrouwelijkheidaanduiding($incoming, self::URL));
		$this->assertSame($incoming, $unknown->deriveVertrouwelijkheidaanduiding($incoming, 'zaaktype-7'));
	}//end testTheZaaktypeDecidesConfidentialityWhenItHasOne()
}//end class
