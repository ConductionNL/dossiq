<?php

/**
 * A disclosure assessment is checked against its classifications and the settled refusal-ground list.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Review
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-dossiq-validates-assessments-against-the-list-req-wrg-005
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Review;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Review\DisclosureAssessmentValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\RefusalGroundStore;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Review\DisclosureAssessmentValidator
 * @uses   \OCA\Dossiq\Woo\WooRefusalGrounds
 * @uses   \OCA\Dossiq\Exception\RefusedException
 */
class DisclosureAssessmentValidatorTest extends TestCase {

	/**
	 * The seeded refusal grounds.
	 *
	 * @var RefusalGroundStore
	 */
	private RefusalGroundStore $grounds;

	/**
	 * The validator under test.
	 *
	 * @var DisclosureAssessmentValidator
	 */
	private DisclosureAssessmentValidator $validator;

	/**
	 * A validator over the seeded list.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->grounds = RefusalGroundStore::seeded();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->grounds);
		$this->validator = new DisclosureAssessmentValidator(
			refusalGrounds: new WooRefusalGrounds(settingsService: $settings, logger: $this->createMock(LoggerInterface::class))
		);
	}//end setUp()

	/**
	 * A disclosed document needs no ground; a missing document and classification are named.
	 *
	 * @return void
	 */
	public function testTheRequiredFieldsAreNamed(): void {
		self::assertSame([], $this->validator->validate(assessment: ['documentRef' => 'doc-1', 'classification' => 'openbaar']));
		self::assertSame(
			['documentRef' => 'documentRef is required', 'classification' => 'classification is required'],
			$this->validator->validate(assessment: [])
		);
		self::assertStringContainsString('Invalid classification', $this->validator->validate(assessment: ['documentRef' => 'doc-1', 'classification' => 'geheim'])['classification']);
	}//end testTheRequiredFieldsAreNamed()

	/**
	 * Withholding anything needs a ground, and the ground must be on the list.
	 *
	 * @return void
	 */
	public function testAWithholdingClassificationNeedsAKnownGround(): void {
		self::assertStringContainsString(
			'At least one weigeringsgrond',
			$this->validator->validate(assessment: ['documentRef' => 'doc-1', 'classification' => 'niet_openbaar'])['weigeringsgronden']
		);
		self::assertSame(
			'Unknown weigeringsgrond: 5.2.5',
			$this->validator->validate(assessment: ['documentRef' => 'doc-1', 'classification' => 'niet_openbaar', 'weigeringsgronden' => ['5.2.5']])['weigeringsgronden']
		);
		self::assertSame([], $this->validator->validate(assessment: ['documentRef' => 'doc-1', 'classification' => 'deels_openbaar', 'weigeringsgronden' => ['5.1.2.e']]));
	}//end testAWithholdingClassificationNeedsAKnownGround()

	/**
	 * An unreadable list refuses the assessment with 503.
	 *
	 * @return void
	 */
	public function testAnUnreadableListRefuses(): void {
		$this->grounds->fails = true;

		$this->expectException(RefusedException::class);
		$this->validator->validate(assessment: ['documentRef' => 'doc-1', 'classification' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.2.e']]);
	}//end testAnUnreadableListRefuses()
}//end class
