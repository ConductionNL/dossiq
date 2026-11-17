<?php

/**
 * Woo Contract Registrar Test
 *
 * Every Kernel contract the Woo component implements is aliased to its
 * implementation, so other components get the shared instance.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Composition root wiring, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Kernel\Contracts\WooCaseDocumentsInterface;
use OCA\Dossiq\Kernel\Contracts\WooCollectionInterface;
use OCA\Dossiq\Kernel\Contracts\WooCollectionQueriesInterface;
use OCA\Dossiq\Kernel\Contracts\WooDeliveredSetVerifierInterface;
use OCA\Dossiq\Kernel\Contracts\WooDeliveredSetWriterInterface;
use OCA\Dossiq\Kernel\Contracts\WooDossierReturnInterface;
use OCA\Dossiq\Kernel\Contracts\WooGatherAddInterface;
use OCA\Dossiq\Kernel\Contracts\WooReceivedAnswersInterface;
use OCA\Dossiq\Kernel\Contracts\WooRecordedDeliveryInterface;
use OCA\Dossiq\Kernel\Contracts\WooReportReadLogInterface;
use OCA\Dossiq\Kernel\Contracts\WooRequestIntakeInterface;
use OCA\Dossiq\Kernel\Contracts\WooSearchPlansInterface;
use OCA\Dossiq\Kernel\Contracts\WooSourcesInterface;
use OCA\Dossiq\Kernel\Contracts\WooThroughputReportInterface;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCA\Dossiq\Woo\WooCollection;
use OCA\Dossiq\Woo\WooCollectionQueries;
use OCA\Dossiq\Woo\WooContractRegistrar;
use OCA\Dossiq\Woo\WooDeliveredSetVerifier;
use OCA\Dossiq\Woo\WooDeliveredSetWriter;
use OCA\Dossiq\Service\Publication\SourceCollectionReturn;
use OCA\Dossiq\Woo\WooGatherAdd;
use OCA\Dossiq\Woo\WooReceivedAnswers;
use OCA\Dossiq\Woo\WooReportReadLog;
use OCA\Dossiq\Woo\WooRequestIntake;
use OCA\Dossiq\Woo\WooSearchPlans;
use OCA\Dossiq\Woo\WooSources;
use OCA\Dossiq\Woo\WooThroughputReport;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Woo\WooContractRegistrar
 */
class WooContractRegistrarTest extends TestCase {

	public function testEveryContractIsAliasedToItsImplementationInOrder(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects(self::exactly(14))->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[] = [$alias, $target];
			}
		);

		(new WooContractRegistrar())->register(context: $context);

		self::assertSame(
			[
				[WooCaseDocumentsInterface::class, WooCaseDocuments::class],
				[WooCollectionInterface::class, WooCollection::class],
				[WooCollectionQueriesInterface::class, WooCollectionQueries::class],
				[WooDeliveredSetVerifierInterface::class, WooDeliveredSetVerifier::class],
				[WooDeliveredSetWriterInterface::class, WooDeliveredSetWriter::class],
				[WooDossierReturnInterface::class, SourceCollectionReturn::class],
				[WooGatherAddInterface::class, WooGatherAdd::class],
				[WooReceivedAnswersInterface::class, WooReceivedAnswers::class],
				[WooRecordedDeliveryInterface::class, WooDeliveredSetWriter::class],
				[WooReportReadLogInterface::class, WooReportReadLog::class],
				[WooRequestIntakeInterface::class, WooRequestIntake::class],
				[WooSearchPlansInterface::class, WooSearchPlans::class],
				[WooSourcesInterface::class, WooSources::class],
				[WooThroughputReportInterface::class, WooThroughputReport::class],
			],
			$aliases
		);
	}//end testEveryContractIsAliasedToItsImplementationInOrder()

	public function testEachImplementationFulfilsItsContract(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		(new WooContractRegistrar())->register(context: $context);

		foreach ($aliases as $contract => $implementation) {
			self::assertTrue(is_subclass_of($implementation, $contract), $implementation . ' implements ' . $contract);
		}
	}//end testEachImplementationFulfilsItsContract()
}//end class
