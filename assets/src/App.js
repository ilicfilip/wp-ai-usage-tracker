/**
 * AI Usage dashboard (spec §7).
 *
 * A single admin page with a period switcher (This month / Today) and four
 * sections:
 *   1. Totals strip + provider/model breakdown.
 *   2. Spend per plugin (the headline): ranked bar + table with confidence badge.
 *   3. Spend per user / role with a user|role toggle.
 *   4. Usage over time: inline SVG line/area chart with a cost|tokens toggle.
 *
 * Data comes from the 'wp-ai-rate-limiter/v1' REST namespace via api-fetch.
 * Charts are tiny inline-SVG helpers (no chart dependency) to keep the bundle
 * small. Costs are stored as integer micros (1e-6 USD) and formatted to dollars.
 *
 * @package
 */

import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import Limits from './Limits';
import {
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexItem,
	Button,
	Spinner,
	Notice,
	Tip,
	// ToggleGroupControl is still behind the __experimental prefix in this WP
	// version but is widely used in core; it is the supported replacement for the
	// now-deprecated ButtonGroup segmented control.
	/* eslint-disable @wordpress/no-unsafe-wp-apis */
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	/* eslint-enable @wordpress/no-unsafe-wp-apis */
} from '@wordpress/components';

/* -------------------------------------------------------------------------- */
/* Formatting helpers                                                          */
/* -------------------------------------------------------------------------- */

const UNKNOWN_SLUG = '__unknown__';
const SYSTEM_USER = '__system__';

/**
 * Format integer micros (1e-6 USD) as a USD string.
 *
 * @param {number} micros Cost in micros.
 * @return {string} e.g. "$43.07".
 */
function formatMoney( micros ) {
	const dollars = ( Number( micros ) || 0 ) / 1e6;
	return new Intl.NumberFormat( undefined, {
		style: 'currency',
		currency: 'USD',
		minimumFractionDigits: 2,
		maximumFractionDigits: dollars < 1 ? 4 : 2,
	} ).format( dollars );
}

/**
 * Format a token / request count with thousands separators.
 *
 * @param {number} n Raw count.
 * @return {string} Localized number.
 */
function formatNumber( n ) {
	return new Intl.NumberFormat().format( Number( n ) || 0 );
}

/**
 * Round a value up to a "nice" axis ceiling: 1, 2 or 5 times a power of ten.
 *
 * Keeps chart gridline labels on clean, readable values (e.g. 100 not 100.05).
 *
 * @param {number} v Raw maximum.
 * @return {number} Rounded-up ceiling (always > 0).
 */
function niceCeil( v ) {
	if ( ! ( v > 0 ) ) {
		return 1;
	}
	const pow = Math.pow( 10, Math.floor( Math.log10( v ) ) );
	const frac = v / pow;
	let nice;
	if ( frac <= 1 ) {
		nice = 1;
	} else if ( frac <= 2 ) {
		nice = 2;
	} else if ( frac <= 5 ) {
		nice = 5;
	} else {
		nice = 10;
	}
	return nice * pow;
}

/**
 * Format a Date as a local 'Y-m-d' string (no UTC shift, no time component).
 *
 * @param {Date} d Date to format.
 * @return {string} 'YYYY-MM-DD'.
 */
function ymd( d ) {
	const y = d.getFullYear();
	const m = String( d.getMonth() + 1 ).padStart( 2, '0' );
	const day = String( d.getDate() ).padStart( 2, '0' );
	return `${ y }-${ m }-${ day }`;
}

/**
 * Derive the date window for a period selection, matching the REST contract.
 *
 * The /timeseries route treats the upper bound as half-open (a bare 'Y-m-d'
 * `to` is expanded to the start of the following day), so we send the last
 * day of the period as `to` and the first day as `from`. Without this the
 * route silently defaults to the current calendar month and the chart ignores
 * the period switcher.
 *
 * @param {string} period 'day' (Today) or 'month' (This month).
 * @return {{from: string, to: string}} Inclusive date bounds in 'Y-m-d'.
 */
function periodRange( period ) {
	const now = new Date();

	if ( period === 'day' ) {
		const today = ymd( now );
		return { from: today, to: today };
	}

	// 'month': first through last day of the current calendar month.
	const first = new Date( now.getFullYear(), now.getMonth(), 1 );
	const last = new Date( now.getFullYear(), now.getMonth() + 1, 0 );
	return { from: ymd( first ), to: ymd( last ) };
}

