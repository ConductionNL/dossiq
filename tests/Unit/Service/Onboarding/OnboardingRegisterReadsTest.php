<?php

/**
 * OnboardingRegisterReads Unit Tests
 *
 * The mandates step reads as done once the mandate register confirms an
 * active mandate, never over a step already decided; the tier comes from the
 * tenant's audit anchor and is `basic` without one; a schema count asks for
 * one row of the tenant's and reads a failed read as none.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Onboarding
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Onboarding;

use OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser;
use OCA\Dossiq\Service\MandaatCheckService;
use OCA\Dossiq\Service\Onboarding\OnboardingRegisterReads;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for OnboardingRegisterReads.
 *
 * @covers \OCA\Dossiq\Service\Onboarding\OnboardingRegisterReads
 * @uses   \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
final class OnboardingRegisterReadsTest extends TestCase {

	/**
	 * Reads over the real normaliser and a mandate register answering the given verdict.
	 *
	 * @param bool|null $activeMandate Whether a mandate is active, or null for no mandate register.
	 *
	 * @return OnboardingRegisterReads
	 */
	private function reads(?bool $activeMandate): OnboardingRegisterReads {
		$mandates = null;
		if ($activeMandate !== null) {
			$mandates = $this->createMock(originalClassName: MandaatCheckService::class);
			$mandates->method('hasActiveMandateFor')->willReturn($activeMandate);
		}

		return new OnboardingRegisterReads(rowNormaliser: new OpenRegisterRowNormaliser(), mandates: $mandates);
	}//end reads()

	/**
	 * An open mandates step is done once a mandate is active; a decided step and no register keep their state.
	 *
	 * @return void
	 */
	public function testTheMandatesStepFollowsTheMandateRegister(): void {
		$open = ['mandate_import' => ['status' => 'pending'], 'go_live' => ['status' => 'pending']];

		$done = $this->reads(activeMandate: true)->withMandatesSet(tenantId: 't1', steps: $open);
		$this->assertSame(expected: 'completed', actual: $done['mandate_import']['status']);
		$this->assertSame(expected: 'pending', actual: $done['go_live']['status']);

		$this->assertSame(expected: $open, actual: $this->reads(activeMandate: false)->withMandatesSet(tenantId: 't1', steps: $open));
		$this->assertSame(expected: $open, actual: $this->reads(activeMandate: null)->withMandatesSet(tenantId: 't1', steps: $open));

		$skipped = ['mandate_import' => ['status' => 'skipped']];
		$this->assertSame(expected: $skipped, actual: $this->reads(activeMandate: true)->withMandatesSet(tenantId: 't1', steps: $skipped));
	}//end testTheMandatesStepFollowsTheMandateRegister()

	/**
	 * The tier is read from the anchor; no service, a failed read or an empty tier is `basic`.
	 *
	 * @return void
	 */
	public function testTheTierComesFromTheAnchor(): void {
		$store = new class {
			/**
			 * The tier the anchor carries, or null when the read fails.
			 *
			 * @var string|null
			 */
			public ?string $tier = 'premium';

			/**
			 * The find options asked for.
			 *
			 * @var array<int, ObjectFindOptions>
			 */
			public array $asked = [];

			/**
			 * Answer the anchor, or fail.
			 *
			 * @param string            $id            The tenant uuid.
			 * @param ObjectFindOptions $options       Where to find it.
			 * @param bool              $_rbac         Whether RBAC applies.
			 * @param bool              $_multitenancy Whether multitenancy applies.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id, ObjectFindOptions $options, bool $_rbac=true, bool $_multitenancy=true): array {
				$this->asked[] = $options;
				if ($this->tier === null) {
					throw new RuntimeException('gone');
				}
				return ['id' => $id, 'tier' => $this->tier];
			}
		};
		$reads = $this->reads(activeMandate: null);

		$this->assertSame(expected: 'premium', actual: $reads->tierOf(objectService: $store, tenantId: 't1'));
		$this->assertSame(expected: 'tenant', actual: $store->asked[0]->schema);

		$store->tier = '';
		$this->assertSame(expected: 'basic', actual: $reads->tierOf(objectService: $store, tenantId: 't1'));
		$store->tier = null;
		$this->assertSame(expected: 'basic', actual: $reads->tierOf(objectService: $store, tenantId: 't1'));
		$this->assertSame(expected: 'basic', actual: $reads->tierOf(objectService: null, tenantId: 't1'));
	}//end testTheTierComesFromTheAnchor()

	/**
	 * A count asks for one of the tenant's rows in the schema, and a failed read counts none.
	 *
	 * @return void
	 */
	public function testACountAsksForOneRowAndAFailureCountsNone(): void {
		$store = new class {
			/**
			 * The findAll() configs, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $reads = [];

			/**
			 * Whether the read fails.
			 *
			 * @var bool
			 */
			public bool $fails = false;

			/**
			 * Answer one row, or fail.
			 *
			 * @param array<string, mixed> $config The read.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config): array {
				$this->reads[] = $config;
				if ($this->fails === true) {
					throw new RuntimeException('down');
				}
				return [['id' => 'row-1']];
			}
		};
		$reads = $this->reads(activeMandate: null);

		$admins = ['tenantRef' => 't1', 'role' => 'tenant_admin'];
		$this->assertSame(expected: 1, actual: $reads->countSchemaRows(objectService: $store, schema: 'tenantUser', filters: $admins));
		$this->assertSame(
			expected: [
				'filters' => ['register' => 'dossiq', 'schema' => 'tenantUser', 'tenantRef' => 't1', 'role' => 'tenant_admin'],
				'limit' => 1,
				'offset' => 0,
			],
			actual: $store->reads[0]
		);

		$store->fails = true;
		$this->assertSame(expected: 0, actual: $reads->countSchemaRows(objectService: $store, schema: 'caseType', filters: []));
	}//end testACountAsksForOneRowAndAFailureCountsNone()
}//end class
