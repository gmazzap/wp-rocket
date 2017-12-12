<?php
defined( 'ABSPATH' ) || die( 'Cheatin&#8217; uh?' );

/**
 * Handles the critical CSS generation process.
 *
 * @since 2.11
 * @author Remy Perona
 */
class Rocket_Critical_CSS {
	/**
	 * Background Process instance
	 *
	 * @since 2.11
	 * @var object $process Background Process instance.
	 * @access public
	 */
	public $process;

	/**
	 * Items for which we generate a critical CSS
	 *
	 * @since 2.11
	 * @var array $items An array of items.
	 * @access public
	 */
	public $items = array();

	/**
	 * Path to the critical CSS directory
	 *
	 * @since 2.11
	 * @var string path to the critical css directory
	 * @access public
	 */
	public $critical_css_path;

	/**
	 * The single instance of the class.
	 *
	 * @since 2.11
	 * @var object
	 */
	protected static $_instance;

	/**
	 * Get the main instance.
	 *
	 * Ensures only one instance of class is loaded or can be loaded.
	 *
	 * @since 2.11
	 * @author Remy Perona
	 *
	 * @return object Main instance.
	 */
	public static function get_instance() {
		if ( ! isset( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Class constructor.
	 *
	 * @since 2.11
	 * @author Remy Perona
	 */
	public function __construct() {
		$this->process = new Rocket_Background_Critical_CSS_Generation();
		$this->items[] = array(
			'type' => 'front_page',
			'url'  => home_url( '/' ),
		);

		$this->critical_css_path = WP_ROCKET_CRITICAL_CSS_PATH . get_current_blog_id() . '/';
	}

	/**
	 * Initializes class and hooks.
	 *
	 * @since 2.11
	 * @author Remy Perona
	 */
	public function init() {
		add_action( 'admin_post_rocket_generate_critical_css', array( $this, 'init_critical_css_generation' ) );
		add_action( 'update_option_' . WP_ROCKET_SLUG, array( $this, 'generate_critical_css_on_activation' ), 11, 2 );
		add_action( 'update_option_' . WP_ROCKET_SLUG, array( $this, 'stop_process_on_deactivation' ), 11, 2 );

		if ( get_rocket_option( 'async_css' ) ) {
			add_action( 'switch_theme', array( $this, 'process_handler' ) );
		}

		add_action( 'admin_notices', array( $this, 'critical_css_generation_running_notice' ) );
		add_action( 'admin_notices', array( $this, 'critical_css_generation_complete_notice' ) );
		add_action( 'admin_notices', array( $this, 'warning_critical_css_dir_permissions' ) );
		add_action( 'wp_head', array( $this, 'insert_load_css' ), PHP_INT_MAX );
		add_action( 'wp_head', array( $this, 'insert_critical_css' ), 1 );
		add_filter( 'rocket_buffer', array( $this, 'async_css' ), 15 );
		add_action( 'rocket_critical_css_generation_process_complete', 'rocket_clean_domain' );
	}

	/**
	 * Performs the critical CSS generation
	 *
	 * @since 2.11
	 * @author Remy Perona
	 */
	public function process_handler() {
		$this->clean_critical_css();
		$this->process->cancel_process();
		$this->set_items();

		foreach ( $this->items as $item ) {
			$this->process->push_to_queue( $item );
		}

		$transient = array(
			'generated' => 0,
			'total'     => count( $this->items ),
			'items'     => array(),
		);

		set_transient( 'rocket_critical_css_generation_process_running', $transient, HOUR_IN_SECONDS );
		$this->process->save()->dispatch();
	}

	/**
	 * Launches the critical CSS generation from admin
	 *
	 * @since 2.11
	 * @author Remy Perona
	 *
	 * @see process_handler()
	 */
	public function init_critical_css_generation() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'rocket_generate_critical_css' ) ) {
			wp_nonce_ays( '' );
		}

		$this->process_handler();

		wp_safe_redirect( esc_url_raw( wp_get_referer() ) );
		die();
	}

	/**
	 * Launches the critical CSS generation when activating the async CSS option
	 *
	 * @since 2.11
	 * @author Remy Perona
	 *
	 * @see process_handler()
	 *
	 * @param array $old_value Previous values for WP Rocket settings.
	 * @param array $value     New values for WP Rocket settings.
	 */
	public function generate_critical_css_on_activation( $old_value, $value ) {
		if ( ! empty( $_POST[ WP_ROCKET_SLUG ] ) && isset( $old_value['async_css'], $value['async_css'] ) && ( $old_value['async_css'] !== $value['async_css'] ) && 1 === (int) $value['async_css'] ) {
			$this->process_handler();
		}
	}