/**
 * Sum the three token buckets of a counter/breakdown row.
 *
 * @param {Object} row Row with input/output/thinking token fields.
 * @return {number} Total tokens.
 */
function totalTokens( row ) {
	return (
		( Number( row.input_tokens ) || 0 ) +
		( Number( row.output_tokens ) || 0 ) +
		( Number( row.thinking_tokens ) || 0 )
	);
}

/**
 * Derive an attribution-confidence descriptor from a scope key.
 *
 * The counters table does not store a per-row confidence value, so we infer it
 * from the scope key: the reserved unknown / system buckets are low-confidence,
 * everything else is treated as attributed.
 *
 * @param {string} scopeType Scope type ('plugin'|'user'|'role').
 * @param {string} scopeKey  Scope key value.
 * @return {{level: string, label: string}} Badge descriptor.
 */
function confidenceFor( scopeType, scopeKey ) {
	if ( scopeKey === UNKNOWN_SLUG ) {
		return {
			level: 'low',
			label: __( 'Unattributed', 'wp-ai-rate-limiter' ),
		};
	}
	if ( scopeType === 'user' && String( scopeKey ) === SYSTEM_USER ) {
		return { level: 'medium', label: __( 'System', 'wp-ai-rate-limiter' ) };
	}
	return { level: 'high', label: __( 'Attributed', 'wp-ai-rate-limiter' ) };
}

/**
 * Human label for a scope key (pretty-prints the reserved buckets).
 *
 * @param {string} scopeType Scope type.
 * @param {string} scopeKey  Scope key value.
 * @return {string} Display label.
 */
function scopeLabel( scopeType, scopeKey ) {
	if ( scopeKey === UNKNOWN_SLUG ) {
		return __( 'Unknown / unattributed', 'wp-ai-rate-limiter' );
	}
	if ( scopeType === 'user' && String( scopeKey ) === SYSTEM_USER ) {
		return __( 'System (cron / REST)', 'wp-ai-rate-limiter' );
	}
	if ( scopeType === 'user' ) {
		// eslint-disable-next-line @wordpress/i18n-translator-comments
		return `${ __( 'User #', 'wp-ai-rate-limiter' ) }${ scopeKey }`;
	}
	return String( scopeKey );
}

/**
 * Render a scope name. For user rows enriched with a username, shows
 * "User #1 (filip)" with the username linked to the profile when an edit URL
 * is available; otherwise falls back to the plain scopeLabel().
 *
 * @param {Object} props           Props.
 * @param {string} props.scopeType Scope type.
 * @param {Object} props.row       The usage row (may carry user_login/edit_url).
 * @return {JSX.Element} Name node.
 */
function ScopeName( { scopeType, row } ) {
	const base = scopeLabel( scopeType, row.scope_key );

	// User rows: "User #1 (filip)" with the username linked to the profile.
	if ( scopeType === 'user' && row.user_login ) {
		const name = row.edit_url ? (
			<a href={ row.edit_url }>{ row.user_login }</a>
		) : (
			row.user_login
		);
		return (
			<>
				{ base } ({ name })
			</>
		);
	}

	// Role rows: the human role name linked to the filtered users list.
	if ( scopeType === 'role' && row.role_label ) {
		return row.list_url ? (
			<a href={ row.list_url }>{ row.role_label }</a>
		) : (
			<>{ row.role_label }</>
		);
	}

	return <>{ base }</>;
}

/* -------------------------------------------------------------------------- */
/* Small presentational components                                            */
/* -------------------------------------------------------------------------- */

/**
 * A confidence badge pill.
 *
 * @param {Object} props       Component props.
 * @param {string} props.level 'high'|'medium'|'low'.
 * @param {string} props.label Visible text.
 * @return {JSX.Element} Badge.
 */
function ConfidenceBadge( { level, label } ) {
	return (
		<span className={ `wp-aiut-badge wp-aiut-badge--${ level }` }>
			{ label }
		</span>
	);
}

