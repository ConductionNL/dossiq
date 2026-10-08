<?php

/**
 * ZGW Rate Limit Tests
 *
 * Every ZGW endpoint keeps a throttle, and the throttle stays above the floor
 * a machine to machine API needs.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The ZGW throttle has two failure modes and this guards both.
 *
 * Remove the attribute and an endpoint has no ceiling at all. That was the
 * state of `AcController::create/update/patch/destroy`, which mint and revoke
 * the credentials every other ZGW call authenticates with.
 *
 * Set the attribute too low and an ordinary integration gets a bare 429 with
 * no body, which reads like the server falling over. That was the state of
 * every write endpoint at 30 a minute: the two VNG contract collections peak
 * at 180 writes inside one 60 second window, and 133 of 646 business-rules
 * requests came back 429.
 *
 * @covers \OCA\Dossiq\Service\ZgwService
 */
class ZgwRateLimitTest extends TestCase {

	/**
	 * The six ZGW component controllers.
	 *
	 * @var string[]
	 */
	private const CONTROLLERS = [
		\OCA\Dossiq\Controller\ZrcController::class,
		\OCA\Dossiq\Controller\ZtcController::class,
		\OCA\Dossiq\Controller\BrcController::class,
		\OCA\Dossiq\Controller\DrcController::class,
		\OCA\Dossiq\Controller\NrcController::class,
		\OCA\Dossiq\Controller\AcController::class,
	];

	/**
	 * Every endpoint this controller declares, with the limits it carries.
	 *
	 * @param string $class The controller class name
	 *
	 * @return array<string, int[]> Method name to the limits declared on it
	 */
	private function endpointLimits(string $class): array {
		$reflected = new ReflectionClass($class);
		$found = [];

		foreach ($reflected->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() !== $class) {
				continue;
			}

			if ($method->isConstructor() === true) {
				continue;
			}

			$limits = [];
			foreach ($method->getAttributes(AnonRateLimit::class) as $attribute) {
				$arguments = $attribute->getArguments();
				$limits[] = (int)($arguments['limit'] ?? $arguments[0]);
			}

			$found[$method->getName()] = $limits;
		}

		return $found;
	}//end endpointLimits()

	/**
	 * No ZGW endpoint ships without a throttle.
	 *
	 * @return void
	 */
	public function testEveryZgwEndpointDeclaresARateLimit(): void {
		$unthrottled = [];

		foreach (self::CONTROLLERS as $class) {
			$limits = $this->endpointLimits($class);
			$this->assertNotEmpty($limits, $class . ' declares no endpoints at all.');

			foreach ($limits as $method => $declared) {
				if ($declared === []) {
					$unthrottled[] = substr(strrchr($class, '\\') ?: $class, 1) . '::' . $method;
				}
			}
		}

		$this->assertSame(
			[],
			$unthrottled,
			"These ZGW endpoints have no AnonRateLimit, so they have no ceiling at all:\n  "
			. implode("\n  ", $unthrottled)
		);
	}//end testEveryZgwEndpointDeclaresARateLimit()

	/**
	 * No throttle sits below the write tier.
	 *
	 * The write tier is the floor because it is the smaller of the two. A
	 * limit under it means somebody tightened an endpoint past what a single
	 * conformance run makes, and the symptom is a bare 429 rather than a
	 * message anybody can act on.
	 *
	 * @return void
	 */
	public function testNoZgwEndpointIsThrottledBelowTheWriteTier(): void {
		$tooTight = [];

		foreach (self::CONTROLLERS as $class) {
			foreach ($this->endpointLimits($class) as $method => $declared) {
				foreach ($declared as $limit) {
					if ($limit < ZgwService::RATE_LIMIT_WRITE) {
						$tooTight[] = sprintf(
							'%s::%s at %d, under the %d write tier',
							substr(strrchr($class, '\\') ?: $class, 1),
							$method,
							$limit,
							ZgwService::RATE_LIMIT_WRITE
						);
					}
				}
			}
		}

		$this->assertSame(
			[],
			$tooTight,
			"These ZGW endpoints throttle below what one contract run makes:\n  "
			. implode("\n  ", $tooTight)
		);
	}//end testNoZgwEndpointIsThrottledBelowTheWriteTier()

	/**
	 * The two tiers stay above the measured peak of a contract run.
	 *
	 * 180 writes and 35 reads in one 60 second window, measured on the VNG OAS
	 * and business-rules collections. Without this, the two tests above would
	 * still pass after somebody lowered both constants together.
	 *
	 * @return void
	 */
	public function testTheTiersClearTheMeasuredContractRunPeak(): void {
		$this->assertGreaterThanOrEqual(
			180,
			ZgwService::RATE_LIMIT_WRITE,
			'The write tier is under the 180 writes a minute the VNG contract collections peak at.'
		);
		$this->assertGreaterThanOrEqual(
			35,
			ZgwService::RATE_LIMIT_READ,
			'The read tier is under the 35 reads a minute the VNG contract collections peak at.'
		);
	}//end testTheTiersClearTheMeasuredContractRunPeak()
}//end class
