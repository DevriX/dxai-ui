import { Fragment } from '@wordpress/element';
import './page.css';

/*
 * The frame of every screen: the page's title the way wp-admin titles its screens, a breadcrumb when the page is inside
 * another (Designs › Semper Dry), a line of facts under the title, and the page's actions on the right. WordPress draws
 * the menu and the admin bar; this draws nothing twice.
 */
export default function Page( { title, crumbs = [], meta = null, actions = null, className = '', children } ) {
	return (
		<div className={ 'dxui-page' + ( className ? ' ' + className : '' ) }>
			{ crumbs.length > 0 && (
				<nav className="dxui-page__crumbs" aria-label="Breadcrumb">
					{ crumbs.map( ( c, i ) => (
						<Fragment key={ i }>
							{ i > 0 && <span className="dxui-page__crumb-sep" aria-hidden="true">›</span> }
							{ c.href ? <a href={ c.href }>{ c.label }</a> : <span>{ c.label }</span> }
						</Fragment>
					) ) }
				</nav>
			) }
			<div className="dxui-page__head">
				<div className="dxui-page__title">
					<h1 className="wp-heading-inline">{ title }</h1>
					{ meta && <p className="dxui-page__meta">{ meta }</p> }
				</div>
				{ actions && <div className="dxui-page__actions">{ actions }</div> }
			</div>
			{ children }
		</div>
	);
}
