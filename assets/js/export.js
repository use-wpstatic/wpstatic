( function ( $, window ) {
	'use strict';

	var CHUNK_TIMEOUT = 30000;
	var AUTO_RESUME_MAX_ATTEMPTS = 8;
	var AUTO_RESUME_RETRY_DELAY = 1500;

	var state = {
		running: false,
		paused: false,
		recoveryMode: false,
		offline: false,
		offlineNoticeShown: false,
		autoResumeInProgress: false,
		autoResumeAttempts: 0,
		autoResumeTimer: null,
		inFlight: false,
		lastSequence: 0,
		processingTimer: null,
		watchdogTimer: null,
		pendingXHR: null
	};

	function formatLogMessage( text ) {
		return text;
	}

	function t( key, fallback ) {
		if (
			typeof wpstaticExportData !== 'undefined' &&
			wpstaticExportData &&
			Object.prototype.hasOwnProperty.call( wpstaticExportData, key ) &&
			wpstaticExportData[ key ]
		) {
			return String( wpstaticExportData[ key ] );
		}

		return fallback;
	}

	function appendLog( text ) {
		var $log = $( '#wpstatic-export-log' );
		if ( ! $log.length ) {
			return;
		}

		if ( $log.data( 'has-content' ) !== 1 ) {
			$log.text( '' );
			$log.data( 'has-content', 1 );
		}

		$log.append( document.createTextNode( formatLogMessage( text ) + '\n' ) );
		$log.scrollTop( $log[0].scrollHeight );
	}

	function appendHighlightedLog( text, type ) {
		var $log = $( '#wpstatic-export-log' );
		if ( ! $log.length ) {
			return;
		}

		if ( $log.data( 'has-content' ) !== 1 ) {
			$log.text( '' );
			$log.data( 'has-content', 1 );
		}

		var klass = 'wpstatic-log-line';
		if ( type === 'error' ) {
			klass += ' wpstatic-log-line-error';
		} else if ( type === 'success' ) {
			klass += ' wpstatic-log-line-success';
		}

		$log.append( $( '<div></div>' ).addClass( klass ).text( formatLogMessage( text ) ) );
		$log.scrollTop( $log[0].scrollHeight );
	}

	function appendStatusMessages( data ) {
		if ( ! data || ! data.status_messages || ! data.status_messages.length ) {
			if ( data && data.last_sequence && data.last_sequence > state.lastSequence ) {
				state.lastSequence = data.last_sequence;
			}
			return;
		}

		$.each( data.status_messages, function ( index, item ) {
			if ( item.entry ) {
				appendLog( item.entry );
			} else if ( item.message ) {
				appendLog( item.message );
			}

			if ( item.sequence && item.sequence > state.lastSequence ) {
				state.lastSequence = item.sequence;
			}
		} );

		if ( data.last_sequence && data.last_sequence > state.lastSequence ) {
			state.lastSequence = data.last_sequence;
		}
	}

	function showPostZipInstructions() {
		var $box = $( '#wpstatic-post-zip-instructions' );
		if ( ! $box.length ) {
			return;
		}

		var domain = wpstaticExportData.wpDomain || window.location.hostname || '';
		var docRoot = wpstaticExportData.wpDocRootPath || '';

		$box.empty();
		$box.append( $( '<p class="wpstatic-post-zip-instructions-title"></p>' ).text( wpstaticExportData.postZipTitle || '' ) );
		$box.append( $( '<p></p>' ).text( wpstaticExportData.postZipLine1 || '' ) );
		$box.append( $( '<p></p>' ).text( wpstaticExportData.postZipLine2 || '' ) );
		$box.append(
			$( '<p></p>' ).text(
				( wpstaticExportData.postZipDomainLabel || '' ) + ' ' + domain
			)
		);
		$box.append(
			$( '<p></p>' ).text(
				( wpstaticExportData.postZipDocRootLabel || '' ) + ' ' + docRoot
			)
		);
		$box.append( $( '<p></p>' ).text( wpstaticExportData.postZipLine3 || '' ) );
		$box.show();
	}

	function request( action, extraData, ajaxOptions ) {
		var data = $.extend(
			{
				action: action,
				nonce: wpstaticExportData.nonce
			},
			extraData || {}
		);

		return $.ajax(
			$.extend(
				{
					url: wpstaticExportData.ajaxUrl,
					method: 'POST',
					data: data
				},
				ajaxOptions || {}
			)
		);
	}

	function isAlreadyRunningError( data ) {
		if ( ! data ) {
			return false;
		}

		if ( data.already_running || data.error_code === 'already_running' ) {
			return true;
		}

		var msg = data.message ? String( data.message ).toLowerCase() : '';
		return msg.indexOf( 'already in progress' ) !== -1 || msg.indexOf( 'already running' ) !== -1;
	}

	function setRunningUI( running ) {
		$( '#wpstatic-start-export' ).toggle( ! running );
		$( '#wpstatic-pause-export' ).toggle( running && ! state.paused && ! state.recoveryMode );
		$( '#wpstatic-resume-export' ).toggle( running && ( state.paused || state.recoveryMode ) );
		$( '#wpstatic-abort-export' ).toggle( running );
		$( '#wpstatic-export-spinner' ).toggle( running ).toggleClass( 'is-active', running );
		setSessionControlsDisabled( running );
	}

	function setSessionControlsDisabled( disabled ) {
		var isDisabled = !! disabled;
		$( '#wpstatic-delete-log, #wpstatic-delete-temp, #wpstatic-include-diagnostics' ).prop( 'disabled', isDisabled );

		$( '#wpstatic-download-zip, #wpstatic-download-log' ).each( function () {
			var $link = $( this );
			$link.toggleClass( 'wpstatic-link-disabled', isDisabled );
			$link.attr( 'aria-disabled', isDisabled ? 'true' : 'false' );
			$link.attr( 'tabindex', isDisabled ? '-1' : '0' );
			$link.data( 'disabled', isDisabled );
		} );
	}

	function setActionButtonsDisabled( disabled ) {
		$( '#wpstatic-pause-export, #wpstatic-resume-export, #wpstatic-abort-export' ).prop( 'disabled', !! disabled );
	}

	function clearAutoResumeTimer() {
		if ( state.autoResumeTimer ) {
			clearTimeout( state.autoResumeTimer );
			state.autoResumeTimer = null;
		}
	}

	function resetAutoResumeState() {
		state.autoResumeAttempts = 0;
		clearAutoResumeTimer();
	}

	function scheduleAutoResumeRetry( delayMs ) {
		if ( ! state.running || state.offline ) {
			return;
		}

		clearAutoResumeTimer();
		state.autoResumeTimer = setTimeout( function () {
			state.autoResumeTimer = null;
			autoResumeAfterReconnect();
		}, delayMs );
	}

	function autoResumeAfterReconnect() {
		if ( ! state.running || state.autoResumeInProgress || state.offline ) {
			return;
		}

		clearAutoResumeTimer();
		state.autoResumeAttempts += 1;
		state.autoResumeInProgress = true;
		request( 'wpstatic_resume', {}, { timeout: CHUNK_TIMEOUT } )
			.done( function ( response ) {
				if ( response && response.success ) {
					resetAutoResumeState();
					state.paused = false;
					state.recoveryMode = false;
					setActionButtonsDisabled( false );
					setRunningUI( true );
					appendLog( t( 'msgExportResumed', 'Export resumed.' ) );
					processBatch();
					return;
				}

				if ( state.autoResumeAttempts < AUTO_RESUME_MAX_ATTEMPTS ) {
					scheduleAutoResumeRetry( AUTO_RESUME_RETRY_DELAY );
					return;
				}

				resetAutoResumeState();
				syncActiveExportState( true );
				appendLog( t( 'msgAutoResumeFailed', 'Could not resume export automatically. Please click Resume or Abort.' ) );
			} )
			.fail( function ( jqXHR, textStatus ) {
				if ( isConnectivityDropError( jqXHR, textStatus ) && state.autoResumeAttempts < AUTO_RESUME_MAX_ATTEMPTS ) {
					scheduleAutoResumeRetry( AUTO_RESUME_RETRY_DELAY );
					return;
				}

				if ( state.autoResumeAttempts < AUTO_RESUME_MAX_ATTEMPTS ) {
					scheduleAutoResumeRetry( AUTO_RESUME_RETRY_DELAY );
					return;
				}

				resetAutoResumeState();
				appendLog( t( 'msgAutoResumeFailed', 'Could not resume export automatically. Please click Resume or Abort.' ) );
			} )
			.always( function () {
				state.autoResumeInProgress = false;
			} );
	}

	function handleOfflineEvent() {
		if ( ! state.running ) {
			return;
		}

		state.offline = true;
		state.paused = true;
		state.recoveryMode = true;
		state.running = true;
		setRunningUI( true );
		setActionButtonsDisabled( true );

		if ( ! state.offlineNoticeShown ) {
			appendHighlightedLog( t( 'msgOfflineWaitingReconnect', 'Your internet connection dropped. Waiting to reconnect...' ), 'error' );
			state.offlineNoticeShown = true;
		}

		if ( state.pendingXHR && typeof state.pendingXHR.abort === 'function' ) {
			state.pendingXHR.abort();
		}
		clearWatchdog();
		resetAutoResumeState();
		state.inFlight = false;
		if ( state.processingTimer ) {
			clearTimeout( state.processingTimer );
			state.processingTimer = null;
		}
	}

	function handleOnlineEvent() {
		if ( ! state.offline ) {
			return;
		}

		state.offline = false;
		state.offlineNoticeShown = false;
		appendHighlightedLog( t( 'msgOnlineRestoredResuming', 'Internet connection restored. Trying to resume export. Please wait ...' ), 'success' );
		setActionButtonsDisabled( false );
		scheduleAutoResumeRetry( 1200 );
	}

	function syncActiveExportState( shouldResume ) {
		request( 'wpstatic_get_active_export_status', {}, { timeout: CHUNK_TIMEOUT } )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					// Keep current controls when status lookup fails.
					return;
				}

				var data = response && response.data ? response.data : {};
				var hasActive = !! data.has_active;
				var status = data && data.status ? String( data.status ) : '';

				if ( ! hasActive ) {
					// In recovery mode, do not hide controls on transient mismatch.
					if ( state.recoveryMode ) {
						setRunningUI( true );
						setActionButtonsDisabled( state.offline );
						return;
					}
					stopRunningUI();
					return;
				}

				state.running = true;
				state.paused = ( status === 'paused' );
				state.recoveryMode = false;
				state.offline = false;
				state.offlineNoticeShown = false;
				setRunningUI( true );
				setActionButtonsDisabled( false );

				if ( shouldResume && ! state.paused ) {
					processBatch();
				}
			} );
	}

	function clearWatchdog() {
		if ( state.watchdogTimer ) {
			clearTimeout( state.watchdogTimer );
			state.watchdogTimer = null;
		}
		state.pendingXHR = null;
	}

	function stopRunningUI() {
		state.running = false;
		state.paused = false;
		state.recoveryMode = false;
		state.offline = false;
		state.offlineNoticeShown = false;
		state.autoResumeInProgress = false;
		resetAutoResumeState();
		clearWatchdog();

		if ( state.processingTimer ) {
			clearTimeout( state.processingTimer );
			state.processingTimer = null;
		}

		setRunningUI( false );
		setActionButtonsDisabled( false );
	}

	function setCompletedUI() {
		stopRunningUI();
		$( '#wpstatic-download-zip' ).show();
	}

	function scheduleNextBatch( waitMs ) {
		var delay = parseInt( waitMs, 10 );
		if ( isNaN( delay ) || delay < 100 ) {
			delay = 500;
		}

		if ( state.processingTimer ) {
			clearTimeout( state.processingTimer );
		}

		state.processingTimer = setTimeout( processBatch, delay );
	}

	function onBatchStuck() {
		if ( state.offline || ( typeof navigator !== 'undefined' && navigator.onLine === false ) ) {
			handleOfflineEvent();
			return;
		}

		var msg = ( wpstaticExportData.restartMessage || 'The server did not respond within 30 seconds. Please click the "Resume" button.\n\nIf the export still doesn\'t resume, click "Abort". After the export aborted successfully, reload this page and click "Generate/Export Static Site" again to restart the export process.' );
		appendLog( msg );
		state.running = true;
		state.paused = true;
		state.recoveryMode = true;
		setActionButtonsDisabled( false );
		setRunningUI( true );
		if ( state.pendingXHR && typeof state.pendingXHR.abort === 'function' ) {
			state.pendingXHR.abort();
		}
		clearWatchdog();
		state.inFlight = false;
		if ( state.processingTimer ) {
			clearTimeout( state.processingTimer );
			state.processingTimer = null;
		}
	}

	function isConnectivityDropError( jqXHR, textStatus ) {
		var statusCode = 0;
		if ( jqXHR && typeof jqXHR.status === 'number' ) {
			statusCode = jqXHR.status;
		}

		if ( state.offline || ( typeof navigator !== 'undefined' && navigator.onLine === false ) ) {
			return true;
		}

		if ( textStatus === 'timeout' ) {
			return true;
		}

		if ( statusCode === 0 && ( textStatus === 'error' || textStatus === 'abort' ) ) {
			return true;
		}

		return false;
	}

	function processBatch() {
		if ( ! state.running || state.paused || state.inFlight ) {
			return;
		}

		state.inFlight = true;
		clearWatchdog();

		var xhr = request(
			'wpstatic_process_batch',
			{ after_sequence: state.lastSequence },
			{ timeout: CHUNK_TIMEOUT }
		);
		state.pendingXHR = xhr;
		state.watchdogTimer = setTimeout( function () {
			if ( state.inFlight && state.pendingXHR ) {
				onBatchStuck();
			}
		}, CHUNK_TIMEOUT + 2000 );

		xhr.done( function ( response ) {
			clearWatchdog();
			var data = response && response.data ? response.data : {};
			appendStatusMessages( data );

			if ( ! response || ! response.success ) {
				appendLog( data.message || t( 'msgExportStoppedError', 'Export stopped due to an error.' ) );
				if ( isAlreadyRunningError( data ) ) {
					state.running = true;
					state.paused = true;
					state.recoveryMode = true;
					setActionButtonsDisabled( false );
					setRunningUI( true );
					appendLog( t( 'msgAlreadyRunningControls', 'An export is already running. Use Resume to continue or Abort to cancel.' ) );
				} else {
					onBatchStuck();
				}
				return;
			}

			state.recoveryMode = false;
			state.offline = false;
			state.offlineNoticeShown = false;
			resetAutoResumeState();
			setActionButtonsDisabled( false );

			if ( data.status_message && ( ! data.status_messages || ! data.status_messages.length ) ) {
				appendLog( data.status_message );
			} else if ( data.message && ( ! data.status_messages || ! data.status_messages.length ) ) {
				appendLog( data.message );
			}

			if ( data.status === 'completed' ) {
				setCompletedUI();
				return;
			}

			if ( data.status === 'failed' || data.status === 'cancelled' ) {
				appendLog( data.message || t( 'msgExportStopped', 'Export stopped.' ) );
				stopRunningUI();
				return;
			}

			if ( data.status === 'paused' ) {
				state.paused = true;
				setRunningUI( true );
			}

			scheduleNextBatch( data.next_wait );
		} )
			.fail( function ( jqXHR, textStatus ) {
				clearWatchdog();
				if ( ! state.inFlight ) {
					return;
				}

				// Ignore intentional aborts triggered by offline handler or UI transitions.
				if ( textStatus === 'abort' && ( state.offline || ! state.running ) ) {
					return;
				}

				// Treat connectivity-related failures as offline/reconnect flow, not timeout failure.
				if ( isConnectivityDropError( jqXHR, textStatus ) ) {
					handleOfflineEvent();
					return;
				}

				onBatchStuck();
			} )
			.always( function () {
				state.inFlight = false;
			} );
	}

	function bindEvents() {
		$( '#wpstatic-start-export' ).on( 'click', function () {
			if ( ! window.confirm( wpstaticExportData.confirmStartExport ) ) {
				return;
			}

			state.lastSequence = 0;

			request( 'wpstatic_start_export', {}, { timeout: CHUNK_TIMEOUT } )
				.done( function ( response ) {
					var data = response && response.data ? response.data : {};
					var msg = data.message ? data.message : t( 'msgUnableToStartExport', 'Unable to start export.' );
					if ( ! response || ! response.success ) {
						appendLog( msg );
						if ( isAlreadyRunningError( data ) ) {
							state.running = true;
							state.paused  = true;
							state.recoveryMode = true;
							setActionButtonsDisabled( false );
							setRunningUI( true );
							appendLog( t( 'msgAlreadyRunningControls', 'An export is already running. Use Resume to continue or Abort to cancel.' ) );
						} else {
							onBatchStuck();
						}
						return;
					}

					state.running = true;
					state.paused = false;
					state.recoveryMode = false;
					state.offline = false;
					state.offlineNoticeShown = false;
					setActionButtonsDisabled( false );
					setRunningUI( true );
					appendLog( response.data && response.data.message ? response.data.message : t( 'msgExportStarted', 'Export started.' ) );
					processBatch();
				} )
				.fail( function ( jqXHR, textStatus ) {
					var msg = ( wpstaticExportData.restartMessage || 'The server did not respond within 30 seconds. Please click the "Resume" button.\n\nIf the export still doesn\'t resume, click "Abort". After the export aborted successfully, reload this page and click "Generate/Export Static Site" again to restart the export process.' );
					appendLog( msg );
					onBatchStuck();
				} );
		} );

		$( '#wpstatic-pause-export' ).on( 'click', function () {
			request( 'wpstatic_pause' ).done( function ( response ) {
				if ( response && response.success ) {
					state.paused = true;
					state.recoveryMode = false;
					setActionButtonsDisabled( false );
					setRunningUI( true );
					appendLog( t( 'msgExportPaused', 'Export paused.' ) );
				}
			} );
		} );

		$( '#wpstatic-resume-export' ).on( 'click', function () {
			request( 'wpstatic_resume' ).done( function ( response ) {
				if ( response && response.success ) {
					state.paused = false;
					state.recoveryMode = false;
					state.offline = false;
					state.offlineNoticeShown = false;
					resetAutoResumeState();
					setActionButtonsDisabled( false );
					setRunningUI( true );
					appendLog( t( 'msgExportResumed', 'Export resumed.' ) );
					processBatch();
				}
			} );
		} );

		$( '#wpstatic-abort-export' ).on( 'click', function () {
			if ( ! window.confirm( wpstaticExportData.confirmAbort ) ) {
				return;
			}
			request( 'wpstatic_abort' ).done( function () {
				appendLog( t( 'msgExportAborted', 'Export aborted.' ) );
				stopRunningUI();
			} );
		} );

		$( '#wpstatic-download-zip' ).on( 'click', function () {
			if ( $( this ).data( 'disabled' ) ) {
				return false;
			}
			showPostZipInstructions();
		} );

		$( '#wpstatic-download-log' ).on( 'click', function () {
			if ( $( this ).data( 'disabled' ) ) {
				return false;
			}
		} );

		$( '#wpstatic-delete-log' ).on( 'click', function () {
			if ( ! window.confirm( wpstaticExportData.confirmDeleteLog ) ) {
				return;
			}
			request( 'wpstatic_delete_log' ).done( function ( response ) {
				appendLog( response && response.data && response.data.message ? response.data.message : t( 'msgLogDeleted', 'Log deleted.' ) );
			} );
		} );

		$( '#wpstatic-delete-temp' ).on( 'click', function () {
			if ( ! window.confirm( wpstaticExportData.confirmDeleteTemp ) ) {
				return;
			}
			request( 'wpstatic_delete_temp_dirs' ).done( function ( response ) {
				appendLog( response && response.data && response.data.message ? response.data.message : t( 'msgTempDirectoriesDeleted', 'Temporary directories deleted.' ) );
			} );
		} );

		$( '#wpstatic-include-diagnostics' ).on( 'change', function () {
			var $download = $( '#wpstatic-download-log' );
			var href = $download.attr( 'href' ) || '';
			href = href.replace( /&diagnostics=yes/g, '' );
			if ( this.checked ) {
				href += '&diagnostics=yes';
			}
			$download.attr( 'href', href );
		} );

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( ! state.running ) {
				return;
			}

			event.preventDefault();
			event.returnValue = wpstaticExportData.unloadWarning;
			return wpstaticExportData.unloadWarning;
		} );
	}

	$( function () {
		bindEvents();
		var hasActive = !!( wpstaticExportData.hasActiveExport );
		var status   = ( wpstaticExportData.activeExportStatus || '' );

		if ( hasActive && ( status === 'fetching' || status === 'collecting' ) ) {
			state.running = true;
			state.paused  = false;
			state.recoveryMode = false;
			state.offline = false;
			state.offlineNoticeShown = false;
			setActionButtonsDisabled( false );
			setRunningUI( true );
			appendLog( t( 'msgActiveExportInProgress', 'An export is in progress. Use Pause or Abort to control it, or wait for completion.' ) );
			processBatch();
		} else if ( hasActive && status === 'paused' ) {
			state.running = true;
			state.paused  = true;
			state.recoveryMode = false;
			state.offline = false;
			state.offlineNoticeShown = false;
			setActionButtonsDisabled( false );
			setRunningUI( true );
			appendLog( t( 'msgActiveExportPaused', 'Export is paused. Click Resume to continue or Abort to cancel.' ) );
		} else {
			setRunningUI( false );
		}

		window.addEventListener( 'offline', handleOfflineEvent );
		window.addEventListener( 'online', handleOnlineEvent );
		syncActiveExportState( true );

		if ( !! wpstaticExportData.showPostZipInstructions ) {
			showPostZipInstructions();
		}
	} );
}( jQuery, window ) );
