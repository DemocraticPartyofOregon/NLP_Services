<?php

namespace Drupal\nlpservices;

/**
 * The rules for the date of a voter contact that a volunteer reports: it
 * can't be in the future, and a report more than LATE_DAYS old gets a
 * reminder to report right after the contact.
 *
 * The data entry page's date bar, its postcard list and the postcards page
 * all use these, through dateElement() for the date box and problem() /
 * isLate() when the form is submitted.
 */
final class NlpContactDate
{
  // A report this many days after the contact is still timely.
  public const int LATE_DAYS = 3;

  public const string FUTURE_MSG = 'The date of the voter contact can\'t be in the future.';
  public const string INVALID_MSG = 'The date of the voter contact isn\'t a valid date.';
  public const string LATE_MSG = 'Thank you for your reports.  Reports are of greatest value when
    they are entered right after the voter contact, so please report your contacts as soon as you can.';

  /**
   * problem
   *
   * Why a contact date can't be used, or '' if it can.  An empty date is
   * fine; the reports then use today.
   *
   * @param string $date - Y-m-d, as from a date box.
   * @param string|null $today - Y-m-d; NULL for today.
   * @return string
   */
  public static function problem(string $date, ?string $today = NULL): string
  {
    if ($date === '') {
      return '';
    }
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($parsed === FALSE || $parsed->format('Y-m-d') !== $date) {
      return self::INVALID_MSG;
    }
    return ($date > ($today ?? self::today())) ? self::FUTURE_MSG : '';
  }

  /**
   * isLate
   *
   * Whether a report for this contact date comes more than LATE_DAYS after
   * the contact.
   *
   * @param string $date - Y-m-d.
   * @param string|null $today - Y-m-d; NULL for today.
   * @return bool
   */
  public static function isLate(string $date, ?string $today = NULL): bool
  {
    if (self::problem($date, $today) !== '' || $date === '') {
      return FALSE;
    }
    $oldest = (new \DateTimeImmutable($today ?? self::today()))
      ->modify('-' . self::LATE_DAYS . ' days')->format('Y-m-d');
    return $date < $oldest;
  }

  /**
   * dateElement
   *
   * Adds to a date box what lets the browser stop a future date and remind
   * about a late one as soon as the date is picked (js/contact-date.js).
   * problem() still checks the date when the form is submitted.
   *
   * @param array $element - a '#type' => 'date' form element.
   * @return array
   */
  public static function dateElement(array $element): array
  {
    $element['#attributes']['max'] = self::today();
    $element['#attributes']['class'][] = 'nlp-contact-date';
    $element['#attributes']['data-late-days'] = (string) self::LATE_DAYS;
    $element['#attributes']['data-future-msg'] = self::FUTURE_MSG;
    $element['#attributes']['data-late-msg'] = preg_replace('/\s+/', ' ', self::LATE_MSG);
    $element['#attached']['library'][] = 'nlpservices/contact-date';
    return $element;
  }

  /**
   * Today in the site's time zone, Y-m-d.
   */
  public static function today(): string
  {
    return date('Y-m-d');
  }

}