	/**
	 * Stops the critical CSS generation when deactivating the async CSS option and remove the notices
	 *
	 * @since 2.11
	 * @author Remy Perona
	 *
	 * @param array $old_value Previous values for WP Rocket settings.
	 * @param array $value     New values for WP Rocket settings.
	 */
	public function stop_process_on_deactivation( $old_value, $value ) {
		if ( ! empty( $_POST[ WP_ROCKET_SLUG ] ) && isset( $old_value['async_css'], $value['async_css'] ) && ( $old_value['async_css'] !== $value['async_css'] ) && 0 === (int) $value['async_css'] ) {
			$this->process->cancel_process();
			delete_transient( 'rocket_critical_css_generation_process_running' );
			delete_transient( 'rocket_critical_css_generation_process_complete' );
		}
	}

	/**
	 * Deletes critical CSS files
	 *
	 * @since 2.11
	 * @author Remy Perona
	 */
	public function clean_critical_css() {
		try {
			$directory = new RecursiveDirectoryIterator( $this->critical_css_path, FilesystemIterator::SKIP_DOTS );
		} catch ( Exception $e ) {
			// no logging yet.
			return;
		}

		try {
			$files = new RecursiveIteratorIterator( $directory, RecursiveIteratorIterator::CHILD_FIRST );
		} catch ( Exception $e ) {
			// no logging yet.
			return;
		}

		foreach ( $files as $file ) {
			rocket_direct_filesystem()->delete( $file );
		}
	}

	/**
	 * Gets all public post types
	 *
	 * @since 2.11
	 * @author Remy Perona
	 */
	public function get_public_post_types() {
		global $wpdb;

		$post_types = get_post_types(
			array(
				'public'             => true,
				'publicly_queryable' => true,
			)
		);

		$post_types[] = 'page';

		/**
		 * Filters the post types excluded from critical CSS generation
		 *
		 * @since 2.11
		 * @author Remy Perona
		 *
		 * @param array $excluded_post_types An array of post types names.
		 * @return array
		 */
		$excluded_post_types = apply_filters( 'rocket_cpcss_excluded_post_types', array() );

		$post_types = array_diff( $post_types, $excluded_post_types );
		$post_types = esc_sql( $post_types );
		$post_types = "'" . implode( "','", $post_types ) . "'";

		$rows = $wpdb->get_results( // WPCS: unprepared SQL ok.
			"
		    SELECT MAX(ID) as ID, post_type
		    FROM (
		        SELECT ID, post_type
		        FROM $wpdb->posts
		        WHERE post_type IN ( $post_types )
		        AND post_status = 'publish'
		        ORDER BY post_date DESC
		    ) AS posts
		    GROUP BY post_type"
		);

		return $rows;
	}

	/**
	 * Gets all public taxonomies
	 *
	 * @since 2.11
	 * @author Remy Perona
	 */
	public function get_public_taxonomies() {
		global $wpdb;

		$taxonomies = get_taxonomies(
			array(
				'public'             => true,
				'publicly_queryable' => true,
			)
		);

		/**
		 * Filters the taxonomies excluded from critical CSS generation
		 *
		 * @since 2.11
		 * @author Remy Perona
		 *
		 * @param array $excluded_taxonomies An array of taxonomies names.
		 * @return array
		 */
		$excluded_taxonomies = apply_filters( 'rocket_cpcss_excluded_taxonomies', array(
			'post_format',
			'product_shipping_class',
		) );

		$taxonomies = array_diff( $taxonomies, $excluded_taxonomies );
		$taxonomies = esc_sql( $taxonomies );
		$taxonomies = "'" . implode( "','", $taxonomies ) . "'";

		$rows = $wpdb->get_results( // WPCS: unprepared SQL ok.
			"
			SELECT MAX( term_id ) AS ID, taxonomy
			FROM (
				SELECT term_id, taxonomy
				FROM $wpdb->term_taxonomy
				WHERE taxonomy IN ( $taxonomies )
				AND count > 0
			) AS taxonomies
			GROUP BY taxonomy
			"
		);

		return $rows;
	}

