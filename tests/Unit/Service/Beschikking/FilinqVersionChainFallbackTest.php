<?php

/**
 * Tests for the filinq adapter when the version chain cannot answer for the effective date.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Service\Beschikking\DecisionTemplateEngine;
use OCA\Dossiq\Service\Beschikking\FilinqTemplateEngineAdapter;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Support\FleetAppId;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An unreadable date and a version chain that throws both fall back to the template's own version.
 */
#[CoversClass(FilinqTemplateEngineAdapter::class)]
#[UsesClass(FleetAppId::class)]
class FilinqVersionChainFallbackTest extends TestCase {

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * An adapter whose container holds filinq's template and version services.
	 *
	 * @param object $versions The version service double.
	 *
	 * @return FilinqTemplateEngineAdapter The adapter.
	 */
	private function adapter(object $versions): FilinqTemplateEngineAdapter {
		$templates = new class {
			/**
			 * A template OpenRegister versions under @self.
			 *
			 * @param string $id The template id.
			 *
			 * @return array<string, mixed> The template.
			 */
			public function getTemplate(string $id): array {
				return ['id' => $id, '@self' => ['version' => '0.0.6']];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\Filinq\Service\TemplateService' => $templates,
				'OCA\Filinq\Service\TemplateVersionService' => $versions,
				default => throw new class('not registered') extends \Exception implements \Psr\Container\NotFoundExceptionInterface {
				},
			}
		);

		return new FilinqTemplateEngineAdapter(
			$container,
			$this->createMock(SettingsService::class),
			$this->createMock(IUserSession::class),
			$this->logger,
			$this->createMock(DecisionTemplateEngine::class)
		);
	}//end adapter()

	/**
	 * A version service that records whether it was asked, and can fail.
	 *
	 * @param bool $fails Whether listing the versions throws.
	 *
	 * @return object The double.
	 */
	private function versions(bool $fails): object {
		return new class($fails) {
			/**
			 * Calls made.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Constructor.
			 *
			 * @param bool $fails Whether listing throws.
			 */
			public function __construct(private bool $fails) {
			}

			/**
			 * A chain whose only version predates every date asked.
			 *
			 * @param string $templateId The template.
			 * @param int    $limit      The page size.
			 *
			 * @return array<string, mixed> The page.
			 */
			public function getVersions(string $templateId, int $limit = 20): array {
				$this->calls++;
				if ($this->fails === true) {
					throw new RuntimeException('chain unreadable');
				}

				return ['results' => [['version' => 9, '@self' => ['created' => '2020-01-01T00:00:00+00:00']]]];
			}
		};
	}//end versions()

	/**
	 * An effective date that cannot be read never consults the chain.
	 *
	 * @return void
	 */
	public function testAnUnreadableEffectiveDateSkipsTheChain(): void {
		$versions = $this->versions(fails: false);

		$resolved = $this->adapter(versions: $versions)->resolveVersion('tpl-abc', 'not a date');

		$this->assertSame(['templateId' => 'tpl-abc', 'version' => '0.0.6', 'effectiveDate' => 'not a date'], $resolved);
		$this->assertSame(0, $versions->calls);
	}//end testAnUnreadableEffectiveDateSkipsTheChain()

	/**
	 * A chain that cannot be listed is logged and the template's own version is used.
	 *
	 * @return void
	 */
	public function testAnUnlistableChainIsLoggedAndFallsBack(): void {
		$versions = $this->versions(fails: true);
		$this->logger->expects($this->once())->method('warning')->with(
			'Filinq could not list the versions of a beschikking template',
			['app' => 'dossiq', 'templateId' => 'tpl-abc', 'exception' => 'chain unreadable']
		);

		$resolved = $this->adapter(versions: $versions)->resolveVersion('tpl-abc', '2026-03-15');

		$this->assertSame('0.0.6', $resolved['version']);
		$this->assertSame(1, $versions->calls);
	}//end testAnUnlistableChainIsLoggedAndFallsBack()

	/**
	 * The same chain, readable, answers with its version in force.
	 *
	 * @return void
	 */
	public function testAReadableChainAnswersFirst(): void {
		$this->assertSame('v9', $this->adapter(versions: $this->versions(fails: false))->resolveVersion('tpl-abc', '2026-03-15')['version']);
	}//end testAReadableChainAnswersFirst()
}//end class
