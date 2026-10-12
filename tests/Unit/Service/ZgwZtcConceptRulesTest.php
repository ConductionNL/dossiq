<?php

/**
 * Characterisation tests for the concept rules of ZgwZtcRulesService.
 *
 * Pins ztc-009 and ztc-010 (what a published type and its sub-resources refuse) and the two
 * concept defaults, through the public methods, so their move to an own class can be proven to
 * change no outcome.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwZtcRulesService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Characterisation tests for the ZTC concept rules.
 *
 * @covers \OCA\Dossiq\Service\ZgwZtcRulesService
 * @covers \OCA\Dossiq\Service\ZgwRulesBase
 *
 * @uses \OCA\Dossiq\Service\FieldValidator
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class ZgwZtcConceptRulesTest extends TestCase {
	/**
	 * The rules, which need no context for any concept rule.
	 *
	 * @return ZgwZtcRulesService
	 */
	private function rules(): ZgwZtcRulesService {
		return new ZgwZtcRulesService(
			logger: $this->createMock(LoggerInterface::class),
			settingsService: $this->createMock(SettingsService::class),
			fieldValidator: new FieldValidator()
		);
	}//end rules()

	/**
	 * The refusal the concept rules build.
	 *
	 * @param string $detail The detail, repeated as the reason.
	 * @param string $code The code.
	 *
	 * @return array
	 */
	private function refusal(string $detail, string $code): array {
		return [
			'valid' => false,
			'status' => 400,
			'detail' => $detail,
			'invalidParams' => [['name' => 'nonFieldErrors', 'code' => $code, 'reason' => $detail]],
			'enrichedBody' => [],
		];
	}//end refusal()

	/**
	 * A new zaaktype, besluittype or informatieobjecttype is a concept unless the body says otherwise.
	 *
	 * @return void
	 */
	public function testANewTypeDefaultsToConcept(): void {
		$rules = $this->rules();

		foreach (ZgwZtcRulesService::CONCEPT_RESOURCES as $resource) {
			$this->assertSame(['a' => 1, 'concept' => true], $rules->defaultConcept(body: ['a' => 1], resource: $resource));
			$this->assertSame(['concept' => false], $rules->defaultConcept(body: ['concept' => false], resource: $resource));
			$this->assertSame(['concept' => null], $rules->defaultConcept(body: ['concept' => null], resource: $resource));
		}

		$this->assertSame(['a' => 1], $rules->defaultConcept(body: ['a' => 1], resource: 'statustypen'));
	}//end testANewTypeDefaultsToConcept()

	/**
	 * A PUT or PATCH keeps the stored concept, whatever the body sends and however it is stored.
	 *
	 * @return void
	 */
	public function testAnUpdateKeepsTheStoredConcept(): void {
		$rules = $this->rules();
		$keep  = static fn (array $existing): mixed => $rules->preserveConcept(
			body: ['concept' => 'sent', 'a' => 1],
			resource: 'zaaktypen',
			existingObject: $existing
		)['concept'];

		foreach ([true, 'true', '1', 1] as $stored) {
			$this->assertTrue($keep(['isDraft' => $stored]));
		}

		foreach ([false, 'false', '0', 0] as $stored) {
			$this->assertFalse($keep(['isDraft' => $stored]));
		}

		$this->assertFalse($keep(['concept' => false]));
		$this->assertTrue($keep(['isDraft' => true, 'concept' => false]));
		$this->assertTrue($keep([]));
		$this->assertSame('ja', $keep(['isDraft' => 'ja']));
		$this->assertSame(
			['a' => 1, 'concept' => false],
			$rules->preserveConcept(body: ['a' => 1], resource: 'besluittypen', existingObject: ['isDraft' => false])
		);
	}//end testAnUpdateKeepsTheStoredConcept()

	/**
	 * Without a stored object, or for a resource that has no concept, the body is left alone.
	 *
	 * @return void
	 */
	public function testConceptIsOnlyKeptForAStoredConceptResource(): void {
		$rules = $this->rules();

		$this->assertSame(['concept' => 'sent'], $rules->preserveConcept(body: ['concept' => 'sent'], resource: 'zaaktypen', existingObject: null));
		$this->assertSame(['a' => 1], $rules->preserveConcept(body: ['a' => 1], resource: 'roltypen', existingObject: ['isDraft' => false]));
	}//end testConceptIsOnlyKeptForAStoredConceptResource()

	/**
	 * Ztc-009: a published type refuses every change, worded by resource and action.
	 *
	 * @return void
	 */
	public function testAPublishedTypeRefusesChanges(): void {
		$rules = $this->rules();
		$labels = [
			'update' => 'bij te werken',
			'patch' => 'deels bij te werken',
			'destroy' => 'te verwijderen',
			'create' => 'aan te passen',
			'publish' => 'aan te passen',
		];
		$names = ['zaaktypen' => 'zaaktype', 'besluittypen' => 'besluittype', 'informatieobjecttypen' => 'informatieobjecttype'];

		foreach ($names as $resource => $name) {
			foreach ($labels as $action => $label) {
				$this->assertSame(
					$this->refusal(detail: "Het is niet toegestaan om een {$name} met concept=false {$label}.", code: 'non-concept-object'),
					$rules->checkConceptProtection(
						resource: $resource,
						action: $action,
						body: ['omschrijving' => 'x'],
						existingObject: ['isDraft' => false],
						parentCaseTypeDraft: null
					),
					$resource . ' ' . $action
				);
			}
		}

		foreach (['false', '0', 0] as $stored) {
			$this->assertNotNull($rules->checkConceptProtection('zaaktypen', 'update', [], ['isDraft' => $stored], null));
		}

		$this->assertNotNull($rules->checkConceptProtection('zaaktypen', 'update', [], ['concept' => false], null));
	}//end testAPublishedTypeRefusesChanges()

	/**
	 * Ztc-009: a concept, a type that is not stored yet, and a type without a concept field pass.
	 *
	 * A draft parent zaaktype does not matter for a type that has its own concept.
	 *
	 * @return void
	 */
	public function testAConceptTypeAcceptsChanges(): void {
		$rules = $this->rules();

		foreach ([null, [], ['isDraft' => true], ['isDraft' => 'true'], ['isDraft' => null, 'concept' => true], ['isDraft' => true, 'concept' => false]] as $existing) {
			$this->assertNull($rules->checkConceptProtection('zaaktypen', 'update', ['a' => 1], $existing, false));
		}
	}//end testAConceptTypeAcceptsChanges()

	/**
	 * Ztc-009c/g/k: a PATCH that ends the validity, and sends nothing else of substance, is allowed.
	 *
	 * @return void
	 */
	public function testAPublishedTypeMayBeGivenAnEndDate(): void {
		$rules = $this->rules();
		$patch = static fn (array $body): ?array => $rules->checkConceptProtection('zaaktypen', 'patch', $body, ['isDraft' => false], null);
		$meta  = ['_route' => 'r', 'zgwApi' => 'catalogi', 'resource' => 'zaaktypen', 'uuid' => 'u', 'concept' => false];

		$this->assertNull($patch(['endValidity' => '2027-01-01']));
		$this->assertNull($patch(['endValidity' => null]));
		$this->assertNull($patch(($meta + ['endValidity' => '2027-01-01', 'startValidity' => '2026-01-01', 'beginObject' => '2026-01-01'])));
		$this->assertNotNull($patch(['startValidity' => '2026-01-01']));
		$this->assertNotNull($patch($meta));
		$this->assertNotNull($patch(['endValidity' => '2027-01-01', 'omschrijving' => 'x']));
		$this->assertNotNull($rules->checkConceptProtection('zaaktypen', 'update', ['endValidity' => '2027-01-01'], ['isDraft' => false], null));
	}//end testAPublishedTypeMayBeGivenAnEndDate()

	/**
	 * Ztc-010: under a published zaaktype only a new resultaattype is refused on create, and every
	 * sub-resource refuses update, patch and destroy.
	 *
	 * @return void
	 */
	public function testSubResourcesOfAPublishedZaaktype(): void {
		$rules   = $this->rules();
		$refusal = $this->refusal(
			detail: 'Het is niet toegestaan om typen van een gepubliceerd zaaktype aan te passen.',
			code: 'non-concept-zaaktype'
		);

		foreach (ZgwZtcRulesService::ZAAKTYPE_SUB_RESOURCES as $resource) {
			foreach (['update', 'patch', 'destroy'] as $action) {
				$this->assertSame($refusal, $rules->checkConceptProtection($resource, $action, [], ['isDraft' => true], false), $resource . ' ' . $action);
			}

			$expected = null;
			if ($resource === 'resultaattypen') {
				$expected = $refusal;
			}

			$this->assertSame($expected, $rules->checkConceptProtection($resource, 'create', [], null, false), $resource);
			$this->assertNull($rules->checkConceptProtection($resource, 'publish', [], null, false), $resource);
			$this->assertNull($rules->checkConceptProtection($resource, 'update', [], null, true), $resource);
			$this->assertNull($rules->checkConceptProtection($resource, 'update', [], null, null), $resource);
		}

		$this->assertNull($rules->checkConceptProtection('catalogussen', 'update', [], ['isDraft' => false], false));
	}//end testSubResourcesOfAPublishedZaaktype()
}//end class