	/**
	 * Sets the items for which we generate critical CSS
	 *
	 * @since 2.11
	 * @author Remy Perona
	 */
	public function set_items() {
		$page_for_posts = get_option( 'page_for_posts' );

		if ( 'page' === get_option( 'show_on_front' ) && ! empty( $page_for_posts ) ) {
			$this->items[] = array(
				'type' => 'home',
				'url'  => get_permalink( get_option( 'page_for_posts' ) ),
			);
		}

		$post_types = $this->get_public_post_types();

		foreach ( $post_types as $post_type ) {
			$this->items[] = array(
				'type' => $post_type->post_type,
				'url'  => get_permalink( $post_type->ID ),
			);
		}

		$taxonomies = $this->get_public_taxonomies();

		foreach ( $taxonomies as $taxonomy ) {
			$this->items[] = array(
				'type' => $taxonomy->taxonomy,
				'url'  => get_term_link( (int) $taxonomy->ID, $taxonomy->taxonomy ),
			);
		}
	}

	/**
	 * This notice is displayed when the critical CSS generation is running
	 *
	 * @since 2.11
	 * @author Remy Perona
	 */
	public function critical_css_generation_running_notice() {
		// This filter is documented in inc/admin-bar.php.
		if ( ! current_user_can( apply_filters( 'rocket_capacity', 'manage_options' ) ) ) {
			return;
		}

		$transient = get_transient( 'rocket_critical_css_generation_process_running' );
		if ( ! $transient ) {
			return;
		}

		$message = '<p>' . sprintf( __( 'Critical CSS generation is currently running: %1$d of %2$d page types completed. (Refresh this page to view progress)', 'rocket' ), $transient['generated'], $transient['total'] ) . '</p>';

		if ( ! empty( $transient['items'] ) ) {
			$message .= '<ul>';

			foreach( $transient['items'] as $item ) {
				$message .= '<li>' . $item . '</li>';
			}

			$message .= '</ul>';
		}

		rocket_notice_html( array(
			'status'  => 'info',
			'message' => $message,
		) );
	}

	/**
	 * This notice is displayed when the critical CSS generation is complete
	 *
	 * @since 2.11
	 * @author Remy Perona
	 */
	public function critical_css_generation_complete_notice() {
		// This filter is documented in inc/admin-bar.php.
		if ( ! current_user_can( apply_filters( 'rocket_capacity', 'manage_options' ) ) ) {
			return;
		}

		$transient = get_transient( 'rocket_critical_css_generation_process_complete' );
		if ( ! $transient ) {
			return;
		}

		if ( $transient['generated'] === 0 ) {
			$status = 'error';
		} elseif ( $transient['generated'] < $transient['total'] ) {
			$status = 'warning';
		} else {
			$status = 'success';
		}

		$message = '<p>' . sprintf( __( 'Critical CSS generation finished for %1$d of %2$d page types.', 'rocket' ), $transient['generated'], $transient['total'] ) . '</p>';

		if ( ! empty( $transient['items'] ) ) {
			$message .= '<ul>';

			foreach( $transient['items'] as $item ) {
				$message .= '<li>' . $item . '</li>';
			}

			$message .= '</ul>';
		}

		rocket_notice_html( array(
			'status'  => $status,
			'message' => $message,
		) );

		delete_transient( 'rocket_critical_css_generation_process_complete' );
	}

	/**
	 * This warning is displayed when the critical CSS dir isn't writeable
	 *
	 * @since 2.11
	 * @author Remy Perona
	 */
	public function warning_critical_css_dir_permissions() {
		// This filter is documented in inc/admin-bar.php.
		if ( current_user_can( apply_filters( 'rocket_capacity', 'manage_options' ) )
			&& ( ! rocket_direct_filesystem()->is_writable( WP_ROCKET_CRITICAL_CSS_PATH ) )
			&& ( get_rocket_option( 'async_css', false ) )
			&& rocket_valid_key() ) {

			$boxes = get_user_meta( $GLOBALS['current_user']->ID, 'rocket_boxes', true );

			if ( in_array( __FUNCTION__, (array) $boxes, true ) ) {
				return;
			}

			$message = rocket_notice_writing_permissions( trim( str_replace( ABSPATH, '', WP_ROCKET_CRITICAL_CSS_PATH ), '/' ) );

			rocket_notice_html( array(
				'status'      => 'error',
				'dismissible' => '',
				'message'     => $message,
			) );
		}
	}