/**
 * A single totals card.
 *
 * @param {Object} props        Component props.
 * @param {string} props.label  Card label.
 * @param {string} props.value  Formatted value.
 * @param {string} [props.hint] Optional sub-text.
 * @return {JSX.Element} Card.
 */
function StatCard( { label, value, hint } ) {
	return (
		<Card size="small" className="wp-aiut-stat">
			<CardBody>
				<div className="wp-aiut-stat__label">{ label }</div>
				<div className="wp-aiut-stat__value">{ value }</div>
				{ hint ? (
					<div className="wp-aiut-stat__hint">{ hint }</div>
				) : null }
			</CardBody>
		</Card>
	);
}

/**
 * Horizontal ranked bar + table for a set of scope rows.
 *
 * @param {Object} props            Component props.
 * @param {string} props.scopeType  Scope type ('plugin'|'user'|'role').
 * @param {Array}  props.rows       Counter rows.
 * @param {string} props.nameHeader Column header for the name column.
 * @return {JSX.Element} Ranked bar+table.
 */
function RankedBreakdown( { scopeType, rows, nameHeader } ) {
	const maxCost = useMemo( () => {
		return rows.reduce(
			( max, r ) => Math.max( max, Number( r.est_cost_micros ) || 0 ),
			0
		);
	}, [ rows ] );

	if ( ! rows.length ) {
		return (
			<p className="wp-aiut-muted">
				{ __(
					'No usage recorded for this period yet.',
					'wp-ai-rate-limiter'
				) }
			</p>
		);
	}

	return (
		<table className="wp-aiut-table widefat striped">
			<thead>
				<tr>
					<th>{ nameHeader }</th>
					<th className="wp-aiut-num">
						{ __( 'Requests', 'wp-ai-rate-limiter' ) }
					</th>
					<th className="wp-aiut-num">
						{ __( 'Tokens', 'wp-ai-rate-limiter' ) }
					</th>
					<th className="wp-aiut-num">
						{ __( 'Est. cost', 'wp-ai-rate-limiter' ) }
					</th>
					<th>{ __( 'Confidence', 'wp-ai-rate-limiter' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row ) => {
					const cost = Number( row.est_cost_micros ) || 0;
					const pct =
						maxCost > 0
							? Math.round( ( cost / maxCost ) * 100 )
							: 0;
					const conf = confidenceFor( scopeType, row.scope_key );
					return (
						<tr key={ `${ scopeType }-${ row.scope_key }` }>
							<td>
								<div className="wp-aiut-bar-cell">
									<span className="wp-aiut-bar-cell__name">
										<ScopeName
											scopeType={ scopeType }
											row={ row }
										/>
									</span>
									<span className="wp-aiut-bar-track">
										<span
											className={ `wp-aiut-bar-fill wp-aiut-bar-fill--${ conf.level }` }
											style={ { width: `${ pct }%` } }
										/>
									</span>
								</div>
							</td>
							<td className="wp-aiut-num">
								{ formatNumber( row.requests ) }
							</td>
							<td className="wp-aiut-num">
								{ formatNumber( totalTokens( row ) ) }
							</td>
							<td className="wp-aiut-num wp-aiut-cost">
								{ formatMoney( cost ) }
							</td>
							<td>
								<ConfidenceBadge
									level={ conf.level }
									label={ conf.label }
								/>
							</td>
						</tr>
					);
				} ) }
			</tbody>
		</table>
	);
}

/**
 * Tiny inline-SVG line + area chart for a daily timeseries.
 *
 * @param {Object} props        Component props.
 * @param {Array}  props.series Array of { day, value, requests }.
 * @param {string} props.metric 'cost'|'tokens' (controls value formatting).
 * @return {JSX.Element} SVG chart.
 */
