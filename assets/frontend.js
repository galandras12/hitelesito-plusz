(function () {
	'use strict';

	function base64urlToBuffer( value ) {
		var base64 = value.replace( /-/g, '+' ).replace( /_/g, '/' );
		var pad = base64.length % 4;
		if ( pad ) {
			base64 += '===='.slice( pad );
		}
		var raw = atob( base64 );
		var buffer = new ArrayBuffer( raw.length );
		var bytes = new Uint8Array( buffer );
		for ( var i = 0; i < raw.length; i++ ) {
			bytes[ i ] = raw.charCodeAt( i );
		}
		return buffer;
	}

	function bufferToBase64url( buffer ) {
		var bytes = new Uint8Array( buffer );
		var str = '';
		for ( var i = 0; i < bytes.byteLength; i++ ) {
			str += String.fromCharCode( bytes[ i ] );
		}
		return btoa( str ).replace( /\+/g, '-' ).replace( /\//g, '_' ).replace( /=+$/, '' );
	}

	/* ------------------------------------------------------------------
	 * Hibadiagnosztika - minden AJAX/hálózati hibáról részletes, kimásolható
	 * leírást állít elő, hogy a felhasználó pontosan visszajelezhesse, hol
	 * és mi akadt el (időpont, művelet, HTTP állapot, nyers válasz, böngésző).
	 * ---------------------------------------------------------------- */

	function nowString() {
		var d = new Date();
		function pad( n ) { return ( n < 10 ? '0' : '' ) + n; }
		return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() ) + ' ' +
			pad( d.getHours() ) + ':' + pad( d.getMinutes() ) + ':' + pad( d.getSeconds() );
	}

	function buildDiagnosticsText( info ) {
		var lines = [];
		lines.push( '=== Hitelesítő+ hibajelentés ===' );
		lines.push( 'Időpont: ' + nowString() );
		lines.push( 'Oldal: ' + window.location.href );
		lines.push( 'Művelet: ' + ( info.action || '-' ) );
		lines.push( 'HTTP állapot: ' + ( undefined !== info.httpStatus ? info.httpStatus : 'ismeretlen' ) );
		lines.push( 'Üzenet: ' + ( info.message || '-' ) );
		lines.push( 'Nyers válasz: ' + ( info.rawText || '-' ) );
		lines.push( 'Böngésző: ' + navigator.userAgent );
		return lines.join( '\n' );
	}

	function showDiagnostics( info ) {
		if ( window.console && console.warn ) {
			console.warn( '[Hitelesítő+ hiba]', info );
		}

		var toggle = document.querySelector( '[data-h2f-diagnostics-toggle]' );
		var panel = document.querySelector( '[data-h2f-diagnostics]' );
		var pre = document.querySelector( '[data-h2f-diagnostics-text]' );

		if ( pre ) {
			pre.textContent = buildDiagnosticsText( info );
		}
		if ( toggle ) {
			toggle.style.display = 'inline-block';
		}
		// Első hiba esetén automatikusan nyissuk is ki, hogy azonnal látszódjon.
		if ( panel && toggle && 'none' === getComputedStyle( panel ).display && ! toggle.dataset.h2fUserToggled ) {
			panel.style.display = 'block';
			toggle.textContent = 'Hiba részleteinek elrejtése';
		}
	}

	function wireDiagnosticsPanel() {
		var toggle = document.querySelector( '[data-h2f-diagnostics-toggle]' );
		var panel = document.querySelector( '[data-h2f-diagnostics]' );
		var copyBtn = document.querySelector( '[data-h2f-diagnostics-copy]' );

		if ( toggle && panel ) {
			toggle.addEventListener( 'click', function () {
				toggle.dataset.h2fUserToggled = '1';
				var isHidden = 'none' === getComputedStyle( panel ).display;
				panel.style.display = isHidden ? 'block' : 'none';
				toggle.textContent = isHidden ? 'Hiba részleteinek elrejtése' : 'Hiba részleteinek megtekintése';
			} );
		}

		if ( copyBtn ) {
			copyBtn.addEventListener( 'click', function () {
				var pre = document.querySelector( '[data-h2f-diagnostics-text]' );
				var text = pre ? pre.textContent : '';
				var original = copyBtn.textContent;

				function done() {
					copyBtn.textContent = 'Másolva!';
					setTimeout( function () { copyBtn.textContent = original; }, 1500 );
				}

				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text ).then( done );
				} else {
					var tmp = document.createElement( 'textarea' );
					tmp.value = text;
					document.body.appendChild( tmp );
					tmp.select();
					document.execCommand( 'copy' );
					document.body.removeChild( tmp );
					done();
				}
			} );
		}
	}

	/**
	 * Felismeri, ha a nyers válasz valójában egy teljes weboldal (HTML
	 * dokumentum) a várt JSON helyett - ez tipikusan azt jelenti, hogy egy
	 * biztonsági/tűzfal bővítmény (pl. Shield Security, Wordfence, egyéb
	 * bot-védelem) blokkolta vagy átirányította az AJAX kérést, mielőtt az
	 * elérte volna a plugin kódját.
	 */
	function looksLikeHtmlPage( text ) {
		var trimmed = ( text || '' ).trim().toLowerCase();
		return trimmed.indexOf( '<!doctype html' ) === 0 || trimmed.indexOf( '<html' ) === 0;
	}

	function endpointUrl( action, useRest ) {
		if ( useRest && window.H2F && H2F.restUrl ) {
			return H2F.restUrl + encodeURIComponent( action );
		}
		return H2F.ajaxUrl;
	}

	function refreshNonce( useRest ) {
		var body = new URLSearchParams();
		body.set( 'action', 'h2f_refresh_nonce' );
		return fetch( endpointUrl( 'h2f_refresh_nonce', useRest ), {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'X-Requested-With': 'XMLHttpRequest',
			},
			body: body.toString(),
			cache: 'no-store',
		} ).then( function ( r ) { return r.json(); } ).then( function ( res ) {
			if ( res && res.success ) {
				if ( res.data.pendingNonce ) {
					H2F.nonce = res.data.pendingNonce;
				} else if ( res.data.setupNonce ) {
					H2F.nonce = res.data.setupNonce;
				}
			}
			return res;
		} ).catch( function () {
			if ( ! useRest && window.H2F && H2F.restUrl ) {
				return refreshNonce( true );
			}
		} );
	}

	function post( action, params, isRetry, useRest ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', ( window.H2F && H2F.nonce ) ? H2F.nonce : '' );
		for ( var key in params ) {
			if ( Object.prototype.hasOwnProperty.call( params, key ) ) {
				body.set( key, params[ key ] );
			}
		}
		return fetch( endpointUrl( action, useRest ), {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				// Sok tűzfal/bot-védelmi bővítmény ez alapján ismeri fel, hogy
				// valódi AJAX kérésről van szó, nem közvetlen/gyanús POST-ról.
				'X-Requested-With': 'XMLHttpRequest',
			},
			body: body.toString(),
			cache: 'no-store',
		} ).then( function ( r ) {
			var httpStatus = r.status;
			return r.text().then( function ( text ) {
				var parsed;
				try {
					parsed = JSON.parse( text );
				} catch ( e ) {
					parsed = null;
				}

				if ( ! parsed || typeof parsed !== 'object' ) {
					if ( looksLikeHtmlPage( text ) && ! useRest && window.H2F && H2F.restUrl ) {
						return post( action, params, true, true );
					}
					if ( looksLikeHtmlPage( text ) ) {
						parsed = {
							success: false,
							data: {
								message: 'A szerver egy teljes weboldalt küldött vissza a várt válasz helyett (HTTP ' + httpStatus + ').',
								detail: 'Ez tipikusan egy biztonsági/tűzfal bővítmény (pl. Shield Security, Wordfence, Sucuri, vagy hasonló bot-védelem/CAPTCHA) beavatkozása miatt történik, ami blokkolja vagy átirányítja az admin-ajax.php-ra küldött kérést. Kérjük, ellenőrizd a biztonsági bővítmény beállításait, és adj kivételt a "h2f_" kezdetű AJAX műveletekre / a wp-admin/admin-ajax.php végpontra.',
							},
						};
					} else {
						parsed = {
							success: false,
							data: {
								message: 'Váratlan szerverválasz (HTTP ' + httpStatus + '). Lásd a hiba részleteit lent.',
								detail: 'A szerver a várt JSON válasz helyett a következőt adta: ' + ( text ? text.substring( 0, 200 ) : 'üres válasz' ),
							},
						};
					}
				}
				parsed._diag = { action: action, httpStatus: httpStatus, rawText: text };
				return parsed;
			} );
		} ).then( function ( res ) {
			// Ha az oldal HTML-je (pl. gyorsítótárazó plugin miatt) elavult
			// nonce-ot tartalmazott, itt automatikusan frissítünk és egyszer
			// újrapróbáljuk - a felhasználó ebből semmit nem vesz észre.
			if ( ! isRetry && res && false === res.success && res.data && res.data.nonce_expired ) {
				return refreshNonce( useRest ).then( function () {
					return post( action, params, true, useRest );
				} );
			}
			if ( res && false === res.success ) {
				showDiagnostics( {
					action: action,
					httpStatus: res._diag ? res._diag.httpStatus : undefined,
					message: getErrorMessage( res ),
					rawText: res._diag ? res._diag.rawText : '',
				} );
			}
			return res;
		} ).catch( function ( err ) {
			var message = 'Hálózati hiba: ' + ( ( err && err.message ) || String( err ) );
			showDiagnostics( { action: action, httpStatus: undefined, message: message, rawText: String( err && err.stack || err ) } );
			return { success: false, data: { message: message } };
		} );
	}

	function showError( container, selector, message ) {
		var el = container.querySelector( '[data-h2f-error="' + selector + '"]' );
		if ( el ) {
			el.textContent = message;
			el.style.display = 'block';
		}
	}

	function hideError( container, selector ) {
		var el = container.querySelector( '[data-h2f-error="' + selector + '"]' );
		if ( el ) {
			el.style.display = 'none';
		}
	}

	function getErrorMessage( res ) {
		if ( res && res.data && res.data.message ) {
			var msg = res.data.message;
			if ( res.data.detail ) {
				msg += ' — ' + res.data.detail;
			}
			return msg;
		}
		return ( window.H2F && H2F.i18n && H2F.i18n.genericError ) ? H2F.i18n.genericError : 'Hiba történt, próbáld újra.';
	}

	function setBusy( btn, busy, label ) {
		if ( ! btn ) {
			return;
		}
		btn.disabled = busy;
		if ( busy ) {
			btn.dataset.originalText = btn.textContent;
			btn.textContent = label || '...';
		} else if ( btn.dataset.originalText ) {
			btn.textContent = btn.dataset.originalText;
		}
	}

	/**
	 * Egyszerű, megbízható mobil-detektálás: a user-agent alapú érintőképernyős
	 * eszközjelzők (telefonok/tabletek), kiegészítve a durva mutatóeszköz
	 * (coarse pointer) + keskeny képernyő ellenőrzésével azokra az esetekre,
	 * amikor egy egyedi/ritkább böngésző UA-stringje nem egyezik pontosan.
	 */
	function isMobileDevice() {
		var uaMatch = /Android|iPhone|iPad|iPod|Windows Phone|Mobile/i.test( navigator.userAgent );
		var coarsePointer = window.matchMedia && window.matchMedia( '(pointer: coarse)' ).matches && window.innerWidth <= 820;
		return uaMatch || coarsePointer;
	}

	/**
	 * A QR kódot mobilon egy érinthető hivatkozásba csomagolja, ami az
	 * otpauth:// séma megnyitásával közvetlenül elindítja az alapértelmezett
	 * hitelesítő alkalmazást (Google/Microsoft Authenticator, stb.) a
	 * kézi QR-beolvasás megkerülésével. Asztali nézetben marad a sima QR kép.
	 */
	function renderQrCode( holder, svg, otpauthUri ) {
		if ( ! holder ) {
			return;
		}
		holder.innerHTML = '';

		if ( isMobileDevice() && otpauthUri ) {
			var link = document.createElement( 'a' );
			link.href = otpauthUri;
			link.className = 'h2f-qr-tap-link';
			link.setAttribute( 'aria-label', 'Érintse meg a gyors párosításhoz' );
			link.innerHTML = svg;

			var caption = document.createElement( 'span' );
			caption.className = 'h2f-qr-tap-caption';
			caption.innerHTML = '<span aria-hidden="true">📲</span> Érintse meg a gyors párosításhoz';

			link.appendChild( caption );
			holder.appendChild( link );
		} else {
			holder.innerHTML = svg;
		}
	}

	function downloadBackupCodesTxt( data ) {
		var lines = [];
		lines.push( '=== ' + data.site_name + ' - Hitelesítő+ biztonsági mentési kódok ===' );
		lines.push( 'Felhasználó: ' + data.user_login );
		lines.push( 'Generálva: ' + data.generated_at );
		lines.push( '' );
		lines.push( 'Minden kód egyszer használható fel, ha nem férsz hozzá a többi hitelesítő módszerhez.' );
		lines.push( 'Tárold biztonságos, offline helyen (pl. nyomtatva, széfben).' );
		lines.push( '' );
		data.codes.forEach( function ( code, i ) {
			lines.push( ( i + 1 ) + '.   ' + code );
		} );
		lines.push( '' );
		lines.push( 'Ha ezeket a kódokat felhasználod vagy elveszíted, generálj újakat a fiókod beállításai között.' );

		var blob = new Blob( [ lines.join( '\n' ) ], { type: 'text/plain;charset=utf-8' } );
		var url = URL.createObjectURL( blob );
		var a = document.createElement( 'a' );
		a.href = url;
		a.download = 'hitelesito-plusz-biztonsagi-kodok-' + ( data.user_login || 'user' ) + '.txt';
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		setTimeout( function () { URL.revokeObjectURL( url ); }, 1000 );
	}

	var H2FVerify = {
		init: function ( opts ) {
			var root = document;

			wireDiagnosticsPanel();

			// Az oldal betöltésekor azonnal friss nonce-ot kérünk - ha az oldal
			// HTML-je gyorsítótárazva volt, ez a kérés soha nincs gyorsítótárazva.
			refreshNonce();

			function goto( view ) {
				var views = root.querySelectorAll( '[data-h2f-view]' );
				for ( var i = 0; i < views.length; i++ ) {
					views[ i ].style.display = views[ i ].getAttribute( 'data-h2f-view' ) === view ? '' : 'none';
				}
			}

			root.querySelectorAll( '[data-h2f-goto]' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					goto( btn.getAttribute( 'data-h2f-goto' ) );
				} );
			} );

			if ( opts && opts.firstMethod ) {
				goto( opts.firstMethod );
			}

			function finalizeRedirect( res ) {
				if ( res && res.success ) {
					window.location.href = res.data.redirect;
				}
			}

			var totpBtn = root.querySelector( '[data-h2f-submit="totp"]' );
			if ( totpBtn ) {
				totpBtn.addEventListener( 'click', function () {
					var input = root.querySelector( '[data-h2f-input="totp"]' );
					hideError( root, 'totp' );
					setBusy( totpBtn, true, H2F.i18n.verifying );
					post( 'h2f_verify_totp', { code: input.value } ).then( function ( res ) {
						setBusy( totpBtn, false );
						if ( res.success ) {
							finalizeRedirect( res );
						} else {
							showError( root, 'totp', getErrorMessage( res ) );
						}
					} ).catch( function () {
						setBusy( totpBtn, false );
						showError( root, 'totp', H2F.i18n.genericError );
					} );
				} );
			}

			var backupBtn = root.querySelector( '[data-h2f-submit="backup"]' );
			if ( backupBtn ) {
				backupBtn.addEventListener( 'click', function () {
					var input = root.querySelector( '[data-h2f-input="backup"]' );
					hideError( root, 'backup' );
					setBusy( backupBtn, true, H2F.i18n.verifying );
					post( 'h2f_verify_backup', { code: input.value } ).then( function ( res ) {
						setBusy( backupBtn, false );
						if ( res.success ) {
							finalizeRedirect( res );
						} else {
							showError( root, 'backup', getErrorMessage( res ) );
						}
					} ).catch( function () {
						setBusy( backupBtn, false );
						showError( root, 'backup', H2F.i18n.genericError );
					} );
				} );
			}

			var emailSendBtns = root.querySelectorAll( '[data-h2f-send="email"]' );
			emailSendBtns.forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					hideError( root, 'email' );
					setBusy( btn, true, H2F.i18n.sending );
					post( 'h2f_send_email_code', {} ).then( function ( res ) {
						setBusy( btn, false );
						if ( res.success ) {
							var info = root.querySelector( '[data-h2f-info="email"]' );
							if ( info ) {
								info.textContent = res.data.message;
								info.style.display = 'block';
							}
							var box = root.querySelector( '[data-h2f-email-code]' );
							if ( box ) {
								box.style.display = 'block';
							}
						} else {
							showError( root, 'email', getErrorMessage( res ) );
						}
					} ).catch( function () {
						setBusy( btn, false );
						showError( root, 'email', H2F.i18n.genericError );
					} );
				} );
			} );

			var emailSubmitBtn = root.querySelector( '[data-h2f-submit="email"]' );
			if ( emailSubmitBtn ) {
				emailSubmitBtn.addEventListener( 'click', function () {
					var input = root.querySelector( '[data-h2f-input="email"]' );
					hideError( root, 'email' );
					setBusy( emailSubmitBtn, true, H2F.i18n.verifying );
					post( 'h2f_verify_email', { code: input.value } ).then( function ( res ) {
						setBusy( emailSubmitBtn, false );
						if ( res.success ) {
							finalizeRedirect( res );
						} else {
							showError( root, 'email', getErrorMessage( res ) );
						}
					} ).catch( function () {
						setBusy( emailSubmitBtn, false );
						showError( root, 'email', H2F.i18n.genericError );
					} );
				} );
			}

			var passkeyBtn = root.querySelector( '[data-h2f-submit="passkey"]' );
			if ( passkeyBtn ) {
				passkeyBtn.addEventListener( 'click', function () {
					hideError( root, 'passkey' );

					if ( ! window.PublicKeyCredential ) {
						showError( root, 'passkey', H2F.i18n.passkeyNotSupp );
						return;
					}

					setBusy( passkeyBtn, true, H2F.i18n.verifying );

					post( 'h2f_passkey_auth_options', {} ).then( function ( res ) {
						if ( ! res.success ) {
							throw new Error( res.data && res.data.message );
						}
						var opts = res.data;
						var publicKey = {
							challenge: base64urlToBuffer( opts.challenge ),
							timeout: opts.timeout,
							rpId: opts.rpId,
							userVerification: opts.userVerification,
							allowCredentials: ( opts.allowCredentials || [] ).map( function ( c ) {
								return { type: c.type, id: base64urlToBuffer( c.id ) };
							} ),
						};
						return navigator.credentials.get( { publicKey: publicKey } );
					} ).then( function ( assertion ) {
						var credential = {
							id: bufferToBase64url( assertion.rawId ),
							rawId: bufferToBase64url( assertion.rawId ),
							type: assertion.type,
							response: {
								clientDataJSON: bufferToBase64url( assertion.response.clientDataJSON ),
								authenticatorData: bufferToBase64url( assertion.response.authenticatorData ),
								signature: bufferToBase64url( assertion.response.signature ),
							},
						};
						return post( 'h2f_passkey_auth_verify', { credential: JSON.stringify( credential ) } );
					} ).then( function ( res ) {
						setBusy( passkeyBtn, false );
						if ( res.success ) {
							finalizeRedirect( res );
						} else {
							showError( root, 'passkey', getErrorMessage( res ) );
						}
					} ).catch( function ( err ) {
						setBusy( passkeyBtn, false );
						showError( root, 'passkey', ( err && err.message ) || H2F.i18n.genericError );
					} );
				} );
			}

			root.querySelectorAll( '.h2f-code-input' ).forEach( function ( input ) {
				input.addEventListener( 'keydown', function ( e ) {
					if ( 'Enter' === e.key ) {
						var view = input.closest( '[data-h2f-view]' );
						var btn = view && view.querySelector( '[data-h2f-submit]' );
						if ( btn ) {
							btn.click();
						}
					}
				} );
			} );
		},
	};

	var H2FSetup = {
		init: function () {
			var root = document;

			wireDiagnosticsPanel();
			refreshNonce();

			var totpStartBtn = root.querySelector( '[data-h2f-totp-start]' );
			if ( totpStartBtn ) {
				totpStartBtn.addEventListener( 'click', function () {
					setBusy( totpStartBtn, true );
					post( 'h2f_setup_totp_start', {} ).then( function ( res ) {
						setBusy( totpStartBtn, false );
						if ( ! res.success ) {
							return;
						}
						renderQrCode( root.querySelector( '[data-h2f-qr-holder]' ), res.data.qr_svg, res.data.otpauth_uri );
						root.querySelector( '[data-h2f-manual-secret]' ).textContent = res.data.secret;
						root.querySelector( '[data-h2f-totp-setup-box]' ).style.display = 'block';
						totpStartBtn.style.display = 'none';
					} );
				} );
			}

			var toggleManualBtn = root.querySelector( '[data-h2f-toggle-manual]' );
			if ( toggleManualBtn ) {
				toggleManualBtn.addEventListener( 'click', function () {
					var box = root.querySelector( '[data-h2f-manual-secret]' );
					box.style.display = ( box.style.display === 'none' || ! box.style.display ) ? 'flex' : 'none';
				} );
			}

			var totpConfirmBtn = root.querySelector( '[data-h2f-totp-confirm]' );
			if ( totpConfirmBtn ) {
				totpConfirmBtn.addEventListener( 'click', function () {
					var input = root.querySelector( '[data-h2f-input="totp-confirm"]' );
					hideError( root, 'totp-confirm' );
					setBusy( totpConfirmBtn, true, H2F.i18n.verifying );
					post( 'h2f_setup_totp_confirm', { code: input.value } ).then( function ( res ) {
						setBusy( totpConfirmBtn, false );
						if ( res.success ) {
							window.location.reload();
						} else {
							showError( root, 'totp-confirm', getErrorMessage( res ) );
						}
					} );
				} );
			}

			var totpDisableBtn = root.querySelector( '[data-h2f-totp-disable]' );
			if ( totpDisableBtn ) {
				totpDisableBtn.addEventListener( 'click', function () {
					if ( ! window.confirm( 'Biztosan letiltod a hitelesítő alkalmazást?' ) ) {
						return;
					}
					post( 'h2f_setup_totp_disable', {} ).then( function ( res ) {
						if ( res.success ) {
							window.location.reload();
						}
					} );
				} );
			}

			var emailToggleBtn = root.querySelector( '[data-h2f-email-toggle]' );
			if ( emailToggleBtn ) {
				emailToggleBtn.addEventListener( 'click', function () {
					var current = emailToggleBtn.getAttribute( 'data-current' ) === '1';
					setBusy( emailToggleBtn, true );
					post( 'h2f_setup_email_toggle', { enabled: current ? '' : '1' } ).then( function ( res ) {
						setBusy( emailToggleBtn, false );
						if ( res.success ) {
							window.location.reload();
						}
					} );
				} );
			}

			var backupGenBtn = root.querySelector( '[data-h2f-backup-generate]' );
			if ( backupGenBtn ) {
				backupGenBtn.addEventListener( 'click', function () {
					setBusy( backupGenBtn, true );
					post( 'h2f_setup_backup_generate', {} ).then( function ( res ) {
						setBusy( backupGenBtn, false );
						if ( ! res.success ) {
							return;
						}
						var box = root.querySelector( '[data-h2f-backup-codes-box]' );
						var grid = document.createElement( 'div' );
						grid.className = 'h2f-backup-codes-grid';
						res.data.codes.forEach( function ( code ) {
							var span = document.createElement( 'div' );
							span.textContent = code;
							grid.appendChild( span );
						} );
						box.innerHTML = '';

						// A TXT fájlt a böngészőben, a szerver megkerülésével
						// állítjuk elő és töltjük le (Blob), hogy semmilyen
						// gyorsítótárazási/nonce probléma ne akadályozhassa.
						var downloadBtn = document.createElement( 'button' );
						downloadBtn.type = 'button';
						downloadBtn.className = 'h2f-btn';
						downloadBtn.style.display = 'block';
						downloadBtn.style.marginBottom = '12px';
						downloadBtn.style.boxSizing = 'border-box';
						downloadBtn.textContent = 'Letöltés TXT fájlként';
						downloadBtn.addEventListener( 'click', function () {
							downloadBackupCodesTxt( res.data );
						} );

						box.appendChild( downloadBtn );
						box.appendChild( grid );
						box.style.display = 'block';

						// Automatikusan el is indítjuk a letöltést, a gomb csak
						// az ismételt letöltéshez marad ott.
						downloadBackupCodesTxt( res.data );
					} );
				} );
			}

			var backupDisableBtn = root.querySelector( '[data-h2f-backup-disable]' );
			if ( backupDisableBtn ) {
				backupDisableBtn.addEventListener( 'click', function () {
					if ( ! window.confirm( 'Biztosan érvényteleníted az összes biztonsági mentési kódot?' ) ) {
						return;
					}
					post( 'h2f_setup_backup_disable', {} ).then( function ( res ) {
						if ( res.success ) {
							window.location.reload();
						}
					} );
				} );
			}

			var passkeyAddBtn = root.querySelector( '[data-h2f-passkey-add]' );
			var passkeyNameBox = root.querySelector( '[data-h2f-passkey-name-box]' );
			if ( passkeyAddBtn && passkeyNameBox ) {
				passkeyAddBtn.addEventListener( 'click', function () {
					passkeyNameBox.style.display = 'block';
					passkeyAddBtn.style.display = 'none';
				} );
			}

			var passkeyConfirmBtn = root.querySelector( '[data-h2f-passkey-confirm]' );
			if ( passkeyConfirmBtn ) {
				passkeyConfirmBtn.addEventListener( 'click', function () {
					hideError( root, 'passkey-setup' );

					if ( ! window.PublicKeyCredential ) {
						showError( root, 'passkey-setup', H2F.i18n.passkeyNotSupp );
						return;
					}

					var nameInput = root.querySelector( '[data-h2f-input="passkey-name"]' );
					var deviceName = nameInput.value || 'Ismeretlen eszköz';

					setBusy( passkeyConfirmBtn, true );

					post( 'h2f_setup_passkey_register_options', {} ).then( function ( res ) {
						if ( ! res.success ) {
							throw new Error( res.data && res.data.message );
						}
						var opts = res.data;
						var publicKey = {
							rp: opts.rp,
							user: {
								id: base64urlToBuffer( opts.user.id ),
								name: opts.user.name,
								displayName: opts.user.displayName,
							},
							challenge: base64urlToBuffer( opts.challenge ),
							pubKeyCredParams: opts.pubKeyCredParams,
							timeout: opts.timeout,
							attestation: opts.attestation,
							authenticatorSelection: opts.authenticatorSelection,
							excludeCredentials: ( opts.excludeCredentials || [] ).map( function ( c ) {
								return { type: c.type, id: base64urlToBuffer( c.id ) };
							} ),
						};
						return navigator.credentials.create( { publicKey: publicKey } );
					} ).then( function ( credential ) {
						var payload = {
							id: bufferToBase64url( credential.rawId ),
							rawId: bufferToBase64url( credential.rawId ),
							type: credential.type,
							response: {
								clientDataJSON: bufferToBase64url( credential.response.clientDataJSON ),
								attestationObject: bufferToBase64url( credential.response.attestationObject ),
							},
						};
						return post( 'h2f_setup_passkey_register_verify', {
							credential: JSON.stringify( payload ),
							device_name: deviceName,
						} );
					} ).then( function ( res ) {
						setBusy( passkeyConfirmBtn, false );
						if ( res.success ) {
							window.location.reload();
						} else {
							showError( root, 'passkey-setup', getErrorMessage( res ) );
						}
					} ).catch( function ( err ) {
						setBusy( passkeyConfirmBtn, false );
						showError( root, 'passkey-setup', ( err && err.message ) || H2F.i18n.genericError );
					} );
				} );
			}

			root.querySelectorAll( '[data-h2f-passkey-delete]' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					if ( ! window.confirm( 'Biztosan törlöd ezt a passkey-t?' ) ) {
						return;
					}
					var id = btn.getAttribute( 'data-h2f-passkey-delete' );
					post( 'h2f_setup_passkey_delete', { id: id } ).then( function ( res ) {
						if ( res.success ) {
							window.location.reload();
						}
					} );
				} );
			} );
		},
	};

	window.H2FVerify = H2FVerify;
	window.H2FSetup = H2FSetup;
} )();