	/**
	 * Determines if critical CSS is available for the current page
	 *
	 * @since 2.11
	 * @author Remy Perona
	 *
	 * @return bool|string False if critical CSS file doesn't exist, file path otherwise
	 */
	public function get_current_page_critical_css() {
		if ( is_home() && 'page' === get_option( 'show_on_front' ) ) {
			$name = 'home.css';
		} elseif ( is_front_page() ) {
			$name = 'front_page.css';
		} elseif ( is_category() ) {
			$name = 'category.css';
		} elseif ( is_tag() ) {
			$name = 'post_tag.css';
		} elseif ( is_tax() ) {
			$taxonomy = get_queried_object()->term_name;
			$name = $taxonomy . '.css';
		} elseif ( is_singular() ) {
			$post_type = get_post_type();
			$name = $post_type . '.css';
		} else {
			$name = 'front_page.css';
		}

		$file = $this->critical_css_path . $name;

		if ( ! rocket_direct_filesystem()->is_readable( $file ) ) {
			if ( ! empty( get_rocket_option( 'critical_css', '' ) ) ) {
				return 'fallback';
			}
	
			return false;
		}

		return $file;
	}

	/**
	 * Defer loading of CSS files
	 *
	 * @since 2.10
	 * @author Remy Perona
	 *
	 * @param string $buffer HTML code.
	 * @return string Updated HTML code
	 */
	public function async_css( $buffer ) {
		if ( ! get_rocket_option( 'async_css' ) ) {
			return $buffer;
		}

		if ( is_rocket_post_excluded_option( 'async_css' ) ) {
			return $buffer;
		}

		if ( ! $this->get_current_page_critical_css() ) {
			return $buffer;
		}

		$excluded_css = array_flip( get_rocket_exclude_async_css() );

		// Get all css files with this regex.
		preg_match_all( apply_filters( 'rocket_async_css_regex_pattern', '/(?=<link[^>]*\s(rel\s*=\s*[\'"]stylesheet["\']))<link[^>]*\shref\s*=\s*[\'"]([^\'"]+)[\'"](.*)>/iU' ), $buffer, $tags_match );

		if ( ! isset( $tags_match[0] ) ) {
			return $buffer;
		}

		$noscripts = '';

		foreach ( $tags_match[0] as $i => $tag ) {
			// Strip query args.
			$path = rocket_extract_url_component( $tags_match[2][ $i ], PHP_URL_PATH );

			// Check if this file should be deferred.
			if ( isset( $excluded_css[ $path ] ) ) {
				continue;
			}

			$preload = str_replace( 'stylesheet', 'preload', $tags_match[1][ $i ] );
			$onload  = str_replace( $tags_match[3][ $i ], ' as="style" onload=""' . $tags_match[3][ $i ] . '>', $tags_match[3][ $i ] );
			$tag     = str_replace( $tags_match[3][ $i ] . '>', $onload, $tag );
			$tag     = str_replace( $tags_match[1][ $i ], $preload, $tag );
			$tag     = str_replace( 'onload=""', 'onload="this.rel=\'stylesheet\'"', $tag );
			$buffer  = str_replace( $tags_match[0][ $i ], $tag, $buffer );

			$noscripts .= '<noscript>' . $tags_match[0][ $i ] . '</noscript>';
		}

		$buffer = str_replace( '</body>', $noscripts . '</body>', $buffer );

		return $buffer;
	}

	/**
	 * Insert critical CSS in the <head>
	 *
	 * @since 2.10
	 * @author Remy Perona
	 */
	public function insert_critical_css() {
		global $pagenow;

		if ( ! get_rocket_option( 'async_css' ) ) {
			return;
		}

		if ( is_rocket_post_excluded_option( 'async_css' ) ) {
			return;
		}

		$current_page_critical_css = $this->get_current_page_critical_css();

		if ( ! $current_page_critical_css ) {
			return;
		}

		// Don't apply on wp-login.php/wp-register.php.
		if ( in_array( $pagenow, array( 'wp-login.php', 'wp-register.php' ), true ) ) {
			return;
		}

		if ( ( defined( 'DONOTROCKETOPTIMIZE' ) && DONOTROCKETOPTIMIZE ) || ( defined( 'DONOTASYNCCSS' ) && DONOTASYNCCSS ) ) {
			return;
		}

		// Don't apply if user is logged-in and cache for logged-in user is off.
		if ( is_user_logged_in() && ! get_rocket_option( 'cache_logged_user' ) ) {
			return;
		}

		// This filter is documented in inc/front/process.php.
		$rocket_cache_search = apply_filters( 'rocket_cache_search', false );

		// Don't apply on search page.
		if ( is_search() && ! $rocket_cache_search ) {
			return;
		}

		// Don't apply on excluded pages.
		if ( in_array( $_SERVER['REQUEST_URI'] , get_rocket_option( 'cache_reject_uri' , array() ), true ) ) {
			return;
		}

		// Don't apply on 404 page.
		if ( is_404() ) {
			return;
		}

		if ( 'fallback' === $current_page_critical_css ) {
			$critical_css_content = get_rocket_option( 'critical_css', '' );
		} else {
			$critical_css_content = rocket_direct_filesystem()->get_contents( $this->get_current_page_critical_css() );
		}

		if ( ! $critical_css_content ) {
			return;
		}

		echo '<style id="rocket-critical-css">' . wp_strip_all_tags( $critical_css_content ) . '</style>';
	}