function TimeSeriesChart( { series, metric } ) {
	const width = 720;
	const height = 220;
	const padL = 56; // room for y-axis labels
	const padR = 16;
	const padT = 16;
	const padB = 28; // room for x-axis labels

	const fmt = ( v ) =>
		metric === 'cost' ? formatMoney( v ) : formatNumber( v );
	const single = series.length === 1;

	const geom = useMemo( () => {
		if ( ! series.length ) {
			return null;
		}

		const innerW = width - padL - padR;
		const innerH = height - padT - padB;
		const rawMax = series.reduce(
			( m, d ) => Math.max( m, Number( d.value ) || 0 ),
			0
		);
		// Round the axis ceiling up to a "nice" number (1, 2 or 5 × 10ⁿ) so the
		// gridline labels land on clean, readable values with headroom above the
		// peak. For the cost metric values are tiny fractions, so the nice-number
		// rounding works in micros-equivalent space just the same.
		const maxVal = niceCeil( rawMax > 0 ? rawMax * 1.05 : 1 );

		const x = ( i ) => {
			if ( series.length === 1 ) {
				return padL + innerW / 2;
			}
			return padL + ( innerW / ( series.length - 1 ) ) * i;
		};
		const y = ( v ) =>
			padT + innerH - ( ( Number( v ) || 0 ) / maxVal ) * innerH;

		const points = series.map( ( d, i ) => ( {
			x: x( i ),
			y: y( d.value ),
			day: d.day,
			value: Number( d.value ) || 0,
			requests: d.requests,
		} ) );

		// Three y gridlines: 0, mid, max.
		const yTicks = [ 0, maxVal / 2, maxVal ].map( ( v ) => ( {
			value: v,
			y: y( v ),
		} ) );

		return { points, yTicks, baseY: padT + innerH, innerW, maxVal };
	}, [ series ] );

	if ( ! geom ) {
		return (
			<p className="wp-aiut-muted">
				{ __(
					'No data points in this range yet.',
					'wp-ai-rate-limiter'
				) }
			</p>
		);
	}

	const { points, yTicks, baseY } = geom;

	const linePath = points
		.map(
			( p, i ) =>
				`${ i === 0 ? 'M' : 'L' } ${ p.x.toFixed( 1 ) } ${ p.y.toFixed(
					1
				) }`
		)
		.join( ' ' );

	const areaPath =
		`M ${ points[ 0 ].x.toFixed( 1 ) } ${ baseY.toFixed( 1 ) } ` +
		points
			.map( ( p ) => `L ${ p.x.toFixed( 1 ) } ${ p.y.toFixed( 1 ) }` )
			.join( ' ' ) +
		` L ${ points[ points.length - 1 ].x.toFixed( 1 ) } ${ baseY.toFixed(
			1
		) } Z`;

	return (
		<svg
			className="wp-aiut-chart"
			viewBox={ `0 0 ${ width } ${ height }` }
			role="img"
			aria-label={ __( 'Usage over time', 'wp-ai-rate-limiter' ) }
			preserveAspectRatio="xMidYMid meet"
		>
			{ /* Horizontal gridlines + y-axis value labels. */ }
			{ yTicks.map( ( t, i ) => (
				<g key={ `tick-${ i }` }>
					<line
						className="wp-aiut-chart__grid"
						x1={ padL }
						x2={ width - padR }
						y1={ t.y }
						y2={ t.y }
					/>
					<text
						className="wp-aiut-chart__axis"
						x={ padL - 8 }
						y={ t.y + 4 }
						textAnchor="end"
					>
						{ fmt( t.value ) }
					</text>
				</g>
			) ) }

			{ /* A line/area needs at least two points; a lone day shows just a marker. */ }
			{ ! single && (
				<>
					<path className="wp-aiut-chart__area" d={ areaPath } />
					<path className="wp-aiut-chart__line" d={ linePath } />
				</>
			) }

			{ points.map( ( p ) => (
				<g key={ p.day }>
					{ /* Drop a guide line from the marker to the x-axis. */ }
					<line
						className="wp-aiut-chart__stem"
						x1={ p.x }
						x2={ p.x }
						y1={ p.y }
						y2={ baseY }
					/>
					<circle
						className="wp-aiut-chart__dot"
						cx={ p.x }
						cy={ p.y }
						r={ single ? 5 : 3 }
					>
						<title>{ `${ p.day }: ${ fmt(
							p.value
						) } (${ formatNumber( p.requests ) } req)` }</title>
					</circle>
					{ single && (
						<text
							className="wp-aiut-chart__label"
							x={ p.x }
							y={ p.y - 12 }
							textAnchor="middle"
						>
							{ fmt( p.value ) }
						</text>
					) }
					<text
						className="wp-aiut-chart__axis"
						x={ p.x }
						y={ baseY + 18 }
						textAnchor="middle"
					>
						{ p.day }
					</text>
				</g>
			) ) }
		</svg>
	);
}

