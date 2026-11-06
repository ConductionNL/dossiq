<?php

/**
 * Every controller registers under the dossiq app id.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * A controller built the way the container builds it answers to the dossiq app id.
 *
 * The container passes the app id to a `string $appName` parameter; a
 * controller without one names Application::APP_ID itself. Either way the
 * parent controller must hold `dossiq`.
 *
 * @covers \OCA\Dossiq\Controller\AcknowledgementController
 * @covers \OCA\Dossiq\Controller\AiController
 * @covers \OCA\Dossiq\Controller\CaseAssignmentController
 * @covers \OCA\Dossiq\Controller\CaseAttentionController
 * @covers \OCA\Dossiq\Controller\CaseCustodyController
 * @covers \OCA\Dossiq\Controller\CaseHandoverController
 * @covers \OCA\Dossiq\Controller\CaseTaskController
 * @covers \OCA\Dossiq\Controller\DocumentAssessmentController
 * @covers \OCA\Dossiq\Controller\FieldEvidenceController
 * @covers \OCA\Dossiq\Controller\FileRequestController
 * @covers \OCA\Dossiq\Controller\PipelinqContactMomentController
 * @covers \OCA\Dossiq\Controller\StarterContentController
 * @covers \OCA\Dossiq\Controller\StufController
 * @covers \OCA\Dossiq\Controller\SubstitutionController
 * @covers \OCA\Dossiq\Controller\TemplateStartController
 * @covers \OCA\Dossiq\Controller\ZrcController
 *
 * @spec exclude Pins how the controllers are constructed; no product behaviour.
 */
class ControllersNameTheirAppTest extends TestCase {

	/**
	 * The controllers.
	 *
	 * @return array<string, array{0: class-string<Controller>}>
	 */
	public static function controllers(): array {
		$names = [
			'Acknowledgement',
			'Ai',
			'CaseAssignment',
			'CaseAttention',
			'CaseCustody',
			'CaseHandover',
			'CaseTask',
			'DocumentAssessment',
			'FieldEvidence',
			'FileRequest',
			'PipelinqContactMoment',
			'StarterContent',
			'Stuf',
			'Substitution',
			'TemplateStart',
			'Zrc',
		];

		$out = [];
		foreach ($names as $name) {
			$out[$name] = ['OCA\\Dossiq\\Controller\\' . $name . 'Controller'];
		}

		return $out;
	}//end controllers()

	/**
	 * The controller holds the dossiq app id.
	 *
	 * @param class-string<Controller> $class The controller.
	 *
	 * @dataProvider controllers
	 *
	 * @return void
	 */
	public function testTheControllerHoldsTheAppId(string $class): void {
		$reflection = new ReflectionClass(objectOrClass: $class);
		$arguments = [];
		foreach ($reflection->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			$typeName = 'mixed';
			if ($type instanceof ReflectionNamedType) {
				$typeName = $type->getName();
			}

			$arguments[$parameter->getName()] = match ($typeName) {
				'string' => Application::APP_ID,
				'array' => [],
				default => $this->createMock(originalClassName: $typeName),
			};
		}

		$controller = $reflection->newInstanceArgs(args: $arguments);
		$appName = (new ReflectionClass(objectOrClass: Controller::class))->getProperty(name: 'appName');

		$this->assertSame(expected: 'dossiq', actual: $appName->getValue(object: $controller));
	}//end testTheControllerHoldsTheAppId()
}//end class
