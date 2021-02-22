<?php
/*
Plugin Name: WP Reverse Pagination
Description: Pagination starts higher and goes lower for more consistent archive page numbering.
Author: Russell Heimlich
Version: 0.2
*/

/**
 * Logic to make WordPress work with reverse pagination
 */
class WP_Reverse_Pagination {

	/**
	 * Once we figure out what the new paged value should be we can store it here for future uses
	 *
	 * @var integer
	 */
	private $new_paged = 0;

	/**
	 * Stores the original paged value of the current request
	 *
	 * @var integer
	 */
	private $original_paged = 0;

	/**
	 * Store the maximum number of pages value once it has been calculated
	 *
	 * @var integer
	 */
	private $max_num_pages = 0;

	/**
	 * Empty constructor
	 */
	public function construct() {}

	/**
	 * Get an instance of this class
	 */
	public static function get_instance() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new static();
			$instance->setup_actions();
			$instance->setup_filters();
		}
		return $instance;
	}

	/**
	 * Hook into WordPress via actions
	 */
	public function setup_actions() {
		add_action( 'template_redirect', array( $this, 'action_template_redirect' ) );
	}

	/**
	 * Hook into WordPress via filters
	 */
	public function setup_filters() {
		add_filter( 'redirect_canonical', array( $this, 'filter_redirect_canonical' ), 10, 2 );
		add_filter( 'posts_clauses_request', array( $this, 'filter_posts_clauses_request' ), 10, 2 );
	}

	/**
	 * Redirect to the non /page/ URL if the current $paged value === the max num pages value
	 */
	public function action_template_redirect() {
		global $wp;

		// When the requested paged value == the max_num_pages value then perform
		// a redirect to the non /page/ version of the URL for SEO reasons.
		if ( intval( $this->original_paged ) === intval( $this->max_num_pages ) ) {
			// Get the URL of the current request.
			$url = home_url( $wp->request );
			$url = add_query_arg( array( $_GET ), trailingslashit( $url ) );
			if ( ! $url ) {
				return;
			}

			$redirect = str_replace( '/page/' . $this->original_paged . '/', '', $url );
			if ( $url !== $redirect ) {
				wp_safe_redirect( $redirect, 302 );
				exit;
			}
		}
	}

	/**
	 * Undo some default redirects that WordPress provides
	 *
	 * @param string $redirect_url  The URL to be redirected to.
	 * @param string $requested_url The URL being requested.
	 */
	public function filter_redirect_canonical( $redirect_url = '', $requested_url = '' ) {
		if ( is_admin() ) {
			return;
		}

		if ( stristr( $requested_url, '/page/1/' ) ) {
			return $requested_url;
		}

		if ( stristr( $redirect_url, '/page/' . $this->new_paged . '/' ) ) {
			return $requested_url;
		}

		return $redirect_url;
	}

	/**
	 * Figure out the proper pagination values and modify SQL clauses to make everything work.
	 *
	 * @param array    $clauses The SQL clauses being modified.
	 * @param WP_Query $wp_query The WP_Query being processed.
	 */
	public function filter_posts_clauses_request( $clauses = array(), $wp_query ) {
		global $wpdb;

		if ( is_admin() || ! $wp_query->is_main_query() ) {
			return $clauses;
		}

		// Setup variables.
		$q       = $wp_query->query_vars;
		$where   = $clauses['where'];
		$groupby = $clauses['groupby'];
		if ( ! empty( $groupby ) ) {
			$groupby = 'GROUP BY ' . $groupby;
		}
		$join     = $clauses['join'];
		$orderby  = $clauses['orderby'];
		$distinct = $clauses['distinct'];
		$fields   = $clauses['fields'];
		$limits   = $clauses['limits'];

		// Figure out how many rows we have.
		$found_posts = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} $join WHERE 1=1 $where $groupby ORDER BY $orderby" );

		// Set how many found posts there are since we know it now.
		$wp_query->found_posts = intval( $found_posts );
		$wp_query->found_posts = apply_filters_ref_array( 'found_posts', array( $wp_query->found_posts, &$wp_query ) );
		
		// Set how many found posts if is archive or other taxonomy pages
		if ( get_queried_object()->term_id > 0 ){
			$wp_query->found_posts = get_term( get_queried_object()->term_id )->count;
		}

		// Set the max number of pages.
		$posts_per_page = intval( $q['posts_per_page'] );
		if ( ! $posts_per_page ) {
			$posts_per_page = get_option( 'posts_per_page' );
		}
		$wp_query->max_num_pages = ceil( $wp_query->found_posts / $posts_per_page );
		$this->max_num_pages     = $wp_query->max_num_pages;

		// Save a database query.
		$wp_query->query_vars['no_found_rows'] = true;

		// Get the current page being paginated.
		$paged     = $q['paged'];
		$new_paged = intval( $wp_query->max_num_pages - $paged ) + 1;
		if ( 0 === $paged ) {
			$new_paged = intval( $wp_query->max_num_pages );
		}
		$this->original_paged = $paged;
		$this->new_paged      = $new_paged;

		// Calculate the new offset.
		$new_offset = ( $wp_query->max_num_pages - $paged ) * $posts_per_page;
		if ( 0 === $paged ) {
			$new_offset = 0;
		}

		// Set the new limit clause if it is a positive number (otherwise a negative number indicates a 404).
		if ( $new_offset >= 0 ) {
			$clauses['limits'] = 'LIMIT ' . $new_offset . ', ' . $posts_per_page;
		}

		return $clauses;
	}
}

