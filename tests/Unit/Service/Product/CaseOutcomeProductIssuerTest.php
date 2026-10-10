<?php

/**
 * Tests for the generic case-outcome product issuer, configured as a parking permit.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Permit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Product;

use OCA\Dossiq\Service\Product\CaseOutcomeProductIssuer;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @spec openspec/changes/portal-permits-as-held-products/tasks.md#2.1
 */
class CaseOutcomeProductIssuerTest extends TestCase {

	private const CASE_ID = '5e7a1000-0000-4000-a000-00000000ac01';

	private const CHANGE_CASE_ID = '5e7a1000-0000-4000-a000-00000000ac02';

	private const TYPE_ID = '5e7a1000-0000-4000-a000-00000000aa01';

	private const CHANGE_TYPE_ID = '5e7a1000-0000-4000-a000-00000000aa02';

	private const DECISION_ID = '5e7a1000-0000-4000-a000-00000000de01';

	/**
	 * The object service double.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The issuer under test.
	 *
	 * @var CaseOutcomeProductIssuer
	 */
	private CaseOutcomeProductIssuer $issuer;

	/**
	 * Build the store and the issuer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/** @var array<string, array<string, array<string, mixed>>> Objects by schema and id. */
			public array $store = [];

			/** @var array<int, array<string, mixed>> Every save as schema and object. */
			public array $saved = [];

			/**
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array<string, mixed>
			 *
			 * @throws DoesNotExistException When there is none.
			 */
			public function find(string $id, string $register = '', string $schema = ''): array {
				if (isset($this->store[$schema][$id]) === false) {
					throw new DoesNotExistException('none');
				}

				return $this->store[$schema][$id];
			}

			/**
			 * @param string               $register      The register.
			 * @param string               $schema        The schema.
			 * @param array<string, mixed> $filters       The filters.
			 * @param bool                 $_rbac         Whether RBAC applies.
			 * @param bool                 $_multitenancy Whether tenancy applies.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters, bool $_rbac = true, bool $_multitenancy = true): array {
				return array_values(
					array_filter(
						($this->store[$schema] ?? []),
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (str_starts_with((string)$key, '_') === false && ($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The id.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = '', ?string $uuid = null): array {
				$id = ($uuid ?? ('new-' . count($this->saved)));
				$this->saved[] = ['schema' => $schema, 'id' => $uuid, 'object' => $object];
				$this->store[$schema][$id] = array_merge($object, ['id' => $id]);
				return $this->store[$schema][$id];
			}
		};

		$this->objects->store = [
			'case' => [
				self::CASE_ID => [
					'id' => self::CASE_ID,
					'caseType' => self::TYPE_ID,
					'portalSubject' => 'subj-sanne',
					'title' => 'Parkeervergunning Lindelaan 4',
					'properties' => [['name' => 'kenteken', 'value' => 'gz-482-k'], ['name' => 'adres', 'value' => 'Lindelaan 4']],
				],
				self::CHANGE_CASE_ID => [
					'id' => self::CHANGE_CASE_ID,
					'caseType' => self::CHANGE_TYPE_ID,
					'portalSubject' => 'subj-sanne',
					'properties' => [['name' => 'permit', 'value' => 'permit-1'], ['name' => 'nieuwKenteken', 'value' => 'HX901B']],
				],
			],
			'caseType' => [
				self::TYPE_ID => [
					'id' => self::TYPE_ID,
					'issuesPermit' => [
						'kind' => 'parkeren-bewoner',
						'theme' => 'parkeren',
						'titleTemplate' => 'Bewonersvergunning {{adres}}',
						'detailsFromCase' => ['kenteken' => 'kenteken', 'adres' => 'adres'],
						'compactFields' => ['kenteken'],
						'issueOn' => ['approved'],
					],
				],
				self::CHANGE_TYPE_ID => ['id' => self::CHANGE_TYPE_ID, 'issuesPermit' => ['changesProduct' => ['answer' => 'permit', 'set' => ['kenteken' => 'nieuwKenteken']], 'compactFields' => ['kenteken']]],
			],
			'permit' => [],
		];

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq'][$key] ?? $default)
		);
		$this->issuer = new CaseOutcomeProductIssuer(settingsService: $settings, logger: new NullLogger());
	}//end setUp()

	/**
	 * A positive outcome on a case whose type issues a permit writes the
	 * resident's permit, valid from the decision day, once.
	 *
	 * @return void
	 */
	public function testAnApprovedDecisionIssuesThePermitOnce(): void {
		$permit = $this->issuer->issueFor(caseId: self::CASE_ID, decisionId: self::DECISION_ID, status: 'approved', decidedAt: '2026-10-08T14:00:00+02:00');

		$this->assertNotNull($permit);
		$this->assertCount(1, $this->objects->saved);
		$written = $this->objects->saved[0]['object'];
		$this->assertSame('permit', $this->objects->saved[0]['schema']);
		$this->assertSame('Bewonersvergunning Lindelaan 4', $written['title']);
		$this->assertSame('parkeren-bewoner', $written['kind']);
		$this->assertSame('parkeren', $written['theme']);
		$this->assertSame('subj-sanne', $written['portalSubject']);
		$this->assertSame(self::CASE_ID, $written['case']);
		$this->assertSame(self::DECISION_ID, $written['decision']);
		$this->assertSame('2026-10-08', $written['validFrom']);
		$this->assertSame('active', $written['status']);
		$this->assertSame('GZ482K', $written['kenteken']);
		$this->assertSame('Lindelaan 4', $written['adres']);
		$this->assertSame([], (new RealSchemaValidator())->errors(slug: 'permit', payload: $written));

		// The same outcome delivered again writes nothing new.
		$this->issuer->issueFor(caseId: self::CASE_ID, decisionId: self::DECISION_ID, status: 'approved', decidedAt: '2026-10-08T14:00:00+02:00');
		$this->assertCount(1, $this->objects->saved);
	}//end testAnApprovedDecisionIssuesThePermitOnce()

