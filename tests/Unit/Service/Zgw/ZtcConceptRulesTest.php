<?php

/**
 * Unit tests for ZtcConceptRules.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zgw\ZtcConceptRules;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Concept defaults and the protection of published types, asked of the class itself.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZtcConceptRules
 * @uses \OCA\Dossiq\Service\ZgwRulesBase
 * @uses \OCA\Dossiq\Service\FieldValidator
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class ZtcConceptRulesTest extends TestCase {
	/**
	 * The rules, which read nothing from the store and so need no context.
	 *
	 * @return ZtcConceptRules
	 */
	private function rules(): ZtcConceptRules {
		return new ZtcConceptRules(
			logger: $this->createMock(LoggerInterface::class),
			settingsService: $this->createMock(SettingsService::class),
			fieldValidator: new FieldValidator()
		);
	}//end rules()

	/**
	 * Only the three types that can be published have a concept.
	 *
	 * @return void
	 */
	public function testWhichResourcesHaveAConcept(): void {
		$this->assertSame(['zaaktypen', 'besluittypen', 'informatieobjecttypen'], ZtcConceptRules::CONCEPT_RESOURCES);
		$this->assertSame(
			['statustypen', 'resultaattypen', 'roltypen', 'eigenschappen', 'zaaktype-informatieobjecttypen'],
			ZtcConceptRules::ZAAKTYPE_SUB_RESOURCES
		);
	}//end testWhichResourcesHaveAConcept()

	/**
	 * A new type is a concept unless the body says so; a sub-resource gets no concept.
	 *
	 * @return void
	 */
	public function testDefaultConcept(): void {
		$rules = $this->rules();

		$this->assertSame(['concept' => true], $rules->defaultConcept(body: [], resource: 'besluittypen'));
		$this->assertSame(['concept' => false], $rules->defaultConcept(body: ['concept' => false], resource: 'besluittypen'));
		$this->assertSame([], $rules->defaultConcept(body: [], resource: 'roltypen'));
	}//end testDefaultConcept()

	/**
	 * An update keeps the stored concept, read from isDraft first and from any of its spellings.
	 *
	 * @return void
	 */
	public function testPreserveConcept(): void {
		$rules = $this->rules();
		$keep  = static fn (?array $existing, string $resource = 'zaaktypen'): array => $rules->preserveConcept(
			body: ['concept' => 'sent'],
			resource: $resource,
			existingObject: $existing
		);

		$this->assertSame(['concept' => 'sent'], $keep(null));
		$this->assertSame(['concept' => 'sent'], $keep(['isDraft' => false], 'statustypen'));
		$this->assertSame(['concept' => true], $keep([]));
		$this->assertSame(['concept' => true], $keep(['isDraft' => '1']));
		$this->assertSame(['concept' => true], $keep(['isDraft' => 1]));
		$this->assertSame(['concept' => true], $keep(['isDraft' => 'true']));
		$this->assertSame(['concept' => false], $keep(['isDraft' => '0']));
		$this->assertSame(['concept' => false], $keep(['isDraft' => 0]));
		$this->assertSame(['concept' => false], $keep(['isDraft' => 'false']));
		$this->assertSame(['concept' => false], $keep(['concept' => false]));
		$this->assertSame(['concept' => 'ja'], $keep(['isDraft' => 'ja']));
	}//end testPreserveConcept()

	/**
	 * Ztc-009: a published type refuses a change and names the action; a concept does not.
	 *
	 * @return void
	 */
	public function testAPublishedTypeIsProtected(): void {
		$rules = $this->rules();
		$check = static fn (string $action, array $body, ?array $existing): ?array => $rules->checkConceptProtection(
			resource: 'informatieobjecttypen',
			action: $action,
			body: $body,
			existingObject: $existing,
			parentCaseTypeDraft: null
		);
		$detail = 'Het is niet toegestaan om een informatieobjecttype met concept=false te verwijderen.';

		$this->assertSame(
			[
				'valid' => false,
				'status' => 400,
				'detail' => $detail,
				'invalidParams' => [['name' => 'nonFieldErrors', 'code' => 'non-concept-object', 'reason' => $detail]],
				'enrichedBody' => [],
			],
			$check('destroy', [], ['isDraft' => false])
		);
		$this->assertStringEndsWith('bij te werken.', $check('update', [], ['isDraft' => '0'])['detail']);
		$this->assertStringEndsWith('deels bij te werken.', $check('patch', ['a' => 1], ['isDraft' => 0])['detail']);
		$this->assertStringEndsWith('aan te passen.', $check('create', [], ['concept' => 'false'])['detail']);
		$this->assertNull($check('update', [], null));
		$this->assertNull($check('update', [], ['isDraft' => true]));
		$this->assertNull($check('update', [], []));
	}//end testAPublishedTypeIsProtected()

	/**
	 * Ztc-009c/g/k: ending the validity of a published type is the one PATCH that passes.
	 *
	 * @return void
	 */
	public function testEndingTheValidityIsAllowed(): void {
		$rules = $this->rules();
		$patch = static fn (array $body): ?array => $rules->checkConceptProtection('zaaktypen', 'patch', $body, ['isDraft' => false], null);

		$this->assertNull($patch(['endValidity' => '2027-01-01', 'startValidity' => '2026-01-01', 'beginObject' => 'x', 'uuid' => 'u', 'concept' => false]));
		$this->assertNotNull($patch(['startValidity' => '2026-01-01']));
		$this->assertNotNull($patch(['endValidity' => '2027-01-01', 'name' => 'x']));
	}//end testEndingTheValidityIsAllowed()

	/**
	 * Ztc-010: under a published zaaktype only create passes, and not for a resultaattype.
	 *
	 * @return void
	 */
	public function testSubResourcesOfAPublishedZaaktypeAreProtected(): void {
		$rules = $this->rules();
		$check = static fn (string $resource, string $action, ?bool $draft): ?array => $rules->checkConceptProtection($resource, $action, [], null, $draft);

		$this->assertNull($check('statustypen', 'create', false));
		$this->assertSame('non-concept-zaaktype', $check('resultaattypen', 'create', false)['invalidParams'][0]['code']);
		$this->assertSame(
			'Het is niet toegestaan om typen van een gepubliceerd zaaktype aan te passen.',
			$check('roltypen', 'destroy', false)['detail']
		);
		$this->assertNotNull($check('eigenschappen', 'update', false));
		$this->assertNotNull($check('zaaktype-informatieobjecttypen', 'patch', false));
		$this->assertNull($check('statustypen', 'publish', false));
		$this->assertNull($check('statustypen', 'update', true));
		$this->assertNull($check('statustypen', 'update', null));
		$this->assertNull($check('catalogussen', 'update', false));
	}//end testSubResourcesOfAPublishedZaaktypeAreProtected()
}//end class
