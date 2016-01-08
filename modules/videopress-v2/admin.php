<?php

/**
 * Things that still need doing:
 *  - Is there a way to use a query arg to hide the share link?  That'd be useful.
 *  - Load in the JS to resize the iframe to set the height automagically and such.
 *  - The secondary extension for `wpvideo` works, but on saving changes the shortcode to `videopress` -- nbd.
 *  - Should we handle other display methods apart from iframe in the editor?
 */

/**
 * WordPress Editor Views
 */
function videopress_editor_view_js_templates() {
	if ( ! isset( get_current_screen()->id ) || get_current_screen()->base != 'post' ) {
		return;
	}

	/**
	 * This template uses the following parameters, and displays the video as an iframe:
	 *  - data.guid     // The guid of the video.
	 *  - data.width    // The width of the iframe.
	 *  - data.height   // The height of the iframe.
	 *  - data.urlargs  // Arguments serialized into a get string.
	 *
	 * In addition, the calling script will need to ensure that the following
	 * JS file is added to the header of the editor iframe:
	 *  - https://s0.wp.com/wp-content/plugins/video/assets/js/next/videopress-iframe.js
	 */
	?>
	<script type="text/html" id="tmpl-videopress_iframe_vnext">
		<div class="tmpl-videopress_iframe_next">
			<iframe width="{{ data.width }}" height="{{ data.height }}" src="https://videopress.com/embed/{{ data.guid }}?{{ data.urlargs }}" frameborder='0' allowfullscreen></iframe>
		</div>
	</script>
	<?php
}
add_action( 'admin_print_footer_scripts', 'videopress_editor_view_js_templates' );

/**
 * WordPress Shortcode Editor View JS Code
 *
 * For convenience and readability, this is printed out in the
 * footer, but ideally should be enqueued seperately.
 */
