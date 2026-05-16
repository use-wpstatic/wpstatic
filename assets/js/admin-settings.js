( function ( $, window ) {
	'use strict';

	var autosaveTimer = null;
	var pendingXHR = null;

	function data() {
		return window.wpstaticAdminSettingsData || {};
	}

	function getMessage( key, fallback ) {
		var settings = data();
		return settings[ key ] ? String( settings[ key ] ) : fallback;
	}

	function setMessage( type, text ) {
		var $message = $( '#wpstatic-settings-message' );
		if ( ! $message.length ) {
			return;
		}

		$message
			.removeClass( 'is-success is-error' )
			.addClass( type ? 'is-' + type : '' )
			.text( text || '' )
			.toggle( !! text );
	}

	function renderGenerateQuestion() {
		var $question = $( '#wpstatic-settings-export-question' );
		if ( ! $question.length ) {
			return;
		}

		$question.empty();
		$question.append( $( '<p></p>' ).text( getMessage( 'msgGenerateQuestion', 'Do you want to Generate/Export Static Site now?' ) ) );

		var yesId = 'wpstatic-generate-now-yes';
		var noId = 'wpstatic-generate-now-no';
		var name = 'wpstatic_generate_now';

		$question.append(
			$( '<label></label>' )
				.attr( 'for', yesId )
				.append( $( '<input>' ).attr( { type: 'radio', id: yesId, name: name, value: 'yes' } ) )
				.append( document.createTextNode( ' ' + getMessage( 'msgGenerateYes', 'Yes' ) ) )
		);

		$question.append(
			$( '<label></label>' )
				.attr( 'for', noId )
				.append( $( '<input>' ).attr( { type: 'radio', id: noId, name: name, value: 'no' } ) )
				.append( document.createTextNode( ' ' + getMessage( 'msgGenerateNo', "No; I'll do it from the 'Make Static Site' tab later" ) ) )
		);

		$question.show();
	}

	function hideGenerateQuestion() {
		$( '#wpstatic-settings-export-question' ).hide().empty();
	}

	function formPayload( $form, silent ) {
		var settings = data();
		var settingsGroup = $form.attr( 'data-settings-group' ) || '';

		var payload = {
			action: 'wpstatic_save_settings',
			nonce: settings.nonce || '',
			settings_group: settingsGroup,
			silent: silent ? '1' : '0'
		};

		if ( settingsGroup === 'http_basic_auth' ) {
			payload.username = $form.find( '[name="username"]' ).val() || '';
			payload.password = $form.find( '[name="password"]' ).val() || '';
		}

		if ( $form.find( '[name="allow_insecure_local_http_fetch"]' ).length ) {
			payload.allow_insecure_local_http_fetch = $form.find( '[name="allow_insecure_local_http_fetch"]' ).prop( 'checked' ) ? '1' : '0';
		}

		if ( $form.find( '[name="prefer_temp_storage_above_document_root"]' ).length ) {
			payload.prefer_temp_storage_above_document_root = $form.find( '[name="prefer_temp_storage_above_document_root"]' ).prop( 'checked' ) ? '1' : '0';
		}

		return payload;
	}

	function saveSettings( $form, silent ) {
		var settings = data();
		if ( ! settings.ajaxUrl || ! $form || ! $form.length ) {
			return;
		}

		if ( pendingXHR && typeof pendingXHR.abort === 'function' ) {
			pendingXHR.abort();
		}

		if ( silent ) {
			hideGenerateQuestion();
			setMessage( '', '' );
		}

		pendingXHR = $.ajax( {
			url: settings.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: formPayload( $form, silent )
		} )
			.done( function ( response, textStatus, jqXHR ) {
				if ( typeof response !== 'object' || response === null ) {
					try {
						response = JSON.parse( jqXHR.responseText || '{}' );
					} catch ( err ) {
						if ( ! silent ) {
							setMessage( 'error', getMessage( 'msgSettingsSaveError', 'Settings could not be saved. Please try again.' ) );
						}
						return;
					}
				}

				var responseData = response && response.data ? response.data : {};
				if ( ! response || ! response.success ) {
					if ( ! silent ) {
						setMessage( 'error', responseData.message || getMessage( 'msgSettingsSaveError', 'Settings could not be saved. Please try again.' ) );
					}
					return;
				}

				if ( silent ) {
					return;
				}

				setMessage( 'success', responseData.message || getMessage( 'msgSettingsSaved', 'Settings saved successfully.' ) );

				if ( ! responseData.has_completed_export ) {
					renderGenerateQuestion();
				} else {
					hideGenerateQuestion();
				}
			} )
			.fail( function ( jqXHR, textStatus ) {
				if ( textStatus === 'abort' || silent ) {
					return;
				}

				if ( jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
					setMessage( 'error', jqXHR.responseJSON.data.message );
					return;
				}

				setMessage( 'error', getMessage( 'msgSettingsSaveError', 'Settings could not be saved. Please try again.' ) );
			} )
			.always( function () {
				pendingXHR = null;
			} );
	}

	function bindSettingsForm( formSelector, saveButtonSelector ) {
		var $form = $( formSelector );
		if ( ! $form.length ) {
			return;
		}

		$form.off( 'input.wpstatic change.wpstatic', 'input' ).on( 'input.wpstatic change.wpstatic', 'input', function () {
			if ( autosaveTimer ) {
				clearTimeout( autosaveTimer );
			}

			autosaveTimer = setTimeout( function () {
				autosaveTimer = null;
				saveSettings( $form, true );
			}, 500 );
		} );

		$( saveButtonSelector ).off( 'click.wpstatic' ).on( 'click.wpstatic', function () {
			if ( autosaveTimer ) {
				clearTimeout( autosaveTimer );
				autosaveTimer = null;
			}

			hideGenerateQuestion();
			saveSettings( $form, false );
		} );

		$( '#wpstatic-settings-export-question' ).off( 'change.wpstatic', 'input[name="wpstatic_generate_now"]' ).on( 'change.wpstatic', 'input[name="wpstatic_generate_now"]', function () {
			if ( this.value === 'yes' ) {
				window.location.href = data().autoStartExportUrl || data().makeStaticSiteUrl || window.location.href;
			}
		} );

	}

	function initSettingsForms() {
		bindSettingsForm( '#wpstatic-security-settings-form', '#wpstatic-save-security-settings' );
		bindSettingsForm( '#wpstatic-general-settings-form', '#wpstatic-save-general-settings' );
	}

	$( initSettingsForms );
}( jQuery, window ) );