	/**
	 * A case whose type issues nothing, or a resident without a portal
	 * subject, gets no permit.
	 *
	 * @return void
	 */
	public function testNoPermitWithoutAnIssuingTypeOrAHolder(): void {
		$this->objects->store['caseType'][self::TYPE_ID]['issuesPermit'] = null;
		$this->assertNull($this->issuer->issueFor(caseId: self::CASE_ID, decisionId: self::DECISION_ID, status: 'approved', decidedAt: null));

		$this->setUp();
		$this->objects->store['case'][self::CASE_ID]['portalSubject'] = '';
		$this->assertNull($this->issuer->issueFor(caseId: self::CASE_ID, decisionId: self::DECISION_ID, status: 'approved', decidedAt: null));
		$this->assertSame([], $this->objects->saved);

		$this->assertNull($this->issuer->issueFor(caseId: 'missing', decisionId: self::DECISION_ID, status: 'approved', decidedAt: null));
	}//end testNoPermitWithoutAnIssuingTypeOrAHolder()

	/**
	 * An approved change case sets the new plate on the permit it names, and
	 * only on the requester's own permit that is still in force.
	 *
	 * @return void
	 */
	public function testAnApprovedChangeCaseSetsTheNewPlate(): void {
		$this->objects->store['permit']['permit-1'] = ['id' => 'permit-1', 'portalSubject' => 'subj-sanne', 'status' => 'active', 'kenteken' => 'GZ482K'];

		$permit = $this->issuer->issueFor(caseId: self::CHANGE_CASE_ID, decisionId: self::DECISION_ID, status: 'approved', decidedAt: null);
		$this->assertSame('HX901B', $permit['kenteken']);
		$this->assertSame('HX901B', $this->objects->store['permit']['permit-1']['kenteken']);

		$this->objects->store['permit']['permit-1'] = ['id' => 'permit-1', 'portalSubject' => 'subj-other', 'status' => 'active', 'kenteken' => 'GZ482K'];
		$this->assertNull($this->issuer->issueFor(caseId: self::CHANGE_CASE_ID, decisionId: self::DECISION_ID, status: 'approved', decidedAt: null));
		$this->assertSame('GZ482K', $this->objects->store['permit']['permit-1']['kenteken']);
	}//end testAnApprovedChangeCaseSetsTheNewPlate()

	/**
	 * An outcome the case type does not declare issues nothing, and a
	 * declared one does: the trigger is configuration.
	 *
	 * @return void
	 */
	public function testOnlyADeclaredOutcomeIssues(): void {
		$this->assertNull($this->issuer->issueFor(caseId: self::CASE_ID, decisionId: self::DECISION_ID, status: 'rejected', decidedAt: null));
		$this->assertSame([], $this->objects->saved);

		$this->objects->store['caseType'][self::TYPE_ID]['issuesPermit']['issueOn'] = ['granted'];
		$this->assertNotNull($this->issuer->issueFor(caseId: self::CASE_ID, decisionId: self::DECISION_ID, status: 'Granted', decidedAt: null));
	}//end testOnlyADeclaredOutcomeIssues()
}//end class
