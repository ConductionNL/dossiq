<?php

/**
 * Record field shape tests
 *
 * A raw source value in the shape its field declaration asks for.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
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

namespace OCA\Dossiq\Tests\Unit\Service\Import;

use InvalidArgumentException;
use OCA\Dossiq\Service\Import\RecordFieldShape;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * Every shape a field may declare.
 *
 * @covers \OCA\Dossiq\Service\Import\RecordFieldShape
 * @uses   \OCA\Dossiq\Service\Import\RecordValues
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class RecordFieldShapeTest extends TestCase {

	use MakesCaseDateNormaliser;

	/**
	 * The shaper under test.
	 *
	 * @var RecordFieldShape
	 */
	private RecordFieldShape $shape;

	/**
	 * Set up over the tenant's date path.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->shape = new RecordFieldShape(dates: $this->caseDates());
	}//end setUp()

	/**
	 * Text collapses its whitespace and is cut at the last whole word; empty or non-scalar is null.
	 *
	 * @return void
	 */
	public function testTextIsCollapsedAndCut(): void {
		self::assertSame('a b', $this->shape->shaped(field: [], raw: "  a \n\t b "));
		self::assertSame('Een lange', $this->shape->shaped(field: ['maxLength' => 12], raw: 'Een lange titel'));
		self::assertSame('ABCD', $this->shape->shaped(field: ['maxLength' => 4], raw: 'ABCDEFGH'));
		self::assertSame('kort', $this->shape->shaped(field: ['maxLength' => 4], raw: 'kort'));
		self::assertNull($this->shape->shaped(field: [], raw: '  '));
		self::assertNull($this->shape->shaped(field: [], raw: ['x']));
	}//end testTextIsCollapsedAndCut()

	/**
	 * Booleans, integers and dates take their shape.
	 *
	 * @return void
	 */
	public function testTypedShapes(): void {
		self::assertTrue($this->shape->shaped(field: ['as' => 'boolean'], raw: 'YES'));
		self::assertFalse($this->shape->shaped(field: ['as' => 'boolean'], raw: 'no'));
		self::assertFalse($this->shape->shaped(field: ['as' => 'boolean'], raw: false));
		self::assertSame(7, $this->shape->shaped(field: ['as' => 'integer'], raw: '7 apples'));
		self::assertSame('2026-01-31', $this->shape->shaped(field: ['as' => 'date'], raw: '2026-01-31'));
		self::assertNull($this->shape->shaped(field: ['as' => 'date'], raw: 'never'));
	}//end testTypedShapes()

	/**
	 * A listed value maps, an empty one takes the default, an unknown one is refused by name.
	 *
	 * @return void
	 */
	public function testListedValues(): void {
		$field = ['from' => 'kind', 'values' => ['a' => 'alpha'], 'default' => 'none'];

		self::assertSame('alpha', $this->shape->shaped(field: $field, raw: 'a'));
		self::assertSame('none', $this->shape->shaped(field: $field, raw: ''));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The kind must be one of: a.');
		$this->shape->shaped(field: $field, raw: 'b');
	}//end testListedValues()
}//end class