// Kick things off!
WP_Reverse_Pagination::get_instance();

/**
 * Display a list of pagination links based on the current query
 *
 * @see paginate_links()
 *
 * @param string $args Args to modify the output.
 */
function reverse_paginate_links( $args = '' ) {
	global $wp_query, $wp_rewrite;

	// Setting up default values based on the current URL.
	$pagenum_link = html_entity_decode( get_pagenum_link() );
	$url_parts    = explode( '?', $pagenum_link );

	// Get max pages and current page out of the current query, if available.
	$total   = isset( $wp_query->max_num_pages ) ? $wp_query->max_num_pages : 1;
	$current = get_query_var( 'paged' ) ? intval( get_query_var( 'paged' ) ) : $total;

	// Append the format placeholder to the base URL.
	$pagenum_link = trailingslashit( $url_parts[0] ) . '%_%';

	// URL base depends on permalink settings.
	$format  = $wp_rewrite->using_index_permalinks() && ! strpos( $pagenum_link, 'index.php' ) ? 'index.php/' : '';
	$format .= $wp_rewrite->using_permalinks() ? user_trailingslashit( $wp_rewrite->pagination_base . '/%#%', 'paged' ) : '?paged=%#%';

	$defaults = array(
		'base'               => $pagenum_link, // http://example.com/all_posts.php%_% : %_% is replaced by format (below).
		'format'             => $format, // ?page=%#% : %#% is replaced by the page number
		'total'              => $total,
		'current'            => $current,
		'aria_current'       => 'page',
		'show_all'           => false,
		'prev_next'          => true,
		'prev_text'          => __( 'Previous &raquo;' ),
		'next_text'          => __( '&laquo; Next' ),
		'end_size'           => 1,
		'mid_size'           => 2,
		'type'               => 'plain',
		'add_args'           => array(), // array of query args to add.
		'add_fragment'       => '',
		'before_page_number' => '',
		'after_page_number'  => '',
	);

	$args = wp_parse_args( $args, $defaults );

	if ( ! is_array( $args['add_args'] ) ) {
		$args['add_args'] = array();
	}

	// Merge additional query vars found in the original URL into 'add_args' array.
	if ( isset( $url_parts[1] ) ) {
		// Find the format argument.
		$format       = explode( '?', str_replace( '%_%', $args['format'], $args['base'] ) );
		$format_query = isset( $format[1] ) ? $format[1] : '';
		wp_parse_str( $format_query, $format_args );

		// Find the query args of the requested URL.
		wp_parse_str( $url_parts[1], $url_query_args );

		// Remove the format argument from the array of query arguments, to avoid overwriting custom format.
		foreach ( $format_args as $format_arg => $format_arg_value ) {
			unset( $url_query_args[ $format_arg ] );
		}

		$args['add_args'] = array_merge( $args['add_args'], urlencode_deep( $url_query_args ) );
	}

	// Who knows what else people pass in $args.
	$total = (int) $args['total'];
	if ( $total < 2 ) {
		return;
	}
	$current  = (int) $args['current'];
	$end_size = (int) $args['end_size']; // Out of bounds?  Make it the default.
	if ( $end_size < 1 ) {
		$end_size = 1;
	}
	$mid_size = (int) $args['mid_size'];
	if ( $mid_size < 0 ) {
		$mid_size = 2;
	}
	$add_args   = $args['add_args'];
	$r          = '';
	$page_links = array();
	$dots       = false;

	if ( $args['prev_next'] && $current && $current < $total ) :
		$link = str_replace( '%_%', $total - 1 === $current ? '' : $args['format'], $args['base'] );
		$link = str_replace( '%#%', $current + 1, $link );
		if ( $add_args ) {
			$link = add_query_arg( $add_args, $link );
		}
		$link .= $args['add_fragment'];

		/**
		 * Filters the paginated links for the given archive pages.
		 *
		 * @since 3.0.0
		 *
		 * @param string $link The paginated link URL.
		 */
		$page_links[] = '<a class="prev page-numbers" href="' . esc_url( apply_filters( 'paginate_links', $link ) ) . '">' . $args['next_text'] . '</a>';
	endif;
	for ( $n = $total; $n >= 1; $n-- ) :
		if ( $n === $current ) :
			$page_links[] = "<span aria-current='" . esc_attr( $args['aria_current'] ) . "' class='page-numbers current'>" . $args['before_page_number'] . number_format_i18n( $n ) . $args['after_page_number'] . '</span>';
			$dots         = true;
		else :
			// This logic is weird and needs reworking.
			if (
				$args['show_all']
				|| (
					$n <= $end_size
					|| (
						$current
						&& $n > $current - $mid_size
						&& $n < $current + $mid_size
					)
					|| $n > $total - $end_size
				)
			) :
				$link = str_replace( '%_%', $total === $n ? '' : $args['format'], $args['base'] );
				$link = str_replace( '%#%', $n, $link );
				if ( $add_args ) {
					$link = add_query_arg( $add_args, $link );
				}
				$link .= $args['add_fragment'];

				/** This filter is documented in wp-includes/general-template.php */
				$page_links[] = "<a class='page-numbers' href='" . esc_url( apply_filters( 'paginate_links', $link ) ) . "'>" . $args['before_page_number'] . number_format_i18n( $n ) . $args['after_page_number'] . '</a>';
				$dots         = true;
			elseif ( $dots && ! $args['show_all'] ) :
				$page_links[] = '<span class="page-numbers dots">' . __( '&hellip;' ) . '</span>';
				$dots         = false;
			endif;
		endif;
	endfor;
	if ( $args['prev_next'] && $current && 1 < $current ) :
		$link = str_replace( '%_%', $args['format'], $args['base'] );
		$link = str_replace( '%#%', $current - 1, $link );
		if ( $add_args ) {
			$link = add_query_arg( $add_args, $link );
		}
		$link .= $args['add_fragment'];

		/** This filter is documented in wp-includes/general-template.php */
		$page_links[] = '<a class="next page-numbers" href="' . esc_url( apply_filters( 'paginate_links', $link ) ) . '">' . $args['prev_text'] . '</a>';
	endif;
	switch ( $args['type'] ) {
		case 'array':
			return $page_links;

		case 'list':
			$r .= "<ul class='page-numbers'>\n\t<li>";
			$r .= join( "</li>\n\t<li>", $page_links );
			$r .= "</li>\n</ul>\n";
			break;

		default:
			$r = join( "\n", $page_links );
			break;
	}
	return $r;
}
