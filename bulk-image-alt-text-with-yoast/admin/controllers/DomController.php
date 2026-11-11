<?php

namespace Pagup\Bialty\Controllers;

use Pagup\Bialty\Core\Option;
use Pagup\Bialty\Traits\DomHelper;
/**
 * Image tag as DomHelper expects it (getAttribute/setAttribute), backed by the
 * WP_HTML_Tag_Processor positioned on that tag. Only the attribute is edited,
 * in place: nothing else in the markup is parsed again or serialized again.
 */
final class DomImageTag {
    private $processor;

    private $updated = array();

    public function __construct( $processor ) {
        $this->processor = $processor;
    }

    /**
     * Like DOMElement::getAttribute(): a missing attribute, or one without a value, reads as an empty string.
     */
    public function getAttribute( $name ) {
        if ( isset( $this->updated[$name] ) ) {
            return $this->updated[$name];
        }
        $value = $this->processor->get_attribute( $name );
        return ( is_string( $value ) ? $value : '' );
    }

    public function setAttribute( $name, $value ) {
        $value = (string) $value;
        if ( $this->processor->set_attribute( $name, $value ) ) {
            $this->updated[$name] = $value;
        }
    }

}

class DomController {
    use DomHelper;
    public function __construct() {
        add_filter( 'the_content', array(&$this, 'bialty'), 99999 );
        add_filter( 'woocommerce_single_product_image_thumbnail_html', array(&$this, 'bialty_woocommerce_gallery'), 99999 );
        add_filter( 'post_thumbnail_html', array(&$this, 'bialty'), 99999 );
        add_filter(
            'woocommerce_related_products_args',
            array(&$this, 'bialty_prepare_related_products'),
            10,
            1
        );
        add_action(
            'woocommerce_before_template_part',
            array(&$this, 'start_related_products_buffer'),
            10,
            4
        );
        add_action(
            'woocommerce_after_template_part',
            array(&$this, 'end_related_products_buffer'),
            10,
            4
        );
    }

	/**
	 * Determine whether the current request is a WooCommerce transactional screen.
	 *
	 * @return bool True for checkout, cart, or account requests.
	 */
	private function bialty_is_transactional_context() {
		return ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() );
	}

    public function bialty( $content ) {
		if ( empty( $content ) || $this->bialty_is_transactional_context() ) {
			return $content;
		}

        // global $post;
        // Disable Bialty on Homepage if option is enabled
        if ( Option::check( 'disable_home' ) && (is_front_page() || is_home()) ) {
            return $content;
        }
        // Disable Bialty if URL exist in Blacklist
        // $post_id = $post->IDs;
        $post_id = get_queried_object_id();
        if ( !empty( $post_id ) && is_numeric( $post_id ) && in_array( (int) $post_id, $this->blacklist() ) ) {
            return $content;
        }
        // Check and Disable Bialty if page is edited by Beaver Builder.
        if ( isset( $_GET['fl_builder'] ) ) {
            return $content;
        }
        // Alt attributes are edited in place with the WordPress HTML API (WordPress 6.2+).
        // Without it, or when the filtered value is not a string, the content is returned as received.
        if ( !is_string( $content ) || !class_exists( 'WP_HTML_Tag_Processor' ) ) {
            return $content;
        }
        $post_types = ( Option::check( 'post_types' ) ? array_intersect( Option::get( 'post_types' ), ['post', 'page'] ) : ['post', 'page'] );
        $html = new \WP_HTML_Tag_Processor($content);
        // Upper case: WordPress 6.2.0 matches a lower-case query against lower-case tags only.
        while ( $html->next_tag( 'IMG' ) ) {
            $node = new DomImageTag($html);
            // Return image URL
            $img_url = $node->getAttribute( "src" );
            // Set alt for Post & Pages
            if ( is_singular( $post_types ) ) {
                if ( empty( $node->getAttribute( 'alt' ) ) ) {
                    if ( Option::check( 'alt_empty' ) ) {
                        $this->setEmpty( 'alt_empty', $node, $img_url );
                    }
                } else {
                    if ( Option::check( 'alt_not_empty' ) ) {
                        $this->setNotEmpty( 'alt_not_empty', $node, $img_url );
                    }
                }
                // Set custom keyword for all alt tags
                if ( Option::post_meta( 'use_bialty_alt' ) == true && !empty( Option::post_meta( 'bialty_cs_alt' ) ) ) {
                    $node->setAttribute( "alt", Option::post_meta( 'bialty_cs_alt' ) );
                }
            }
        }
        // Set alt for Post/Pages
        if ( is_singular( $post_types ) ) {
            if ( empty( Option::post_meta( 'disable_bialty' ) ) ) {
                $content = $this->bialty_container( $html->get_updated_html() );
            }
        }
        return $content;
    }

	/**
	 * Wrap processed markup in the container the plugin has always output around it.
	 *
	 * The container used to be a by-product of the former libxml round trip. It is kept byte for byte,
	 * so themes and stylesheets still find the same element. Debug mode never had it.
	 *
	 * @param string $html Markup whose alt attributes were processed.
	 * @return string
	 */
	private function bialty_container( $html ) {
		if ( Option::check( 'debug_mode' ) ) {
			return $html;
		}
		return '<div class="bialty-container">' . $html . "</div>\n";
	}

    public function bialty_woocommerce_gallery( $content ) {
        return $content;
    }

    public function bialty_prepare_related_products( $args ) {
        // Flag that we're in related products context
        $GLOBALS['bialty_in_related'] = true;
        return $args;
    }

    public function start_related_products_buffer(
        $template_name,
        $template_path,
        $located,
        $args
    ) {
        if ( $template_name === 'single-product/related.php' ) {
            ob_start();
        }
    }

    public function end_related_products_buffer(
        $template_name,
        $template_path,
        $located,
        $args
    ) {
        if ( $template_name === 'single-product/related.php' ) {
            $html = ob_get_clean();
            echo $html;
            return;
        }
    }

    /**
     * Register a custom filter to apply Bialty alt text functionality
     * 
     * @param string $filter_name The name of the filter to hook into
     * @param int $priority Priority of the filter (default: 99999)
     * @return void
     */
    public function register_custom_filter( $filter_name, $priority = 99999 ) {
        // Add the filter using the main bialty method
        add_filter( $filter_name, array($this, 'bialty'), $priority );
    }

    /**
     * Register multiple custom filters at once
     * 
     * @param array $filters Array of filter names or arrays with [name, priority]
     * @return void
     */
    public function register_custom_filters( $filters ) {
        foreach ( $filters as $filter ) {
            if ( is_array( $filter ) ) {
                $this->register_custom_filter( $filter[0], $filter[1] ?? 99999 );
            } else {
                $this->register_custom_filter( $filter );
            }
        }
    }

}

$DomController = new DomController();