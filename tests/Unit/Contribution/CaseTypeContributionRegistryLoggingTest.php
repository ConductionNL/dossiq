<?php

/**
 * Unit tests for what the case-type contribution registry logs and normalises.
 *
 * The provider doubles live at the real convention FQCNs, as in
 * CaseTypeContributionRegistryTest, so the registry's own class_exists()
 * resolution runs.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Contribution
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://dossiq.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Trimmingapp\Dossiq;

/**
 * Declares case types with padding and a stray non-array entry.
 */
class CaseTypeContributionProvider {

	/**
	 * The declared case types.
	 *
	 * @return array<int, mixed> The declarations.
	 */
	public function getCaseTypes(): array {
		return [
			['identifier' => '  padded-type ', 'title' => ' Padded type  ', 'version' => 2],
			'not a declaration',
			['identifier' => '   ', 'title' => 'Blank identifier'],
		];
	}//end getCaseTypes()
}//end class

namespace OCA\Scalarapp\Dossiq;

/**
 * Answers something other than a list.
 */
class CaseTypeContributionProvider {

	/**
	 * Not a list.
	 *
	 * @return string The answer.
	 */
	public function getCaseTypes(): string {
		return 'none';
	}//end getCaseTypes()
}//end class

namespace OCA\Explodingapp\Dossiq;

use RuntimeException;

/**
 * Throws when asked.
 */
class CaseTypeContributionProvider {

	/**
	 * Fails.
	 *
	 * @return array<int, mixed> Never.
	 */
	public function getCaseTypes(): array {
		throw new RuntimeException('provider exploded');
	}//end getCaseTypes()
}//end class

namespace OCA\Unbuildableapp\Dossiq;

/**
 * A provider the container cannot build.
 */
class CaseTypeContributionProvider {

	/**
	 * Would declare a case type.
	 *
	 * @return array<int, mixed> The declarations.
	 */
	public function getCaseTypes(): array {
		return [['identifier' => 'unreachable', 'title' => 'Unreachable']];
	}//end getCaseTypes()
}//end class

namespace OCA\Dossiq\Tests\Unit\Contribution;

use OCA\Dossiq\Contribution\CaseTypeContributionRegistry;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Normalisation of declarations, and the log line each kind of failure leaves.
 *
 * @covers \OCA\Dossiq\Contribution\CaseTypeContributionRegistry
 */
class CaseTypeContributionRegistryLoggingTest extends TestCase {

	/**
	 * Build the registry.
	 *
	 * @param array<int, string> $apps   The installed apps.
	 * @param LoggerInterface    $logger The logger.
	 *
	 * @return CaseTypeContributionRegistry The registry.
	 */
	private function registry(array $apps, LoggerInterface $logger): CaseTypeContributionRegistry {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($apps);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id): object {
				if ($id === 'OCA\Unbuildableapp\Dossiq\CaseTypeContributionProvider') {
					throw new RuntimeException('missing dependency');
				}

				return new $id();
			}
		);

		return new CaseTypeContributionRegistry($appManager, $container, $logger);
	}//end registry()

	/**
	 * Identifier and title are trimmed, other keys kept, and non-declarations skipped with a warning each.
	 *
	 * @return void
	 */
	public function testDeclarationsAreTrimmedAndBadOnesWarned(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::exactly(2))->method('warning')->with(
			'Dossiq: skipping a case type with no identifier or title',
			['app' => 'trimmingapp']
		);

		self::assertSame(
			[['identifier' => 'padded-type', 'title' => 'Padded type', 'version' => 2, 'contributedBy' => 'trimmingapp']],
			$this->registry(apps: ['trimmingapp'], logger: $logger)->all()
		);
	}//end testDeclarationsAreTrimmedAndBadOnesWarned()

	/**
	 * find() matches the trimmed identifier exactly.
	 *
	 * @return void
	 */
	public function testFindMatchesTheTrimmedIdentifier(): void {
		$registry = $this->registry(apps: ['trimmingapp'], logger: $this->createMock(LoggerInterface::class));

		self::assertSame('Padded type', ($registry->find(identifier: 'padded-type') ?? [])['title'] ?? null);
		self::assertNull($registry->find(identifier: '  padded-type '));
	}//end testFindMatchesTheTrimmedIdentifier()

	/**
	 * A provider answering something other than a list contributes nothing, silently.
	 *
	 * @return void
	 */
	public function testANonListAnswerContributesNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');
		$logger->expects(self::never())->method('error');

		self::assertSame([], $this->registry(apps: ['scalarapp'], logger: $logger)->all());
	}//end testANonListAnswerContributesNothing()

	/**
	 * A throwing provider is logged as an error naming the app and the reason.
	 *
	 * @return void
	 */
	public function testAThrowingProviderIsLoggedAsAnError(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with(
			'Dossiq: case-type provider failed',
			['app' => 'explodingapp', 'reason' => 'provider exploded']
		);

		self::assertSame([], $this->registry(apps: ['explodingapp'], logger: $logger)->all());
	}//end testAThrowingProviderIsLoggedAsAnError()

	/**
	 * A provider the container cannot build is logged at debug and skipped.
	 *
	 * @return void
	 */
	public function testAnUnbuildableProviderIsLoggedAtDebug(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('debug')->with(
			'Dossiq: case-type provider not resolvable',
			['app' => 'unbuildableapp', 'reason' => 'missing dependency']
		);
		$logger->expects(self::never())->method('error');

		self::assertSame([], $this->registry(apps: ['unbuildableapp', 'scalarapp'], logger: $logger)->all());
	}//end testAnUnbuildableProviderIsLoggedAtDebug()
}//end class
