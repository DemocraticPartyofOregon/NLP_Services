<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Service;

use Drupal\nlpservices\NlpContactDate;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(NlpContactDate::class)]
#[Group('nlpservices')]
class NlpContactDateTest extends UnitTestCase {

  private const string TODAY = '2026-11-18';

  public function testProblemAcceptsTodayAndEarlierDates(): void {
    $this->assertSame('', NlpContactDate::problem('2026-11-18', self::TODAY));
    $this->assertSame('', NlpContactDate::problem('2026-10-01', self::TODAY));
  }

  public function testProblemAcceptsNoDate(): void {
    $this->assertSame('', NlpContactDate::problem('', self::TODAY));
  }

  public function testProblemRejectsAFutureDate(): void {
    $this->assertSame(NlpContactDate::FUTURE_MSG, NlpContactDate::problem('2026-11-19', self::TODAY));
    $this->assertSame(NlpContactDate::FUTURE_MSG, NlpContactDate::problem('2027-01-01', self::TODAY));
  }

  public function testProblemRejectsAnInvalidDate(): void {
    $this->assertSame(NlpContactDate::INVALID_MSG, NlpContactDate::problem('2026-02-30', self::TODAY));
    $this->assertSame(NlpContactDate::INVALID_MSG, NlpContactDate::problem('11/18/2026', self::TODAY));
  }

  #[DataProvider('lateProvider')]
  public function testIsLateForReportsMoreThanThreeDaysAfterTheContact(string $date, bool $late): void {
    $this->assertSame($late, NlpContactDate::isLate($date, self::TODAY));
  }

  public static function lateProvider(): array {
    return [
      'today'             => ['2026-11-18', FALSE],
      'three days ago'    => ['2026-11-15', FALSE],
      'four days ago'     => ['2026-11-14', TRUE],
      'last month'        => ['2026-10-01', TRUE],
      'across a year end' => ['2025-12-30', TRUE],
      'no date'           => ['', FALSE],
      'future'            => ['2026-11-20', FALSE],
    ];
  }

  public function testDateElementStopsFutureDatesAndAddsTheReminder(): void {
    $element = NlpContactDate::dateElement([
      '#type' => 'date',
      '#attributes' => ['class' => ['nlp-not-an-edit']],
    ]);

    $this->assertSame(NlpContactDate::today(), $element['#attributes']['max']);
    // Classes already on the box are kept.
    $this->assertSame(['nlp-not-an-edit', 'nlp-contact-date'], $element['#attributes']['class']);
    $this->assertSame('3', $element['#attributes']['data-late-days']);
    $this->assertSame(NlpContactDate::FUTURE_MSG, $element['#attributes']['data-future-msg']);
    $this->assertStringContainsString('right after the voter contact', $element['#attributes']['data-late-msg']);
    $this->assertContains('nlpservices/contact-date', $element['#attached']['library']);
  }

}