function videopress_editor_view_footer_scripts() {
	global $content_width;
	if ( ! isset( get_current_screen()->id ) || get_current_screen()->base != 'post' ) {
		return;
	}
	?>
	<script>
		/* global tinyMCE, console */
		(function( $, wp ){
			wp.mce = wp.mce || {};
			wp.mce.videopress_wp_view_renderer = {
				shortcode_string : 'videopress',
				shortcode_data : {},
				template       : wp.template( 'videopress_iframe_vnext' ),
				getContent     : function() {
					var urlargs = 'for=<?php echo esc_js( parse_url( home_url(), PHP_URL_HOST ) ); ?>',
						named = this.shortcode.attrs.named,
						options, key, width;

					for ( key in named ) {
						switch ( key ) {
							case 'at' :
								if ( parseInt( named[ key ], 10 ) ) {
									urlargs += '&' + key + '=' + parseInt( named[ key ], 10 );
								} // Else omit, as it's the default.
								break;
							case 'permalink' :
								if ( 'false' === named[ key ] ) {
									urlargs += '&' + key + '=0';
								} // Else omit, as it's the default.
								break;
							case 'hd' :
							case 'loop' :
							case 'autoplay' :
								if ( 'true' === named[ key ] ) {
									urlargs += '&' + key + '=1';
								} // Else omit, as it's the default.
								break;
							default:
								// Unknown parameters?  Ditch it!
								break;
						}
					}

					options = {
						width   : <?php echo esc_js( $content_width ); ?>,
						height  : <?php echo esc_js( intval( $content_width * 0.5625 ) ); /* 0.5625 = 9/16 -- a 16:9 HD aspect ratio */ ?>,
						guid    : this.shortcode.attrs.numeric[0],
						urlargs : urlargs
					};

					if ( typeof named.w !== 'undefined' ) {
						width = parseInt( named.w, 10 );
						if ( width > 60 && width < <?php echo esc_js( $content_width ); ?> ) {
							options.width  = width;
							options.height = parseInt( width * 0.5625, 10 );
						}
					}

					return this.template( options );
				},
				edit: function( data, update ) {
					var shortcode_data = wp.shortcode.next( this.shortcode_string, data ), // TODO: make work for wpvideo as well
						values        = shortcode_data.shortcode.attrs.named;

					values.guid = shortcode_data.shortcode.attrs.numeric[0];

					wp.mce.videopress_wp_view_renderer.popupwindow( tinyMCE.activeEditor, values );
				},
				popupwindow: function( editor, values, onsubmit_callback ){
					var renderer = this,
						key;

					/**
					 * Populate the defaults.
					 */
					values = $.extend( {
						w         : '',
						at        : 0,
						permalink : false,
						hd        : true,
						loop      : true,
						freedom   : true,
						autoplay  : true,
						flashonly : true
					}, values );

					/**
					 * Set up a fallback onsubmit callback handler.
					 *
					 * A custom one can be provided as the third argument if desired.
					 */
					if ( typeof onsubmit_callback !== 'function' ) {
						onsubmit_callback = function( e ) {
							var s = '[' + renderer.shortcode_string,
								i;
							for ( i in e.data ) {
								switch( i ) {
									case 'guid' :
										s += ' ' + e.data.guid;
										break;
									case 'w' :
									case 'at' :
										if ( parseInt( e.data[ i ], 10 ) ) {
											s += ' ' + i + '="' + parseInt( e.data[ i ], 10 ) + '"';
										} // Else omit, as it's the default.
										break;
									case 'permalink' :
										if ( ! e.data[ i ] ) {
											s += ' ' + i + '="false"';
										} // Else omit, as it's the default.
										break;
									case 'hd' :
									case 'loop' :
									case 'freedom' :
									case 'autoplay' :
									case 'flashonly' :
										if ( e.data[ i ] ) {
											s += ' ' + i + '="true"';
										} // Else omit, as it's the default.
										break;
									default:
										// Unknown parameters?  Ditch it!
										break;
								}
							}
							s += ']';
							editor.insertContent( s );
						};
					}

					/**
					 * Cast the checked options to true or false as needed.
					 */
					for ( key in values ) {
						switch ( key ) {
							case 'permalink' :
								if ( $.inArray( values[ key ], [ false, 'false', '0' ] ) ) {
									values[ key ] = false;
								} else {
									values[ key ] = true;
								}
								break;
							case 'hd' :
							case 'loop' :
							case 'freedom' :
							case 'autoplay' :
							case 'flashonly' :
								if ( $.inArray( values[ key ], [ true, 'true', '1' ] ) ) {
									values[ key ] = true;
								} else {
									values[ key ] = false;
								}
								break;
							default:
								break;
						}
					}

					/**
					 * Declare the fields that will show in the popup when editing the shortcode.
					 */
					editor.windowManager.open( {
						title : '<?php echo esc_js( __( 'VideoPress Shortcode', 'jetpack' ) ); ?>', // This should be internationalized via wp_localize_script
						body  : [
							{
								type  : 'textbox',
								name  : 'guid',
								label : '<?php echo esc_js( __( 'Video GUID', 'jetpack' ) ); ?>',
								value : values.guid
							},
							{
								type  : 'textbox',
								name  : 'w',
								label : '<?php echo esc_js( __( 'Width (in pixels)', 'jetpack' ) ); ?>',
								value : values.w
							},
							{
								type  : 'textbox',
								name  : 'at',
								label : '<?php echo esc_js( __( 'Start how many seconds in?', 'jetpack' ) ); ?>',
								value : values.at
							},
							{
								type    : 'checkbox',
								name    : 'hd',
								label   : '<?php echo esc_js( __( 'Default to High Definition version?', 'jetpack' ) ); ?>',
								checked : values.hd
							},
							{
								type    : 'checkbox',
								name    : 'permalink',
								label   : '<?php echo esc_js( __( 'Display the permalink to the video?', 'jetpack' ) ); ?>',
								checked : values.permalink
							},
							{
								type    : 'checkbox',
								name    : 'autoplay',
								label   : '<?php echo esc_js( __( 'Autoplay video on load?', 'jetpack' ) ); ?>',
								checked : values.autoplay
							},
							{
								type    : 'checkbox',
								name    : 'loop',
								label   : '<?php echo esc_js( __( 'Loop playback indefinitely?', 'jetpack' ) ); ?>',
								checked : values.loop
							},
							{
								type    : 'checkbox',
								name    : 'freedom',
								label   : '<?php echo esc_js( __( 'Use only Open Source codecs? (this may degrade performance)', 'jetpack' ) ); ?>',
								checked : values.freedom
							},
							{
								type    : 'checkbox',
								name    : 'flashonly',
								label   : '<?php echo esc_js( __( 'Use the legacy flash player? (not recommended)', 'jetpack' ) ); ?>',
								checked : values.flashonly
							}
						],
						onsubmit : onsubmit_callback
					} );
				}
			};
			wp.mce.views.register( 'videopress', wp.mce.videopress_wp_view_renderer );

			// Extend the videopress one to also handle `wpvideo` instances.
			wp.mce.wpvideo_wp_view_renderer = _.extend( {}, wp.mce.videopress_wp_view_renderer, {
				shortcode_string : 'wpvideo'
			});
			wp.mce.views.register( 'wpvideo', wp.mce.wpvideo_wp_view_renderer );
		}( jQuery, wp ));
	</script>
	<?php
}
add_action( 'admin_print_footer_scripts', 'videopress_editor_view_footer_scripts' );
