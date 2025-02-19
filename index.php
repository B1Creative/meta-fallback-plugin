<?php
/**
 * Plugin Name: Meta Fallback
 * Description: A simple plugin to add meta tags to your website if Yoast SEO is not active.
 * Version: 1.2.1
 * Author: B1 Creative
 * Author URI: https://b1creative.com
 */

// Exit if accessed directly
if( ! defined( 'ABSPATH' ) ) {
    exit;
}

// functions Function
function b1_mf__kill(mixed $var) : never {
    echo '<pre>';
    var_dump($var);
    echo '</pre>';
    exit;
}
function b1_mf__truncate(string $text, int $length = 160, string $appendix = '...'): string {
    $str = strip_tags( $text );
    $str = substr( $str, 0, $length );
    $str = rtrim( $str, ".,!?" );
    $str = substr( $str, 0, strrpos( $str, ' ' ) );
    $str = trim(preg_replace('/\s+/', ' ', $str));
    return $str . $appendix;
}
function b1_mf__remove_wordpress_blocks(string $content): string{
    $str = preg_replace('/<!--.*-->/', '', $content); // Removing Comments (AKA Blocks)
    $str = preg_replace('/\[.*]/', '', $str); // Removing Shortcodes
    $str = strip_tags($str); // Removing HTML
    return trim($str);
}
function b1_mf__get_meta_description(WP_Post $post, string $site_name, bool $singular = false, bool $acf_is_active = false): string {
    $acf_field_used = false;
    $desc = '';
    if($singular){
        $desc = b1_mf__remove_wordpress_blocks($post->post_content); // Try and use post content
    }
    if(!$desc && $acf_is_active) {
        $desc = get_field(B1_MF_ACF_DESC, 'option'); // see if we have ACF and try and use the fallback
        if($desc) $acf_field_used = true;
    }
    if($singular && !$desc) {
       $desc = trim($post->post_excerpt); // if not use the excerpt
    }
    if(!$desc) $desc = get_option('blogdescription'); // if no excerpt use site tagline
    if(!$desc) $desc = $site_name; // if no tagline use the blog name again.
    return esc_attr(b1_mf__truncate($desc, 160, $acf_field_used ? '' : '...'));
}
function b1_mf__get_meta_image(WP_Post $post, bool $singular = false, bool $acf_is_active = false) : string {
    $image = '';

    if($singular){
        $image = esc_attr(get_the_post_thumbnail_url($post->ID, 'full'));
    }
    // If no post thumbnail and ACF is active, try to use the fallback
    if(!$image && $acf_is_active){
        $image = get_field(B1_MF_ACF_IMAGE, 'option');
    }
    // If no post thumbnail still, use the site logo as a backup
    if(!$image && has_custom_logo()){
        $logo = get_theme_mod( 'custom_logo' );
        $attachment = wp_get_attachment_image_src( $logo , 'full' );
        $image = esc_attr($attachment[0]);
    }

    return $image;
}

// Check if Yoast SEO is active
$yoast_plugin_key = 'wordpress-seo/wp-seo.php'; // Yoast SEO Plugin Key
$acf_plugin_key = 'advanced-custom-fields-pro/acf.php'; // Advanced Custom Fields Pro Plugin Key
$plugin_keys = get_option('active_plugins'); // Get active plugins

$acf_is_active = in_array($acf_plugin_key, $plugin_keys);
const B1_MF_ACF_IMAGE = 'b1_mf_image';
const B1_MF_ACF_DESC = 'b1_mf_description';
const B1_MF_ACF_SETTINGS_PAGE = 'b1-meta-fallback-settings';
// Had to use define here instead of const
// due to const not being able to assign to
// the return of a function at the time of writing
define("B1_MF_PLUGIN_KEY", plugin_basename(__FILE__));