	/**
	 * Insert loadCSS script in <head>
	 *
	 * @since 2.10
	 * @author Remy Perona
	 */
	public function insert_load_css() {
		global $pagenow;

		if ( ! get_rocket_option( 'async_css' ) ) {
			return;
		}

		if ( is_rocket_post_excluded_option( 'async_css' ) ) {
			return;
		}

		if ( ! $this->get_current_page_critical_css() ) {
			return;
		}

		// Don't apply on wp-login.php/wp-register.php.
		if ( in_array( $pagenow, array( 'wp-login.php', 'wp-register.php' ), true ) ) {
			return;
		}

		if ( ( defined( 'DONOTROCKETOPTIMIZE' ) && DONOTROCKETOPTIMIZE ) || ( defined( 'DONOTASYNCCSS' ) && DONOTASYNCCSS ) ) {
			return;
		}

		// Don't apply if user is logged-in and cache for logged-in user is off.
		if ( is_user_logged_in() && ! get_rocket_option( 'cache_logged_user' ) ) {
			return;
		}

		// This filter is documented in inc/front/process.php.
		$rocket_cache_search = apply_filters( 'rocket_cache_search', false );

		// Don't apply on search page.
		if ( is_search() && ! $rocket_cache_search ) {
			return;
		}

		// Don't apply on excluded pages.
		if ( in_array( $_SERVER['REQUEST_URI'] , get_rocket_option( 'cache_reject_uri' , array() ), true ) ) {
			return;
		}

		// Don't apply on 404 page.
		if ( is_404() ) {
			return;
		}

		echo <<<JS
<script>
/*! loadCSS. [c]2017 Filament Group, Inc. MIT License */
!function(a){"use strict";var b=function(b,c,d){function e(a){return h.body?a():void setTimeout(function(){e(a)})}function f(){i.addEventListener&&i.removeEventListener("load",f),i.media=d||"all"}var g,h=a.document,i=h.createElement("link");if(c)g=c;else{var j=(h.body||h.getElementsByTagName("head")[0]).childNodes;g=j[j.length-1]}var k=h.styleSheets;i.rel="stylesheet",i.href=b,i.media="only x",e(function(){g.parentNode.insertBefore(i,c?g:g.nextSibling)});var l=function(a){for(var b=i.href,c=k.length;c--;)if(k[c].href===b)return a();setTimeout(function(){l(a)})};return i.addEventListener&&i.addEventListener("load",f),i.onloadcssdefined=l,l(f),i};"undefined"!=typeof exports?exports.loadCSS=b:a.loadCSS=b}("undefined"!=typeof global?global:this);
/*! loadCSS rel=preload polyfill. [c]2017 Filament Group, Inc. MIT License */
!function(a){if(a.loadCSS){var b=loadCSS.relpreload={};if(b.support=function(){try{return a.document.createElement("link").relList.supports("preload")}catch(b){return!1}},b.poly=function(){for(var b=a.document.getElementsByTagName("link"),c=0;c<b.length;c++){var d=b[c];"preload"===d.rel&&"style"===d.getAttribute("as")&&(a.loadCSS(d.href,d,d.getAttribute("media")),d.rel=null)}},!b.support()){b.poly();var c=a.setInterval(b.poly,300);a.addEventListener&&a.addEventListener("load",function(){b.poly(),a.clearInterval(c)}),a.attachEvent&&a.attachEvent("onload",function(){a.clearInterval(c)})}}}(this);
</script>
JS;
	}

}