/* -------------------------------------------------------------------------- */
/* Empty state                                                                */
/* -------------------------------------------------------------------------- */

/**
 * Empty state explaining attribution and the self-ID hook.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.config Runtime config (carries the attribute hook name).
 * @return {JSX.Element} Empty state card.
 */
function EmptyState( { config } ) {
	const hook =
		( config && config.attribute ) || 'wp_ai_rate_limiter_attribute';
	return (
		<Card className="wp-aiut-empty">
			<CardBody>
				<h2>
					{ __( 'No AI usage recorded yet', 'wp-ai-rate-limiter' ) }
				</h2>
				<p>
					{ __(
						'Once a plugin or theme makes a WordPress AI Client request, it appears here attributed to the originating plugin and the current user.',
						'wp-ai-rate-limiter'
					) }
				</p>
				<h3>{ __( 'How attribution works', 'wp-ai-rate-limiter' ) }</h3>
				<ul className="wp-aiut-list">
					<li>
						{ __(
							'Self-identification (most reliable): a plugin announces itself before its prompt.',
							'wp-ai-rate-limiter'
						) }
					</li>
					<li>
						{ __(
							'Backtrace mapping (medium): we map the calling file back to its plugin slug.',
							'wp-ai-rate-limiter'
						) }
					</li>
					<li>
						{ __(
							'Unknown bucket (fallback): anything we cannot name is still tracked, never dropped.',
							'wp-ai-rate-limiter'
						) }
					</li>
				</ul>
				<Tip>
					{ __(
						'Good-citizen plugins can self-identify with:',
						'wp-ai-rate-limiter'
					) }
				</Tip>
				<pre className="wp-aiut-code">{ `do_action( '${ hook }', 'my-plugin-slug' );` }</pre>
			</CardBody>
		</Card>
	);
}

/* -------------------------------------------------------------------------- */
/* Main app                                                                    */
/* -------------------------------------------------------------------------- */

/**
 * The dashboard application.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.config Runtime config from window.wpAiUsageTracker.
 * @return {JSX.Element} Dashboard.
 */
