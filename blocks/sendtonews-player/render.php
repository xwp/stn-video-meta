<?php
/**
 * Server-side render for the STN Video Player block.
 *
 * @package STN\VideoMeta
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

$key = $attributes['embedKey'] ?? '';

if ( '' === $key ) {
	return;
}

echo do_shortcode( '[sendtonews key="' . esc_attr( $key ) . '" type="float"]' );
