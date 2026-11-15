<?php

/**
 * Every anonymously reachable endpoint carries a volume ceiling.
 *
 * 🔴 THIS TEST EXISTS BECAUSE AN UNTHROTTLED ENDPOINT LOOKS EXACTLY LIKE A
 * THROTTLED ONE. The ceiling is enforced by Nextcloud's
 * `RateLimitingMiddleware`, which runs before the controller and reads the
 * attribute by reflection. A method with no attribute does not error, does not
 * log and does not behave differently in any test that calls it directly: it
 * answers. So the only thing that can catch a missing ceiling is a check that
 * reads the attribute the middleware reads.
 *
 * The finding that prompted it: `PublicCaseSurvivorController::survivor` is
 * reached by anonymous callers with nothing but a token in the URL, and had no
 * cap at all. A token space with no rate limit is enumerable at whatever rate
 * the network allows.
 *
 * It is a SWEEP over every `#[PublicPage]` method in `lib/Controller/`, not an
 * assertion about one method, because the next public endpoint added without a
 * ceiling is exactly what a test naming today's endpoints would stay green
 * through.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-merge/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\PublicCaseSurvivorController;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCA\Dossiq\Service\CaseMergeService;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The ceiling on anonymous traffic.
 *
 * @coversNothing
 */
class PublicEndpointThrottlingTest extends TestCase {

	/**
	 * The attributes Nextcloud's middleware accepts as a ceiling.
	 *
	 * @var array<int, string>
	 */
	private const CEILINGS = [
		AnonRateLimit::class,
		UserRateLimit::class,
		BruteForceProtection::class,
	];

	/**
	 * The most survivor lookups one address may make in a minute.
	 *
	 * Tight on purpose: the only thing the caller brings is the token, so the
	 * ceiling IS the control. A real "track your case" link is followed once.
	 */
	private const SURVIVOR_CEILING = 10;

	/**
	 * Every `#[PublicPage]` method in `lib/Controller/` carries a ceiling.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-the-old-number-still-finds-the-case-req-cm-38
	 */
	public function testEveryPublicEndpointCarriesACeiling(): void {
		$unthrottled = [];
		$seen = 0;

		foreach ($this->controllerClasses() as $class) {
			$reflection = new ReflectionClass($class);
			foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class) {
					continue;
				}

				if ($method->getAttributes(PublicPage::class) === []) {
					continue;
				}

				$seen++;
				$hasCeiling = false;
				foreach (self::CEILINGS as $ceiling) {
					if ($method->getAttributes($ceiling) !== []) {
						$hasCeiling = true;
						break;
					}
				}

				if ($hasCeiling === false) {
					$unthrottled[] = $class . '::' . $method->getName();
				}
			}
		}

		// A sweep that found nothing to sweep is not a pass. If the discovery
		// below ever stops finding controllers, this is the line that says so
		// rather than the suite reporting green on zero work.
		$this->assertGreaterThan(
			0,
			$seen,
			'no #[PublicPage] method was found at all, so this test inspected nothing'
		);

		$this->assertSame(
			[],
			$unthrottled,
			"these endpoints answer anonymous callers with no volume ceiling:\n  "
			. implode("\n  ", $unthrottled)
		);
	}//end testEveryPublicEndpointCarriesACeiling()

	/**
	 * The survivor endpoint's ceiling is the one that refuses a token sweep.
	 *
	 * The sweep above would pass on a limit of a million an hour. This pins the
	 * number on the one endpoint whose entire authentication is a guessable
	 * string, and pins it as an upper bound so tightening it later stays green.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-the-old-number-still-finds-the-case-req-cm-38
	 */
	public function testTheSurvivorTokenLookupRefusesASweep(): void {
		$attributes = (new ReflectionClass(PublicCaseSurvivorController::class))
			->getMethod('survivor')
			->getAttributes(AnonRateLimit::class);

		$this->assertCount(1, $attributes, 'survivor must carry exactly one AnonRateLimit');

		$limit = $attributes[0]->newInstance();
		$this->assertSame(60, $limit->getPeriod(), 'the ceiling is expressed per minute');
		$this->assertLessThanOrEqual(
			self::SURVIVOR_CEILING,
			$limit->getLimit(),
			'a token space with a loose ceiling is still enumerable'
		);
		// And not zero, which would refuse the legitimate link and is how a
		// limit gets removed altogether.
		$this->assertGreaterThan(0, $limit->getLimit(), 'the real link must still resolve');
	}//end testTheSurvivorTokenLookupRefusesASweep()

	/**
	 * Every controller class under `lib/Controller/`.
	 *
	 * Derived from the directory rather than listed, so a controller added
	 * later is inspected without anyone remembering to add it here.
	 *
	 * @return array<int, class-string>
	 */
	private function controllerClasses(): array {
		$dir = __DIR__ . '/../../../lib/Controller';
		$classes = [];

		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
		foreach ($iterator as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$relative = substr((string)$file->getPathname(), (strlen($dir) + 1), -4);
			$class = 'OCA\\Dossiq\\Controller\\' . str_replace('/', '\\', $relative);
			if (class_exists($class) === true) {
				$classes[] = $class;
			}
		}

		sort($classes);
		return $classes;
	}//end controllerClasses()

	/**
	 * The uniform 404 is the same answer for every way of failing.
	 *
	 * Contract coverage for `publicCaseSurvivor#survivor` (gate-25), and the
	 * other half of the throttling above: the CEILING stops a sweep going
	 * fast, and the UNIFORM 404 stops a sweep learning anything from the
	 * answers it does get. Both are needed. A 404 for an unknown token and a
	 * 403 for a known-but-unmerged one would turn this endpoint into an oracle
	 * for which tokens exist, at any rate at all.
	 *
	 * @return void
	 */
	public function testEveryWayOfFailingGetsTheSameUniform404(): void {
		$settings = $this->createMock(SettingsService::class);
		// No token service resolvable: the token cannot be read at all.
		$settings->method('getOpenRegisterClass')->willReturn(null);
		$settings->method('getObjectService')->willReturn(null);
		$settings->method('getConfigValue')->willReturn('');

		$merge = $this->createMock(CaseMergeService::class);
		$merge->expects(self::never())->method('resolveSurvivor');

		$controller = new PublicCaseSurvivorController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			mergeService: $merge,
			settingsService: $settings,
			logger: $this->createMock(LoggerInterface::class),
		);

		foreach (['', 'not-a-token', str_repeat('a', 64)] as $token) {
			$response = $controller->survivor(token: $token);

			self::assertSame(
				Http::STATUS_NOT_FOUND,
				$response->getStatus(),
				'token ' . var_export($token, true) . ' must get the uniform 404'
			);
			self::assertSame(
				['message' => 'Not Found'],
				$response->getData(),
				'the body must not differ between failures either'
			);
		}
	}//end testEveryWayOfFailingGetsTheSameUniform404()
}//end class
