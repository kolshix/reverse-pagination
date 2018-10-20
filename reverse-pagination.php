<?php
/*
Plugin Name: WP Reverse Pagination
Description: Pagination starts higher and goes lower for more consistent archive page numbering.
Author: Russell Heimlich
Version: 0.1
*/

class WP_Reverse_Pagination {

	private $new_paged = 0;
	private $original_paged = 0;
	private $max_num_pages = 0;

	public function _construct() {}

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

	public function setup_actions() {
		add_action( 'pre_get_posts', array( $this, 'action_pre_get_posts' ) );
		add_action( 'template_redirect', array( $this, 'action_template_redirect' ) );
		add_action( 'wp_head', array( $this, 'action_wp_head' ), 1 );
	}

	public function setup_filters() {
		add_filter( 'redirect_canonical', array( $this, 'filter_redirect_canonical' ), 10, 2 );
		add_filter( 'posts_request', array( $this, 'filter_posts_request' ), 10, 2 );
	}

	// This will help in figuring out how to make this work https://ozthegreat.io/wordpress/wordpress-database-queries-speed-sql_calc_found_rows
	public function action_pre_get_posts( $query ) {
		global $wpdb;
		if ( is_admin() || ! $query->is_main_query() ) {
			return $query;
		}

		$q = $query->query_vars;
		$query->set( 'no_found_rows', 1 );

		if ( empty( $q['order'] ) || strtoupper( $q['order'] ) != 'DESC' ) {
			$q['order'] = 'ASC';
		}
		if ( ! $q['paged'] ) {
			// $q['order'] = 'DESC';
		}

		// $wp_query->set( 'order', $q['order'] );
	}

	public function action_template_redirect() {

		// When the requested paged value == the max_num_pages value then perform
		// a redirect to the non /page/ version of the URL for SEO reasons
		if ( $this->original_paged == $this->max_num_pages ) {
			$url = false;
			// Build the URL in the address bar
			if ( isset( $_SERVER['HTTP_HOST'] ) ) {
				$url  = is_ssl() ? 'https://' : 'http://';
				$url .= $_SERVER['HTTP_HOST'];
				$url .= $_SERVER['REQUEST_URI'];
			}
			if ( ! $url ) {
				return;
			}

			$redirect = str_replace( '/page/' . $this->original_paged . '/', '', $url );
			wp_safe_redirect( $redirect, 301 );
			exit;
		}
	}

	public function action_wp_head() {
		if ( is_main_query() ) {
			// set_query_var( 'paged', $this->new_paged );
		}
	}

	public function filter_redirect_canonical( $redirect_url, $requested_url ) {
		if ( is_admin() ) {
			return;
		}

		if ( stristr( $requested_url, '/page/1/' ) ) {
			return $requested_url;
		}

		return $redirect_url;
	}

	public function filter_posts_request( $sql, $wp_query ) {
		global $wpdb;

		if ( ! $wp_query->is_main_query() ) {
			return $sql;
		}

		$original_sql = $sql;
		$q = $wp_query->query_vars;

		// Get the conditionals after the first FROM in the SQL statement
		$pieces = explode( 'FROM', $sql, $limit = 2 );
		$conditionals = $pieces[1];

		// Figure out how many total posts there are for this query
		$max_pages_sql = 'SELECT COUNT(*) FROM' . $conditionals;

		// The ORDER clause is undeeded for just a count
		$pieces = explode( ' ORDER ', $max_pages_sql );
		$max_pages_sql = trim( $pieces[0] );

		// Remove GROUP BY clause used in taxonomy queries
		$pieces = explode( ' GROUP BY ', $max_pages_sql );
		$max_pages_sql = trim( $pieces[0] );
		$found_posts = $wpdb->get_var( $max_pages_sql );

		// Set how many found posts there are since we know it now
		$wp_query->found_posts = intval( $found_posts );
		$wp_query->found_posts = apply_filters_ref_array( 'found_posts', array( $wp_query->found_posts, &$wp_query ) );

		// Set the max number of pages
		$posts_per_page = intval( $q['posts_per_page'] );
		if ( ! $posts_per_page ) {
			$posts_per_page = get_option( 'posts_per_page' );
		}
		$wp_query->max_num_pages = ceil( $wp_query->found_posts / $posts_per_page );

		$paged = $q['paged'];
		$new_paged = intval( $wp_query->max_num_pages - $paged ) + 1;
		if ( $paged == 0 ) {
			$new_paged = intval( $wp_query->max_num_pages );
		}
		$new_offset = ( $wp_query->max_num_pages - $paged ) * $posts_per_page;

		$this->max_num_pages = intval( $wp_query->max_num_pages );
		$this->new_paged = $new_paged;
		$this->original_paged = $paged;
		// $wp_query->query['paged'] = $new_paged;
		// $wp_query->query_vars['paged'] = $new_paged;

		$old_offset = ( $paged * $posts_per_page) - $posts_per_page;

		$find = 'LIMIT ' . $old_offset . ', ' . $posts_per_page;
		$replace = 'LIMIT ' . $new_offset . ', ' . $posts_per_page;
		$sql = str_replace( $find, $replace, $sql );

		return $sql;
	}

}

