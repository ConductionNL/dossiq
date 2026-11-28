<?php

/**
 * PortalContractRegistrar Unit Tests
 *
 * @category  Tests
 * @package   OCA\Dossiq\Tests\Unit\Portal
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Kernel\Contracts\ApplicantPortalActsInterface;
use OCA\Dossiq\Kernel\Contracts\CitizenManifestInterface;
use OCA\Dossiq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Dossiq\Kernel\Contracts\PortalContributionProviderInterface;
use OCA\Dossiq\Kernel\Contracts\PortalSubmissionLedgerInterface;
use OCA\Dossiq\Kernel\Contracts\PortalWooAnswerInterface;
use OCA\Dossiq\Kernel\Contracts\ResidentQuestionTaskInterface;
use OCA\Dossiq\Portal\ApplicantPortalActs;
use OCA\Dossiq\Portal\CitizenManifest;
use OCA\Dossiq\Portal\PortalAssertionVerifier;
use OCA\Dossiq\Portal\PortalContractRegistrar;
use OCA\Dossiq\Portal\PortalContributionProvider;
use OCA\Dossiq\Portal\PortalResidentActsContractRegistrar;
use OCA\Dossiq\Portal\PortalSubmissionLedger;
use OCA\Dossiq\Portal\PortalWooAnswer;
use OCA\Dossiq\Portal\ResidentQuestionTask;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Portal\PortalContractRegistrar
 * @covers \OCA\Dossiq\Portal\PortalResidentActsContractRegistrar
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class PortalContractRegistrarTest extends TestCase {

	/**
	 * The aliases the registrar must register, in order: the component's own, then a resident's acts.
	 *
	 * @var array<int, array{0: class-string, 1: class-string}>
	 */
	private const EXPECTED = [
		[CitizenManifestInterface::class, CitizenManifest::class],
		[PortalAssertionVerifierInterface::class, PortalAssertionVerifier::class],
		[PortalContributionProviderInterface::class, PortalContributionProvider::class],
		[PortalSubmissionLedgerInterface::class, PortalSubmissionLedger::class],
		[ApplicantPortalActsInterface::class, ApplicantPortalActs::class],
		[PortalWooAnswerInterface::class, PortalWooAnswer::class],
		[ResidentQuestionTaskInterface::class, ResidentQuestionTask::class],
	];

	/**
	 * Every contract of the component is aliased exactly once, and nothing else is registered.
	 *
	 * @return void
	 */
	public function testRegistersEachContractAliasOnce(): void {
		$this->assertSame(expected: self::EXPECTED, actual: $this->aliasesOf(registrar: new PortalContractRegistrar()));
	}//end testRegistersEachContractAliasOnce()

	/**
	 * The resident's acts registrar binds only its three contracts.
	 *
	 * @return void
	 */
	public function testTheResidentActsRegistrarBindsItsThree(): void {
		$this->assertSame(
			expected: array_slice(array: self::EXPECTED, offset: 4),
			actual: $this->aliasesOf(registrar: new PortalResidentActsContractRegistrar())
		);
	}//end testTheResidentActsRegistrarBindsItsThree()

	/**
	 * Each aliased implementation actually implements the contract it is bound to.
	 *
	 * @return void
	 */
	public function testEachTargetImplementsItsContract(): void {
		foreach (self::EXPECTED as [$contract, $target]) {
			$this->assertTrue(
				condition: is_subclass_of(object_or_class: $target, class: $contract),
				message: $target . ' does not implement ' . $contract
			);
		}
	}//end testEachTargetImplementsItsContract()

	/**
	 * The aliases a registrar registers, in order; it registers no service.
	 *
	 * @param PortalContractRegistrar|PortalResidentActsContractRegistrar $registrar The registrar.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function aliasesOf(PortalContractRegistrar|PortalResidentActsContractRegistrar $registrar): array {
		$aliases = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->expects($this->any())->method(constraint: 'registerServiceAlias')->willReturnCallback(
			callback: function (string $alias, string $target) use (&$aliases): void {
				$aliases[] = [$alias, $target];
			}
		);
		$context->expects($this->never())->method(constraint: 'registerService');

		$registrar->register(context: $context);

		return $aliases;
	}//end aliasesOf()
}//end class
