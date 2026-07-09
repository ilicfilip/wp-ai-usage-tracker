/**
 * Limits management section (Phase 2).
 *
 * Lists configured usage limits and lets an admin create/edit/delete them via
 * the 'wp-aiut/v1/limits' REST routes. Kept in its own module to
 * keep the dashboard component readable.
 *
 * @package
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import {
	Card,
	CardHeader,
	CardBody,
	Button,
	Spinner,
	Notice,
	SelectControl,
	TextControl,
	CheckboxControl,
	Flex,
	FlexItem,
} from '@wordpress/components';

const SCOPE_TYPES = [
	{ label: __( 'Plugin', 'wp-aiut' ), value: 'plugin' },
	{ label: __( 'User', 'wp-aiut' ), value: 'user' },
	{ label: __( 'Role', 'wp-aiut' ), value: 'role' },
	{ label: __( 'Model', 'wp-aiut' ), value: 'model' },
	{ label: __( 'Global (all)', 'wp-aiut' ), value: 'global' },
];

const LIMIT_TYPES = [
	{ label: __( 'Cost (USD)', 'wp-aiut' ), value: 'cost' },
	{ label: __( 'Tokens', 'wp-aiut' ), value: 'tokens' },
	{ label: __( 'Requests', 'wp-aiut' ), value: 'requests' },
];

const PERIODS = [
	{ label: __( 'Per month', 'wp-aiut' ), value: 'month' },
	{ label: __( 'Per day', 'wp-aiut' ), value: 'day' },
];

const ENFORCEMENTS = [
	{ label: __( 'Off (track only)', 'wp-aiut' ), value: 'off' },
	{ label: __( 'Soft (alert)', 'wp-aiut' ), value: 'soft' },
	{ label: __( 'Hard (block)', 'wp-aiut' ), value: 'hard' },
];

const CONFIDENCES = [
	{
		label: __( 'Medium — backtrace or better', 'wp-aiut' ),
		value: 'medium',
	},
	{
		label: __( 'High — self-identified only', 'wp-aiut' ),
		value: 'high',
	},
];

const BLANK = {
	scope_type: 'plugin',
	scope_key: '*',
	limit_type: 'cost',
	period_kind: 'month',
	threshold: '',
	enforcement: 'soft',
	min_confidence: 'medium',
	alert_80: true,
	alert_100: true,
	enabled: true,
};

/**
 * Format a stored threshold for display (cost is micros → USD).
 *
 * @param {Object} limit Limit row.
 * @return {string} Human threshold.
 */
function formatThreshold( limit ) {
	if ( limit.limit_type === 'cost' ) {
		const dollars = ( Number( limit.threshold ) || 0 ) / 1e6;
		return new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency: 'USD',
			maximumFractionDigits: dollars < 1 ? 4 : 2,
		} ).format( dollars );
	}
	return new Intl.NumberFormat().format( Number( limit.threshold ) || 0 );
}

/**
 * Convert a user-entered threshold to the stored unit (cost dollars → micros).
 *
 * @param {string} type  Limit type.
 * @param {string} value Raw input value.
 * @return {number} Stored integer threshold.
 */
function toStored( type, value ) {
	const num = Number( value ) || 0;
	return type === 'cost' ? Math.round( num * 1e6 ) : Math.round( num );
}

/**
 * Convert a stored threshold back to an editable value (micros → dollars).
 *
 * @param {string} type  Limit type.
 * @param {number} value Stored value.
 * @return {string} Editable string.
 */
function fromStored( type, value ) {
	const num = Number( value ) || 0;
	return type === 'cost' ? String( num / 1e6 ) : String( num );
}

/**
 * Map an enforcement mode to a confidence-badge severity level for colouring.
 *
 * Reuses the existing badge palette: hard = red, soft = amber, off = green.
 *
 * @param {string} enforcement Enforcement mode.
 * @return {string} Badge level ('low'|'medium'|'high').
 */
function enforcementBadgeLevel( enforcement ) {
	if ( enforcement === 'hard' ) {
		return 'low';
	}
	if ( enforcement === 'soft' ) {
		return 'medium';
	}
	return 'high';
}

/**
 * The Limits management card.
 *
 * @return {JSX.Element} Section.
 */
