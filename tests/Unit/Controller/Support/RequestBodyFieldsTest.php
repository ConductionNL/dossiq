<?php

/**
 * Unit tests for the request body fields a controller asks for.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
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
 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\RequestBodyFields;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\RequestBodyFields
 */
class RequestBodyFieldsTest extends TestCase {

	/**
	 * What each refusal says.
	 *
	 * @var array{required: string, invalid: string}
	 */
	private const MESSAGES = ['required' => 'outcome is required', 'invalid' => 'Invalid outcome value'];

	/**
	 * A missing choice and an unknown one are each refused with their own sentence; an allowed one is not.
	 *
	 * @return void
	 */
	public function testAChoiceIsRequiredAndMustBeAllowed(): void {
		$fields = new RequestBodyFields();

		$missing = $fields->choiceRefusal(value: '', allowed: ['granted', 'refused'], messages: self::MESSAGES);
		$unknown = $fields->choiceRefusal(value: 'maybe', allowed: ['granted', 'refused'], messages: self::MESSAGES);

		self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $missing?->getStatus());
		self::assertSame(expected: ['error' => 'outcome is required'], actual: $missing?->getData());
		self::assertSame(expected: ['error' => 'Invalid outcome value'], actual: $unknown?->getData());
		self::assertNull(actual: $fields->choiceRefusal(value: 'granted', allowed: ['granted', 'refused'], messages: self::MESSAGES));
	}//end testAChoiceIsRequiredAndMustBeAllowed()

	/**
	 * An absent field is null; a sent one, blank or not, is its string.
	 *
	 * @return void
	 */
	public function testAnOptionalStringIsNullOnlyWhenAbsent(): void {
		$fields = new RequestBodyFields();

		self::assertNull(actual: $fields->optionalString(body: [], key: 'notes'));
		self::assertSame(expected: ' ', actual: $fields->optionalString(body: ['notes' => ' '], key: 'notes'));
		self::assertSame(expected: '2026', actual: $fields->optionalString(body: ['notes' => 2026], key: 'notes'));
	}//end testAnOptionalStringIsNullOnlyWhenAbsent()
}//end class