// Kick things off!
WP_Reverse_Pagination::get_instance();

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
		'base'               => $pagenum_link, // http://example.com/all_posts.php%_% : %_% is replaced by format (below)
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
		'add_args'           => array(), // array of query args to add
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
		$format = explode( '?', str_replace( '%_%', $args['format'], $args['base'] ) );
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

	// Who knows what else people pass in $args
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
	$add_args = $args['add_args'];
	$r = '';
	$page_links = array();
	$dots = false;

	if ( $args['prev_next'] && $current && $current < $total ) :
		$link = str_replace( '%_%', $total - 1 == $current ? '' : $args['format'], $args['base'] );
		$link = str_replace( '%#%', $current + 1, $link );
		if ( $add_args )
			$link = add_query_arg( $add_args, $link );
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
		if ( $n == $current ) :
			$page_links[] = "<span aria-current='" . esc_attr( $args['aria_current'] ) . "' class='page-numbers current'>" . $args['before_page_number'] . number_format_i18n( $n ) . $args['after_page_number'] . "</span>";
			$dots = true;
		else :
			// This logic is weird and needs reworking
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
				$link = str_replace( '%_%', $total == $n ? '' : $args['format'], $args['base'] );
				$link = str_replace( '%#%', $n, $link );
				if ( $add_args )
					$link = add_query_arg( $add_args, $link );
				$link .= $args['add_fragment'];

				/** This filter is documented in wp-includes/general-template.php */
				$page_links[] = "<a class='page-numbers' href='" . esc_url( apply_filters( 'paginate_links', $link ) ) . "'>" . $args['before_page_number'] . number_format_i18n( $n ) . $args['after_page_number'] . "</a>";
				$dots = true;
			elseif ( $dots && ! $args['show_all'] ) :
				$page_links[] = '<span class="page-numbers dots">' . __( '&hellip;' ) . '</span>';
				$dots = false;
			endif;
		endif;
	endfor;
	if ( $args['prev_next'] && $current && 1 < $current ) :
		$link = str_replace( '%_%', $args['format'], $args['base'] );
		$link = str_replace( '%#%', $current - 1, $link );
		if ( $add_args )
			$link = add_query_arg( $add_args, $link );
		$link .= $args['add_fragment'];

		/** This filter is documented in wp-includes/general-template.php */
		$page_links[] = '<a class="next page-numbers" href="' . esc_url( apply_filters( 'paginate_links', $link ) ) . '">' . $args['prev_text'] . '</a>';
	endif;
	switch ( $args['type'] ) {
		case 'array' :
			return $page_links;

		case 'list' :
			$r .= "<ul class='page-numbers'>\n\t<li>";
			$r .= join("</li>\n\t<li>", $page_links);
			$r .= "</li>\n</ul>\n";
			break;

		default :
			$r = join("\n", $page_links);
			break;
	}
	return $r;
}