export default function Limits() {
	const [ limits, setLimits ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ editing, setEditing ] = useState( null ); // BLANK-shaped draft or null
	const [ saving, setSaving ] = useState( false );

	const load = useCallback( async () => {
		setLoading( true );
		setError( '' );
		try {
			const res = await apiFetch( {
				path: 'wp-aiut/v1/limits',
			} );
			setLimits( ( res && res.limits ) || [] );
		} catch ( e ) {
			setError( e.message || __( 'Failed to load limits.', 'wp-aiut' ) );
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const startCreate = () => setEditing( { ...BLANK } );

	const startEdit = ( limit ) =>
		setEditing( {
			...limit,
			threshold: fromStored( limit.limit_type, limit.threshold ),
			alert_80: !! limit.alert_80,
			alert_100: !! limit.alert_100,
			enabled: !! limit.enabled,
		} );

	const cancel = () => setEditing( null );

	const save = async () => {
		setSaving( true );
		setError( '' );
		try {
			const payload = {
				...editing,
				threshold: toStored( editing.limit_type, editing.threshold ),
			};
			const isUpdate = !! editing.id;
			await apiFetch( {
				path: isUpdate
					? `wp-aiut/v1/limits/${ editing.id }`
					: 'wp-aiut/v1/limits',
				method: isUpdate ? 'PUT' : 'POST',
				data: payload,
			} );
			setEditing( null );
			await load();
		} catch ( e ) {
			setError(
				e.message || __( 'Failed to save the limit.', 'wp-aiut' )
			);
		} finally {
			setSaving( false );
		}
	};

	const remove = async ( id ) => {
		// eslint-disable-next-line no-alert
		const confirmed = window.confirm(
			__( 'Delete this limit?', 'wp-aiut' )
		);
		if ( ! confirmed ) {
			return;
		}
		setError( '' );
		try {
			await apiFetch( {
				path: `wp-aiut/v1/limits/${ id }`,
				method: 'DELETE',
			} );
			await load();
		} catch ( e ) {
			setError(
				e.message || __( 'Failed to delete the limit.', 'wp-aiut' )
			);
		}
	};

	const set = ( key ) => ( value ) =>
		setEditing( ( prev ) => ( { ...prev, [ key ]: value } ) );

	return (
		<section className="wp-aiut-section">
			<Card className="wp-aiut-card">
				<CardHeader>
					<strong>{ __( 'Limits', 'wp-aiut' ) }</strong>
					{ ! editing && (
						<Button
							variant="primary"
							__next40pxDefaultSize
							onClick={ startCreate }
						>
							{ __( 'Add limit', 'wp-aiut' ) }
						</Button>
					) }
				</CardHeader>
				<CardBody>
					{ error ? (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) : null }

					{ loading ? (
						<div className="wp-aiut-loading">
							<Spinner />
						</div>
					) : null }

					{ ! loading && editing ? (
						<LimitForm
							draft={ editing }
							set={ set }
							onSave={ save }
							onCancel={ cancel }
							saving={ saving }
						/>
					) : null }

					{ ! loading && ! editing && ! limits.length ? (
						<p className="wp-aiut-muted">
							{ __(
								'No limits configured. Add one to start capping usage. Until a hard limit exists, the plugin only tracks — it never blocks.',
								'wp-aiut'
							) }
						</p>
					) : null }

					{ ! loading && ! editing && limits.length ? (
						<LimitsTable
							limits={ limits }
							onEdit={ startEdit }
							onDelete={ remove }
						/>
					) : null }
				</CardBody>
			</Card>
		</section>
	);
}

/**
 * Read-only table of configured limits.
 *
 * @param {Object}   props          Props.
 * @param {Array}    props.limits   Limit rows.
 * @param {Function} props.onEdit   Edit handler.
 * @param {Function} props.onDelete Delete handler.
 * @return {JSX.Element} Table.
 */
function LimitsTable( { limits, onEdit, onDelete } ) {
	return (
		<table className="wp-aiut-table widefat striped">
			<thead>
				<tr>
					<th>{ __( 'Scope', 'wp-aiut' ) }</th>
					<th>{ __( 'Limit', 'wp-aiut' ) }</th>
					<th>{ __( 'Period', 'wp-aiut' ) }</th>
					<th>{ __( 'Mode', 'wp-aiut' ) }</th>
					<th>{ __( 'Status', 'wp-aiut' ) }</th>
					<th />
				</tr>
			</thead>
			<tbody>
				{ limits.map( ( l ) => (
					<tr key={ l.id }>
						<td>
							<span className="wp-aiut-scope-type">
								{ l.scope_type }
							</span>
							<span className="wp-aiut-scope-key">
								{ l.scope_key === '*'
									? __( 'all', 'wp-aiut' )
									: l.scope_key }
							</span>
						</td>
						<td>
							<strong>{ formatThreshold( l ) }</strong>{ ' ' }
							<span className="wp-aiut-muted">
								{ l.limit_type }
							</span>
						</td>
						<td className="wp-aiut-cap">{ l.period_kind }</td>
						<td>
							<span
								className={ `wp-aiut-badge wp-aiut-badge--${ enforcementBadgeLevel(
									l.enforcement
								) }` }
							>
								{ l.enforcement }
							</span>
						</td>
						<td>
							<span
								className={ `wp-aiut-badge wp-aiut-badge--${
									l.enabled ? 'high' : 'off'
								}` }
							>
								{ l.enabled
									? __( 'Enabled', 'wp-aiut' )
									: __( 'Disabled', 'wp-aiut' ) }
							</span>
						</td>
						<td className="wp-aiut-row-actions">
							<Button
								variant="secondary"
								size="small"
								onClick={ () => onEdit( l ) }
							>
								{ __( 'Edit', 'wp-aiut' ) }
							</Button>
							<Button
								variant="tertiary"
								size="small"
								isDestructive
								onClick={ () => onDelete( l.id ) }
							>
								{ __( 'Delete', 'wp-aiut' ) }
							</Button>
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * Create/edit form for a single limit.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.draft    Draft limit.
 * @param {Function} props.set      Curried field setter.
 * @param {Function} props.onSave   Save handler.
 * @param {Function} props.onCancel Cancel handler.
 * @param {boolean}  props.saving   Whether a save is in flight.
 * @return {JSX.Element} Form.
 */
function LimitForm( { draft, set, onSave, onCancel, saving } ) {
	const isHard = draft.enforcement === 'hard';
	const thresholdLabel =
		draft.limit_type === 'cost'
			? __( 'Threshold (USD)', 'wp-aiut' )
			: __( 'Threshold', 'wp-aiut' );

	return (
		<div className="wp-aiut-limit-form">
			<Flex wrap gap={ 4 } align="flex-start">
				<FlexItem>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Scope type', 'wp-aiut' ) }
						value={ draft.scope_type }
						options={ SCOPE_TYPES }
						onChange={ set( 'scope_type' ) }
					/>
				</FlexItem>
				<FlexItem>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Scope key', 'wp-aiut' ) }
						help={ __(
							'Slug / user ID / role, or * for all.',
							'wp-aiut'
						) }
						value={ draft.scope_key }
						onChange={ set( 'scope_key' ) }
					/>
				</FlexItem>
				<FlexItem>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Meter', 'wp-aiut' ) }
						value={ draft.limit_type }
						options={ LIMIT_TYPES }
						onChange={ set( 'limit_type' ) }
					/>
				</FlexItem>
				<FlexItem>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						label={ thresholdLabel }
						value={ draft.threshold }
						onChange={ set( 'threshold' ) }
					/>
				</FlexItem>
				<FlexItem>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Period', 'wp-aiut' ) }
						value={ draft.period_kind }
						options={ PERIODS }
						onChange={ set( 'period_kind' ) }
					/>
				</FlexItem>
				<FlexItem>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Enforcement', 'wp-aiut' ) }
						value={ draft.enforcement }
						options={ ENFORCEMENTS }
						onChange={ set( 'enforcement' ) }
					/>
				</FlexItem>
				{ isHard && (
					<FlexItem>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Min confidence to block', 'wp-aiut' ) }
							help={ __(
								'High = only block plugins that self-identify.',
								'wp-aiut'
							) }
							value={ draft.min_confidence }
							options={ CONFIDENCES }
							onChange={ set( 'min_confidence' ) }
						/>
					</FlexItem>
				) }
			</Flex>

			<div className="wp-aiut-limit-form__checks">
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'Alert at 80%', 'wp-aiut' ) }
					checked={ draft.alert_80 }
					onChange={ set( 'alert_80' ) }
				/>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'Alert at 100%', 'wp-aiut' ) }
					checked={ draft.alert_100 }
					onChange={ set( 'alert_100' ) }
				/>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'Enabled', 'wp-aiut' ) }
					checked={ draft.enabled }
					onChange={ set( 'enabled' ) }
				/>
			</div>

			<Flex justify="flex-start" gap={ 2 }>
				<FlexItem>
					<Button
						variant="primary"
						__next40pxDefaultSize
						isBusy={ saving }
						disabled={ saving }
						onClick={ onSave }
					>
						{ __( 'Save limit', 'wp-aiut' ) }
					</Button>
				</FlexItem>
				<FlexItem>
					<Button
						variant="tertiary"
						__next40pxDefaultSize
						onClick={ onCancel }
					>
						{ __( 'Cancel', 'wp-aiut' ) }
					</Button>
				</FlexItem>
			</Flex>
		</div>
	);
}
