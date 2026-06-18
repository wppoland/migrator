/* Migrator admin — resumable export driver. Vanilla JS, no dependencies. */
( function () {
	'use strict';

	var data = window.migratorData || {};
	var i18n = data.i18n || {};

	var startBtn = document.getElementById( 'migrator-export-start' );
	if ( ! startBtn ) {
		return;
	}

	var progress = document.getElementById( 'migrator-export-progress' );
	var bar = progress.querySelector( '.migrator-progress__bar' );
	var fill = document.getElementById( 'migrator-export-fill' );
	var status = document.getElementById( 'migrator-export-status' );
	var result = document.getElementById( 'migrator-export-result' );
	var resultMsg = document.getElementById( 'migrator-export-result-msg' );
	var download = document.getElementById( 'migrator-export-download' );

	function post( action ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', data.nonce );
		return fetch( data.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function setProgress( percent, message ) {
		fill.style.width = percent + '%';
		bar.setAttribute( 'aria-valuenow', String( percent ) );
		status.textContent = message + ' ' + percent + '%';
	}

	function fail( message ) {
		progress.hidden = true;
		result.hidden = false;
		resultMsg.textContent = message || i18n.failed || 'Export failed.';
		resultMsg.classList.add( 'is-error' );
		download.hidden = true;
		startBtn.disabled = false;
	}

	function finish( job ) {
		setProgress( 100, i18n.done || 'Backup ready.' );
		result.hidden = false;
		resultMsg.classList.remove( 'is-error' );
		resultMsg.textContent = ( i18n.done || 'Backup ready.' ) + ' ' + ( job.fileName || '' ) + ' (' + ( job.size || '' ) + ')';
		download.hidden = false;
		download.setAttribute( 'href', job.download );
		download.setAttribute( 'download', job.fileName || 'backup.migrator' );
		startBtn.disabled = false;
	}

	function loop() {
		post( 'migrator_export_step' ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				fail( res && res.data ? res.data.message : '' );
				return;
			}
			var job = res.data;
			setProgress( job.percent, i18n.archiving || 'Archiving files…' );
			if ( job.done ) {
				finish( job );
			} else {
				loop();
			}
		} ).catch( function () {
			fail();
		} );
	}

	startBtn.addEventListener( 'click', function () {
		startBtn.disabled = true;
		result.hidden = true;
		resultMsg.classList.remove( 'is-error' );
		progress.hidden = false;
		setProgress( 0, i18n.preparing || 'Preparing…' );

		post( 'migrator_export_start' ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				fail( res && res.data ? res.data.message : '' );
				return;
			}
			loop();
		} ).catch( function () {
			fail();
		} );
	} );
}() );
