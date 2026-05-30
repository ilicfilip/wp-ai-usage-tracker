<?php
/**
 * Sends limit-threshold notifications (Phase 2).
 *
 * @package WP_AI_Rate_Limiter
 */

namespace WP_AI_Rate_Limiter\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * Delivers a notification when a usage limit crosses a threshold.
 *
 * Emails the site admin by default. Sites can route elsewhere (Slack, webhook)
 * by hooking the 'wp_ai_rate_limiter_notify' action, which fires with the same
 * structured payload before the email is sent.
 */
class Notifier {

	/**
	 * Send a threshold notification.
	 *
	 * @param array<string,mixed> $limit   The limit row that crossed.
	 * @param int                 $current Current usage in the limit's unit.
	 * @param int                 $percent The threshold crossed (80 or 100).
	 * @return void
	 */
	public function notify( array $limit, $current, $percent ) {
		/**
		 * Fires when a usage limit crosses an alert threshold.
		 *
		 * Lets sites deliver the alert through a custom channel. Fires regardless
		 * of whether the built-in email is sent.
		 *
		 * @param array<string,mixed> $limit   The limit row.
		 * @param int                 $current Current usage.
		 * @param int                 $percent Threshold crossed (80|100).
		 */
		do_action( 'wp_ai_rate_limiter_notify', $limit, $current, (int) $percent );

		$recipient = $this->recipient();

		if ( '' === $recipient ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: percent, 2: scope key. */
			__( '[AI Usage] %1$d%% of limit reached for %2$s', 'wp-ai-rate-limiter' ),
			(int) $percent,
			$this->scope_label( $limit )
		);

		$body = $this->build_body( $limit, (int) $current, (int) $percent );

		wp_mail( $recipient, $subject, $body );
	}

	/**
	 * Resolve the notification recipient.
	 *
	 * Defaults to the site admin email, filterable for custom routing.
	 *
	 * @return string Email address, or '' to skip the email.
	 */
	private function recipient() {
		/**
		 * Filter the alert email recipient.
		 *
		 * @param string $email Default admin email.
		 */
		$email = (string) apply_filters(
			'wp_ai_rate_limiter_alert_email',
			(string) get_option( 'admin_email' )
		);

		return is_email( $email ) ? $email : '';
	}

	/**
	 * Compose the email body.
	 *
	 * @param array<string,mixed> $limit   The limit row.
	 * @param int                 $current Current usage.
	 * @param int                 $percent Threshold crossed.
	 * @return string
	 */
	private function build_body( array $limit, $current, $percent ) {
		$lines = [
			sprintf(
				/* translators: 1: percent, 2: scope label. */
				__( 'AI usage has reached %1$d%% of a configured limit for %2$s.', 'wp-ai-rate-limiter' ),
				$percent,
				$this->scope_label( $limit )
			),
			'',
			sprintf(
				/* translators: 1: current usage, 2: threshold, 3: unit. */
				__( 'Current: %1$s of %2$s %3$s (%4$s).', 'wp-ai-rate-limiter' ),
				$this->format_value( $limit['limit_type'], $current ),
				$this->format_value( $limit['limit_type'], (int) $limit['threshold'] ),
				$limit['limit_type'],
				$limit['period_kind']
			),
		];

		if ( 'hard' === $limit['enforcement'] && $percent >= 100 ) {
			$lines[] = '';
			$lines[] = __( 'This is a hard limit: further requests in this scope are being blocked.', 'wp-ai-rate-limiter' );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Human label for a limit's scope.
	 *
	 * @param array<string,mixed> $limit The limit row.
	 * @return string
	 */
	private function scope_label( array $limit ) {
		$key = '*' === $limit['scope_key']
			? __( 'all', 'wp-ai-rate-limiter' )
			: $limit['scope_key'];

		return $limit['scope_type'] . ' ' . $key;
	}

	/**
	 * Format a stored value for display (cost micros -> USD).
	 *
	 * @param string $type  Limit type.
	 * @param int    $value Stored value.
	 * @return string
	 */
	private function format_value( $type, $value ) {
		if ( 'cost' === $type ) {
			return '$' . number_format( $value / 1000000, 4 );
		}

		return (string) $value;
	}
}