export default function App( { config } ) {
	// Period: 'month' (This month) or 'day' (Today).
	const [ period, setPeriod ] = useState( 'month' );
	// User/role toggle for section 3.
	const [ peopleScope, setPeopleScope ] = useState( 'user' );
	// Cost/tokens toggle for the over-time chart.
	const [ chartMetric, setChartMetric ] = useState( 'cost' );

	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const [ totals, setTotals ] = useState( null );
	const [ plugins, setPlugins ] = useState( [] );
	const [ people, setPeople ] = useState( [] );
	const [ series, setSeries ] = useState( [] );

	/**
	 * Fetch every data slice for the current selections.
	 */
	const load = useCallback( async () => {
		setLoading( true );
		setError( null );

		try {
			const { from, to } = periodRange( period );
			const ns = 'wp-ai-rate-limiter/v1';
			const [ totalsRes, pluginRes, peopleRes, seriesRes ] =
				await Promise.all( [
					apiFetch( { path: `${ ns }/totals?period=${ period }` } ),
					apiFetch( {
						path: `${ ns }/usage?scope_type=plugin&period=${ period }`,
					} ),
					apiFetch( {
						path: `${ ns }/usage?scope_type=${ peopleScope }&period=${ period }`,
					} ),
					apiFetch( {
						path: `${ ns }/timeseries?metric=${ chartMetric }&from=${ from }&to=${ to }`,
					} ),
				] );

			setTotals( totalsRes || null );
			setPlugins( ( pluginRes && pluginRes.rows ) || [] );
			setPeople( ( peopleRes && peopleRes.rows ) || [] );
			setSeries( ( seriesRes && seriesRes.series ) || [] );
		} catch ( e ) {
			setError(
				( e && e.message ) ||
					__( 'Failed to load usage data.', 'wp-ai-rate-limiter' )
			);
		} finally {
			setLoading( false );
		}
	}, [ period, peopleScope, chartMetric ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const totalRow = ( totals && totals.totals ) || {};
	const hasAnyData =
		( Number( totalRow.requests ) || 0 ) > 0 ||
		plugins.length > 0 ||
		people.length > 0;

	const periodLabel =
		period === 'day'
			? __( 'Today', 'wp-ai-rate-limiter' )
			: __( 'This month', 'wp-ai-rate-limiter' );

	return (
		<div className="wp-aiut">
			<Flex
				className="wp-aiut-toolbar"
				justify="space-between"
				align="center"
			>
				<FlexItem>
					<ToggleGroupControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						hideLabelFromVision
						isBlock
						label={ __( 'Period', 'wp-ai-rate-limiter' ) }
						value={ period }
						onChange={ ( value ) => setPeriod( value ) }
					>
						<ToggleGroupControlOption
							value="month"
							label={ __( 'This month', 'wp-ai-rate-limiter' ) }
						/>
						<ToggleGroupControlOption
							value="day"
							label={ __( 'Today', 'wp-ai-rate-limiter' ) }
						/>
					</ToggleGroupControl>
				</FlexItem>
				<FlexItem>
					<Button
						variant="tertiary"
						onClick={ load }
						disabled={ loading }
					>
						{ __( 'Refresh', 'wp-ai-rate-limiter' ) }
					</Button>
				</FlexItem>
			</Flex>

			{ error ? (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) : null }

			{ loading ? (
				<div className="wp-aiut-loading">
					<Spinner />
					<span>
						{ __( 'Loading usage…', 'wp-ai-rate-limiter' ) }
					</span>
				</div>
			) : null }

			{ ! loading && ! error && ! hasAnyData ? (
				<EmptyState config={ config } />
			) : null }

			{ ! loading && ! error && hasAnyData ? (
				<>
					{ /* Section 1 — Totals strip + breakdown */ }
					<section className="wp-aiut-section">
						<Flex className="wp-aiut-stats" gap={ 4 } wrap>
							<FlexItem isBlock>
								<StatCard
									label={ __(
										'Estimated spend',
										'wp-ai-rate-limiter'
									) }
									value={ formatMoney(
										totalRow.est_cost_micros
									) }
									hint={ periodLabel }
								/>
							</FlexItem>
							<FlexItem isBlock>
								<StatCard
									label={ __(
										'Requests',
										'wp-ai-rate-limiter'
									) }
									value={ formatNumber( totalRow.requests ) }
									hint={ periodLabel }
								/>
							</FlexItem>
							<FlexItem isBlock>
								<StatCard
									label={ __(
										'Total tokens',
										'wp-ai-rate-limiter'
									) }
									value={ formatNumber(
										totalTokens( totalRow )
									) }
									hint={ `${ formatNumber(
										totalRow.input_tokens
									) } ${ __(
										'in',
										'wp-ai-rate-limiter'
									) } / ${ formatNumber(
										totalRow.output_tokens
									) } ${ __(
										'out',
										'wp-ai-rate-limiter'
									) }` }
								/>
							</FlexItem>
						</Flex>

						<Card className="wp-aiut-card">
							<CardHeader>
								<strong>
									{ __(
										'Breakdown by provider & model',
										'wp-ai-rate-limiter'
									) }
								</strong>
							</CardHeader>
							<CardBody>
								<ProviderModelBreakdown totals={ totals } />
							</CardBody>
						</Card>
					</section>

					{ /* Section 2 — Spend per plugin (headline, visual primacy) */ }
					<section className="wp-aiut-section wp-aiut-section--headline">
						<Card className="wp-aiut-card">
							<CardHeader>
								<strong>
									{ __(
										'Spend per plugin',
										'wp-ai-rate-limiter'
									) }
								</strong>
								<span className="wp-aiut-muted">
									{ __(
										'Ranked by estimated cost',
										'wp-ai-rate-limiter'
									) }
								</span>
							</CardHeader>
							<CardBody>
								<RankedBreakdown
									scopeType="plugin"
									rows={ plugins }
									nameHeader={ __(
										'Plugin',
										'wp-ai-rate-limiter'
									) }
								/>
							</CardBody>
						</Card>
					</section>

					{ /* Section 3 — Spend per user / role */ }
					<section className="wp-aiut-section">
						<Card className="wp-aiut-card">
							<CardHeader>
								<strong>
									{ __(
										'Spend per person',
										'wp-ai-rate-limiter'
									) }
								</strong>
								<ToggleGroupControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									hideLabelFromVision
									isBlock
									label={ __(
										'Group by',
										'wp-ai-rate-limiter'
									) }
									value={ peopleScope }
									onChange={ ( value ) =>
										setPeopleScope( value )
									}
								>
									<ToggleGroupControlOption
										value="user"
										label={ __(
											'By user',
											'wp-ai-rate-limiter'
										) }
									/>
									<ToggleGroupControlOption
										value="role"
										label={ __(
											'By role',
											'wp-ai-rate-limiter'
										) }
									/>
								</ToggleGroupControl>
							</CardHeader>
							<CardBody>
								<RankedBreakdown
									scopeType={ peopleScope }
									rows={ people }
									nameHeader={
										peopleScope === 'user'
											? __( 'User', 'wp-ai-rate-limiter' )
											: __( 'Role', 'wp-ai-rate-limiter' )
									}
								/>
							</CardBody>
						</Card>
					</section>

					{ /* Section 4 — Usage over time */ }
					<section className="wp-aiut-section">
						<Card className="wp-aiut-card">
							<CardHeader>
								<strong>
									{ __(
										'Usage over time',
										'wp-ai-rate-limiter'
									) }
								</strong>
								<ToggleGroupControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									hideLabelFromVision
									isBlock
									label={ __(
										'Metric',
										'wp-ai-rate-limiter'
									) }
									value={ chartMetric }
									onChange={ ( value ) =>
										setChartMetric( value )
									}
								>
									<ToggleGroupControlOption
										value="cost"
										label={ __(
											'Cost',
											'wp-ai-rate-limiter'
										) }
									/>
									<ToggleGroupControlOption
										value="tokens"
										label={ __(
											'Tokens',
											'wp-ai-rate-limiter'
										) }
									/>
								</ToggleGroupControl>
							</CardHeader>
							<CardBody>
								<TimeSeriesChart
									series={ series }
									metric={ chartMetric }
								/>
							</CardBody>
						</Card>
					</section>
				</>
			) : null }

			{ /* Limits management is always available, even with no usage yet. */ }
			{ ! loading && ! error ? <Limits /> : null }
		</div>
	);
}

/**
 * Provider/model breakdown sub-section under the totals strip.
 *
 * Flags rows that are likely estimated (zero cost but non-zero tokens) so the
 * honesty-first confidence signal carries through the breakdown too.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.totals Totals payload from /totals.
 * @return {JSX.Element} Breakdown lists.
 */
function ProviderModelBreakdown( { totals } ) {
	const byProvider = ( totals && totals.by_provider ) || [];
	const byModel = ( totals && totals.by_model ) || [];

	if ( ! byProvider.length && ! byModel.length ) {
		return (
			<p className="wp-aiut-muted">
				{ __(
					'No provider data for this period.',
					'wp-ai-rate-limiter'
				) }
			</p>
		);
	}

	return (
		<div className="wp-aiut-breakdown">
			<div>
				<h4>{ __( 'By provider', 'wp-ai-rate-limiter' ) }</h4>
				<ul className="wp-aiut-kv">
					{ byProvider.map( ( r ) => {
						const estimated =
							( Number( r.est_cost_micros ) || 0 ) === 0 &&
							totalTokens( r ) > 0;
						return (
							<li key={ r.provider }>
								<span>
									{ r.provider ||
										__(
											'(unknown)',
											'wp-ai-rate-limiter'
										) }
								</span>
								<span className="wp-aiut-cost">
									{ formatMoney( r.est_cost_micros ) }
									{ estimated ? (
										<ConfidenceBadge
											level="low"
											label={ __(
												'est.',
												'wp-ai-rate-limiter'
											) }
										/>
									) : null }
								</span>
							</li>
						);
					} ) }
				</ul>
			</div>
			<div>
				<h4>{ __( 'By model', 'wp-ai-rate-limiter' ) }</h4>
				<ul className="wp-aiut-kv">
					{ byModel.map( ( r ) => (
						<li key={ `${ r.provider }/${ r.model }` }>
							<span>{ `${ r.provider || '?' } / ${
								r.model || '?'
							}` }</span>
							<span className="wp-aiut-cost">
								{ formatMoney( r.est_cost_micros ) }
							</span>
						</li>
					) ) }
				</ul>
			</div>
		</div>
	);
}