// If ACF Pro is active, create options page
if($acf_is_active){
    add_action('acf/init', function(){
        // Create Settings Page
        $meta_fallback_page = acf_add_options_page([
            'page_title' => 'Meta Fallback Settings',
            'menu_title' => 'Meta Fallback',
            'menu_slug' => B1_MF_ACF_SETTINGS_PAGE,
            'capability' => 'edit_posts'
        ]);


        // Add Fields and Group to the Settings Page
        acf_add_local_field_group(array (
            'key' => 'b1_meta_fallback_settings',
            'title' => 'Default Fallbacks Settings',
            'fields' => [
                [
                    'key' => 'field_'.B1_MF_ACF_IMAGE,
                    'label' => 'Sharing Image',
                    'name' => B1_MF_ACF_IMAGE,
                    'type' => 'image',
                    'instructions' => 'This is the image we will use if your page doesn\'t contain a featured_image.',
                    'return_format' => 'url'
                ],
                [
                    'key' => 'field_'.B1_MF_ACF_DESC,
                    'label' => 'Description',
                    'name' => B1_MF_ACF_DESC,
                    'type' => 'textarea',
                    'instructions' => 'Note: 160 max characters - This is the text we will use if your page doesn\'t contain any actual post content.',
                    'maxlength' => '160',
                    'rows' => 5,
                ]
            ],
            'location' => [
                [
                    [
                        'param' => 'options_page',
                        'operator' => '==',
                        'value' => B1_MF_ACF_SETTINGS_PAGE,
                    ],
                ]
            ],
            'menu_order' => 0,
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen' => '',
        ));
    });
    add_filter('plugin_action_links_'.B1_MF_PLUGIN_KEY, function($links){
        $settings_url = esc_url(add_query_arg('page', B1_MF_ACF_SETTINGS_PAGE, get_admin_url('admin.php')));
        $settings_link = "<a href='{$settings_url}'>". __('Settings') ."</a>";
        $links[] = $settings_link;
        return $links;
    });
}

// If Yoast SEO is not active, add the meta fallback
if( ! in_array( $yoast_plugin_key, $plugin_keys ) ) {
    add_action( 'wp_head', function () use ($acf_is_active) {
        meta_fallback($acf_is_active);
    });
}

// Actual fallback logic;
function meta_fallback(bool $acf_is_active = false) : void
{
    global $post;
    global $wp;
    
    // Constants
    $site_name = get_bloginfo('name');
    $singular = is_singular();
    $domain = get_bloginfo('url');

    // Vars depending on singularity
    $url = $singular ? get_permalink($post->ID) : home_url($wp->request);
    $title = $singular ? $post->post_title . ' | '.$site_name : $site_name;
    $type = $singular && $post->post_type == 'post' ? 'article' : 'website';


    // Formatting    
    $title = esc_attr($title);
    $url = esc_url($url);
    $domain = esc_url($domain);
    $type = esc_attr($type);

    // Get More Complex Data
    $description = b1_mf__get_meta_description($post, $site_name, $singular, $acf_is_active);
    $image = b1_mf__get_meta_image($post, $singular, $acf_is_active);

    // ==== OUTPUT META TAGS ====

    // Basic Meta Description
    echo "<meta name='description' content='{$description}'>";

    // Open Graph Meta
    echo "<meta property='og:url' content='{$url}'>";
    echo "<meta property='og:type' content='{$type}'>";
    echo "<meta property='og:title' content='{$title}'>";
    echo "<meta property='og:description' content='{$description}'>";
    echo "<meta property='og:image' content='{$image}'>";

    // Twitter Card Meta
    echo "<meta name='twitter:card' content='summary_large_image'>";
    echo "<meta property='twitter:domain' content='{$domain}'>";
    echo "<meta property='twitter:url' content='{$url}'>";
    echo "<meta name='twitter:title' content='{$title}'>";
    echo "<meta name='twitter:description' content='{$description}'>";
    echo "<meta name='twitter:image' content='{$image}'>";
}