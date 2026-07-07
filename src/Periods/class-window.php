<?php
/**
 * Time-period helpers (timezone-aware, no database access).
 *
 * @package WP_AIUT
 */

namespace WP_AIUT\Periods;

defined( 'ABSPATH' ) || exit;

/**
 * Computes period keys and ranges in the site timezone.
 *
 * Period keys are the stable identity used by the counters table (spec §5):
 *   - 'day'   => 'Y-m-d' (e.g. '2026-05-29')
 *   - 'month' => 'Y-m'   (e.g. '2026-05')
 *
 * A new period is simply a new key — there is never a destructive reset.
 */
class Window {

	/**
	 * Period kind: calendar day.
	 */
	const KIND_DAY = 'day';

	/**
	 * Period kind: calendar month.
	 */
	const KIND_MONTH = 'month';

	/**
	 * Compute the period key for a given kind at a given moment.
	 *
	 * @param string   $kind 'day' or 'month'.
	 * @param int|null $timestamp Unix timestamp, or null for now().
	 * @return string Period key (e.g. '2026-05-29' or '2026-05').
	 */
	public static function period_key( $kind, $timestamp = null ) {
		$date = self::date_in_site_tz( $timestamp );

		if ( self::KIND_MONTH === $kind ) {
			return $date->format( 'Y-m' );
		}

		// Default to day.
		return $date->format( 'Y-m-d' );
	}

	/**
	 * Convenience: the period key for the current moment.
	 *
	 * @param string $kind 'day' or 'month'.
	 * @return string Period key.
	 */
	public static function current_period_key( $kind ) {
		return self::period_key( $kind );
	}

	/**
	 * Resolve the inclusive-start / exclusive-end range for a period.
	 *
	 * Returns DateTimeImmutable objects in the site timezone. The 'from' is the
	 * first instant of the period; the 'to' is the first instant of the next
	 * period (half-open interval [from, to)).
	 *
	 * @param string $kind       'day' or 'month'.
	 * @param string $period_key Period key, e.g. '2026-05-29' or '2026-05'.
	 * @return array{from:\DateTimeImmutable,to:\DateTimeImmutable}|null
	 *               Range, or null if the key is malformed for the kind.
	 */
	public static function range( $kind, $period_key ) {
		$tz = wp_timezone();

		if ( self::KIND_MONTH === $kind ) {
			if ( ! preg_match( '/^\d{4}-\d{2}$/', $period_key ) ) {
				return null;
			}

			$from = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $period_key . '-01 00:00:00', $tz );

			if ( false === $from ) {
				return null;
			}

			$to = $from->modify( 'first day of next month' );

			return [
				'from' => $from,
				'to'   => $to,
			];
		}

		// Day.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $period_key ) ) {
			return null;
		}

		$from = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $period_key . ' 00:00:00', $tz );

		if ( false === $from ) {
			return null;
		}

		$to = $from->modify( '+1 day' );

		return [
			'from' => $from,
			'to'   => $to,
		];
	}

	/**
	 * Build a DateTimeImmutable for the given timestamp in the site timezone.
	 *
	 * @param int|null $timestamp Unix timestamp, or null for now().
	 * @return \DateTimeImmutable
	 */
	private static function date_in_site_tz( $timestamp = null ) {
		$tz = wp_timezone();

		if ( null === $timestamp ) {
			return new \DateTimeImmutable( 'now', $tz );
		}

		$date = new \DateTimeImmutable( '@' . (int) $timestamp );

		// "@" timestamps are always UTC; shift into the site timezone.
		return $date->setTimezone( $tz );
	}
}
