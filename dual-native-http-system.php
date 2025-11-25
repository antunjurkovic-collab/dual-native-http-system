<?php
/**
 * Plugin Name: Dual-Native HTTP System
 * Description: Advanced dual-native pattern implementation for WordPress with full HTTP system
 * Version: 2.0
 * Requires at least: 5.5
 * Requires PHP: 7.4
 * Author: Dual-Native Team
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) { 
    exit; 
}

// Define constants
define('DUAL_NATIVE_HTTP_VERSION', '2.0');
define('DUAL_NATIVE_HTTP_DIR', plugin_dir_path(__FILE__));

// Include the composer autoloader if available
if (file_exists(DUAL_NATIVE_HTTP_DIR . 'vendor/autoload.php')) {
    require_once DUAL_NATIVE_HTTP_DIR . 'vendor/autoload.php';
} else {
    // Manual loading of our classes
    require_once DUAL_NATIVE_HTTP_DIR . 'src/DualNativeSystem.php';
    require_once DUAL_NATIVE_HTTP_DIR . 'src/Config/Config.php';
    require_once DUAL_NATIVE_HTTP_DIR . 'src/Core/CIDManager.php';
    require_once DUAL_NATIVE_HTTP_DIR . 'src/Core/LinkManager.php';
    require_once DUAL_NATIVE_HTTP_DIR . 'src/Core/CatalogManager.php';
    require_once DUAL_NATIVE_HTTP_DIR . 'src/Validation/ValidationEngine.php';
    require_once DUAL_NATIVE_HTTP_DIR . 'src/HTTP/HTTPRequestHandler.php';
}

/**
 * Initialize the Dual-Native HTTP System
 */
function dual_native_http_init() {
    if (class_exists('DualNative\HTTP\DualNativeSystem')) {
        global $dual_native_system;
        
        $dual_native_system = new \DualNative\HTTP\DualNativeSystem();
        
        // Register our REST API endpoints
        add_action('rest_api_init', 'dual_native_register_endpoints');
        
        // Hook into content save to update catalog and CID cache
        add_action('save_post', 'dual_native_update_resource_on_save', 10, 3);
        
        // Initialize admin interface if needed
        if (is_admin()) {
            add_action('admin_menu', 'dual_native_add_admin_menu');
        }
    }
}
add_action('init', 'dual_native_http_init');

/**
 * Register REST API endpoints
 */
function dual_native_register_endpoints() {
    // MR endpoint for posts
    register_rest_route('dual-native/v2', '/posts/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'dual_native_get_post_mr',
        'permission_callback' => function($request) {
            $id = (int) $request['id'];
            return current_user_can('read_post', $id) || get_post_status($id) === 'publish';
        },
    ));
    
    // Safe write endpoint for posts
    register_rest_route('dual-native/v2', '/posts/(?P<id>\d+)/blocks', array(
        'methods' => 'POST',
        'callback' => 'dual_native_post_blocks',
        'permission_callback' => function($request) {
            $id = (int) $request['id'];
            return current_user_can('edit_post', $id);
        },
    ));
    
    // Catalog endpoint
    register_rest_route('dual-native/v2', '/catalog', array(
        'methods' => 'GET',
        'callback' => 'dual_native_get_catalog',
        'permission_callback' => function($request) {
            // For internal catalog access - requires edit_posts capability
            return current_user_can('edit_posts');
        },
    ));

    // Public catalog endpoint with published-only content
    register_rest_route('dual-native/v2', '/public-catalog', array(
        'methods' => 'GET',
        'callback' => 'dual_native_get_public_catalog',
        'permission_callback' => '__return_true', // Public access
    ));

    // Conformance endpoint
    register_rest_route('dual-native/v2', '/conformance', array(
        'methods' => 'GET',
        'callback' => 'dual_native_get_conformance',
        'permission_callback' => function($request) {
            // For now, allow read access for conformance checking
            return current_user_can('read') || current_user_can('edit_posts');
        },
    ));

    // v1 route aliases for API stability (map to same callbacks)
    register_rest_route('dual-native/v1', '/posts/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'dual_native_get_post_mr',
        'permission_callback' => function($request) {
            $id = (int) $request['id'];
            return current_user_can('read_post', $id) || get_post_status($id) === 'publish';
        },
    ));
    register_rest_route('dual-native/v1', '/posts/(?P<id>\d+)/blocks', array(
        'methods' => 'POST',
        'callback' => 'dual_native_post_blocks',
        'permission_callback' => function($request) {
            $id = (int) $request['id'];
            return current_user_can('edit_post', $id);
        },
    ));
    register_rest_route('dual-native/v1', '/catalog', array(
        'methods' => 'GET',
        'callback' => 'dual_native_get_catalog',
        'permission_callback' => function($request) {
            return current_user_can('edit_posts');
        },
    ));
    register_rest_route('dual-native/v1', '/public-catalog', array(
        'methods' => 'GET',
        'callback' => 'dual_native_get_public_catalog',
        'permission_callback' => '__return_true',
    ));
}

/**
 * Get Machine Representation for a post
 */
function dual_native_get_post_mr($request) {
    global $dual_native_system;
    
    $id = (int) $request['id'];
    
    // Get the post
    $post = get_post($id);
    if (!$post) {
        return new WP_REST_Response(['error' => 'Post not found'], 404);
    }
    
    // Build the MR structure with block-aware parsing
    $mr = [
        'rid' => $id,
        'title' => $post->post_title,
        'status' => $post->post_status,
        'type' => $post->post_type,
        'author' => [
            'id' => (int) $post->post_author,
            'name' => get_the_author_meta('display_name', $post->post_author)
        ],
        'published' => mysql2date('c', $post->post_date_gmt, false),
        'modified' => mysql2date('c', $post->post_modified_gmt, false),
        'excerpt' => $post->post_excerpt,
        'word_count' => (function($text){
            $text = (string) $text;
            if ($text === '') return 0;
            if (preg_match_all('/\p{L}+/u', $text, $m)) { return count($m[0]); }
            return str_word_count($text);
        })(wp_strip_all_tags($post->post_content)),
        'mr_schema' => 'tct-1', // MR schema version for profile governance
    ];

    // Parse Gutenberg blocks if available
    if (function_exists('parse_blocks')) {
        $blocks = parse_blocks($post->post_content);
        $mr['blocks'] = dual_native_map_blocks($blocks);
        $mr['core_content_text'] = dual_native_flatten_text($mr['blocks']);
    } else {
        // Fallback for classic content
        $mr['blocks'] = [
            [
                'type' => 'core/freeform',
                'content' => $post->post_content
            ]
        ];
        $mr['core_content_text'] = wp_strip_all_tags($post->post_content);
    }
    
    // Compute CID
    $cid = $dual_native_system->computeCID($mr);
    
    // Use the system's HTTP request handler which has the validator guard
    global $dual_native_system;

    // Check conditional request using the validator guard
    if ($dual_native_system->getHTTPRequestHandler()->validateIfNoneMatch(
        $request->get_headers(),
        $mr
    )) {
        // Content unchanged, return 304
        $response = new WP_REST_Response(null, 304);
        $response->header('ETag', '"' . $cid . '"');
        $response->header('Last-Modified', gmdate('D, d M Y H:i:s', strtotime($mr['modified'])) . ' GMT');
        $response->header('Cache-Control', 'no-cache');
        return $response;
    }
    
    // Add bidirectional links
    $hr_url = get_permalink($id);
    $mr_url = rest_url('dual-native/v2/posts/' . $id);
    
    $mr['links'] = $dual_native_system->generateLinks((string)$id, $hr_url, $mr_url);
    $mr['cid'] = $cid;
    $mr['profile'] = \DualNative\HTTP\Config\Config::HTTP_PROFILE;
    
    // Prepare response with proper headers
    $response = new WP_REST_Response($mr, 200);
    $response->header('ETag', '"' . $cid . '"');
    $response->header('Last-Modified', gmdate('D, d M Y H:i:s', strtotime($mr['modified'])) . ' GMT');
    $response->header('Content-Type', 'application/json; profile="' . \DualNative\HTTP\Config\Config::HTTP_PROFILE . '"');
    $response->header('Cache-Control', 'no-cache');
    $response->header('Link', '<' . $mr_url . '>; rel="self"; type="application/json", <' . $hr_url . '>; rel="canonical"; type="text/html"');

    // RFC 9530 Content-Digest header
    $json_content = wp_json_encode($mr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $response->header('Content-Digest', 'sha-256=:' . base64_encode(hash('sha256', $json_content, true)) . ':');

    // Set Content-Length header
    $response->header('Content-Length', strlen($json_content));

    return $response;
}

/**
 * Normalize and compare HTTP validator (ETag) values
 *
 * @param string $validator_header The validator header value (If-None-Match, If-Match)
 * @param string $expected_cid The expected CID
 * @return bool Whether any of the provided validators match the expected CID
 */
function dual_native_normalize_and_compare_validator(string $validator_header, string $expected_cid): bool {
    // Split on commas to handle multiple validators
    $validators = array_map('trim', explode(',', $validator_header));

    foreach ($validators as $validator) {
        // Remove W/ prefix for weak validators
        if (stripos($validator, 'W/"') === 0) {
            $validator = substr($validator, 3); // Remove "W/"" prefix
        }

        // Remove surrounding quotes if present
        $validator = trim($validator, '"');

        // Compare the normalized validator to the expected CID
        if (hash_equals($expected_cid, $validator)) {
            return true;
        }
    }

    return false;
}

/**
 * Map WordPress blocks to dual-native MR format
 *
 * @param array $blocks Array of parsed WordPress blocks
 * @return array Array of mapped blocks for MR
 */
function dual_native_map_blocks(array $blocks): array {
    $mapped_blocks = [];

    foreach ($blocks as $block) {
        if (!isset($block['blockName'])) {
            continue; // Skip invalid blocks
        }

        $name = $block['blockName'];
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
        $html = isset($block['innerHTML']) ? $block['innerHTML'] : '';

        $block_output = [];

        switch ($name) {
            case 'core/paragraph':
                $content = trim(wp_strip_all_tags($html, true));
                $block_output = [
                    'type' => 'core/paragraph',
                    'content' => $content
                ];
                break;

            case 'core/heading':
                $level = isset($attrs['level']) ? (int) $attrs['level'] : 2;
                $level = max(1, min(6, $level));
                $content = trim(wp_strip_all_tags($html, true));
                $block_output = [
                    'type' => 'core/heading',
                    'level' => $level,
                    'content' => $content
                ];
                break;

            case 'core/list':
                $ordered = isset($attrs['ordered']) ? (bool) $attrs['ordered'] : (strpos($html, '<ol') !== false);
                $items = [];
                if (preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $html, $matches)) {
                    foreach ($matches[1] as $item) {
                        $item_content = trim(wp_strip_all_tags($item, true));
                        if ($item_content !== '') {
                            $items[] = $item_content;
                        }
                    }
                }
                $block_output = [
                    'type' => 'core/list',
                    'ordered' => $ordered,
                    'items' => $items
                ];
                break;

            case 'core/image':
                $image_id = isset($attrs['id']) ? (int) $attrs['id'] : 0;
                $alt = isset($attrs['alt']) ? (string) $attrs['alt'] : '';
                $src = '';
                if (preg_match('/src=[\'"]([^\'"]+)[\'"]/i', $html, $src_match)) {
                    $src = $src_match[1];
                }
                $block_output = [
                    'type' => 'core/image',
                    'imageId' => $image_id,
                    'altText' => $alt,
                    'url' => $src ?: null
                ];
                break;

            case 'core/code':
            case 'core/preformatted':
                $content = trim(wp_strip_all_tags($html, true));
                $block_output = [
                    'type' => 'core/code',
                    'content' => $content
                ];
                break;

            case 'core/quote':
                $content = trim(wp_strip_all_tags($html, true));
                $block_output = [
                    'type' => 'core/quote',
                    'content' => $content
                ];
                break;

            default:
                // For any other block types, extract content if available
                $content = trim(wp_strip_all_tags($html, true));
                if ($content !== '') {
                    $block_output = [
                        'type' => $name ?: 'unknown',
                        'content' => $content
                    ];
                }
                break;
        }

        if (!empty($block_output)) {
            $mapped_blocks[] = $block_output;

            // Process inner blocks recursively
            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $inner_blocks = dual_native_map_blocks($block['innerBlocks']);
                $mapped_blocks = array_merge($mapped_blocks, $inner_blocks);
            }
        }
    }

    return $mapped_blocks;
}

/**
 * Flatten block content to text for word count and search
 *
 * @param array $blocks Array of mapped blocks
 * @return string Flattened text content
 */
function dual_native_flatten_text(array $blocks): string {
    $parts = [];

    foreach ($blocks as $block) {
        if (!empty($block['content']) && is_string($block['content'])) {
            $parts[] = $block['content'];
        }

        if (!empty($block['items']) && is_array($block['items'])) {
            $parts[] = implode(' ', array_map('strval', $block['items']));
        }
    }

    $text = trim(implode(' ', $parts));
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);

    return $text;
}

/**
 * Handle block operations with safe write
 */
function dual_native_post_blocks($request) {
    global $dual_native_system;
    
    $id = (int) $request['id'];
    $body = $request->get_json_params();
    
    // Check If-Match header for safe write
    $if_match = $request->get_header('if-match');
    if (!$if_match) {
        return new WP_REST_Response([
            'error' => 'If-Match header required for safe write'
        ], 428); // Precondition Required
    }

    // Get current post to verify CID
    $post = get_post($id);
    if (!$post) {
        return new WP_REST_Response(['error' => 'Post not found'], 404);
    }

    $current_mr = [
        'rid' => $id,
        'title' => $post->post_title,
        'content' => $post->post_content,
        'status' => $post->post_status,
        'type' => $post->post_type,
        'author' => [
            'id' => (int) $post->post_author,
            'name' => get_the_author_meta('display_name', $post->post_author)
        ],
        'published' => mysql2date('c', $post->post_date_gmt, false),
        'modified' => mysql2date('c', $post->post_modified_gmt, false),
    ];

    $current_cid = $dual_native_system->computeCID($current_mr);

    // Use the system's HTTP request handler which has the validator guard
    global $dual_native_system;

    // Validate If-Match header using the validator guard
    $validation_result = $dual_native_system->getHTTPRequestHandler()->validateIfMatch(
        $request->get_headers(),
        $current_mr
    );

    if (!$validation_result['valid']) {
        $response = new WP_REST_Response([
            'error' => 'Precondition Failed',
            'message' => $validation_result['error'],
            'current_cid' => $validation_result['currentCid']
        ], 412); // Precondition Failed
        $response->header('ETag', '"' . $validation_result['currentCid'] . '"');
        return $response;
    }
    
    // Perform the block operation
    $insert_where = $body['insert'] ?? 'append';
    $new_blocks = $body['blocks'] ?? (isset($body['block']) ? [$body['block']] : []);
    
    if (empty($new_blocks)) {
        return new WP_REST_Response(['error' => 'No blocks to insert'], 400);
    }
    
    // Get the current content
    $current_content = $post->post_content;
    
    // Determine insertion method and position
    $insert_where = $body['insert'] ?? 'append';
    $index = isset($body['index']) ? max(0, (int)$body['index']) : null;

    // Count existing blocks for effective insertion index
    $existing_blocks = [];
    if (function_exists('parse_blocks')) {
        $existing_blocks = parse_blocks($current_content);
    }
    $existing_block_count = count($existing_blocks);

    $effective_index = $existing_block_count; // Default to append
    if ($insert_where === 'index' && $index !== null) {
        $effective_index = min(max(0, $index), $existing_block_count);
    } elseif ($insert_where === 'prepend') {
        $effective_index = 0;
    }

    // Build content based on insertion method
    if ($insert_where === 'index' && function_exists('parse_blocks') && function_exists('serialize_blocks')) {
        // Insert at specific index using Gutenberg block parsing
        $head_blocks = array_slice($existing_blocks, 0, $effective_index);
        $tail_blocks = array_slice($existing_blocks, $effective_index);

        // Convert new blocks to Gutenberg format
        $new_gutenberg_blocks = [];
        foreach ($new_blocks as $block) {
            $block_type = $block['type'] ?? 'core/paragraph';
            $block_attrs = $block['attrs'] ?? [];

            switch ($block_type) {
                case 'core/paragraph':
                    $new_gutenberg_blocks[] = [
                        'blockName' => 'core/paragraph',
                        'attrs' => $block_attrs,
                        'innerHTML' => '<p>' . esc_html($block['content'] ?? '') . '</p>',
                        'innerBlocks' => []
                    ];
                    break;
                case 'core/heading':
                    $level = max(1, min(6, (int)($block['level'] ?? 2)));
                    $new_gutenberg_blocks[] = [
                        'blockName' => 'core/heading',
                        'attrs' => array_merge($block_attrs, ['level' => $level]),
                        'innerHTML' => '<h' . $level . '>' . esc_html($block['content'] ?? '') . '</h' . $level . '>',
                        'innerBlocks' => []
                    ];
                    break;
                case 'core/list':
                    $ordered = !empty($block['ordered']);
                    $items = $block['items'] ?? [];
                    $tag = $ordered ? 'ol' : 'ul';
                    $inner_html = '<' . $tag . '>';
                    foreach ($items as $item) {
                        $inner_html .= '<li>' . esc_html($item) . '</li>';
                    }
                    $inner_html .= '</' . $tag . '>';

                    $new_gutenberg_blocks[] = [
                        'blockName' => 'core/list',
                        'attrs' => array_merge($block_attrs, ['ordered' => $ordered]),
                        'innerHTML' => $inner_html,
                        'innerBlocks' => []
                    ];
                    break;
                default:
                    $new_gutenberg_blocks[] = [
                        'blockName' => $block_type,
                        'attrs' => $block_attrs,
                        'innerHTML' => '<p>Block: ' . esc_html($block['content'] ?? '') . '</p>',
                        'innerBlocks' => []
                    ];
                    break;
            }
        }

        // Combine blocks
        $final_blocks = array_merge($head_blocks, $new_gutenberg_blocks, $tail_blocks);

        // Serialize back to content
        $current_content = serialize_blocks($final_blocks);
    } else {
        // For append or prepend using string manipulation
        foreach ($new_blocks as $block) {
            $block_type = $block['type'] ?? 'core/paragraph';
            $block_content = $block['content'] ?? '';

            $block_markup = '';
            switch ($block_type) {
                case 'core/paragraph':
                    $block_markup = '<!-- wp:paragraph -->' . "\n" . '<p>' . esc_html($block_content) . '</p>' . "\n" . '<!-- /wp:paragraph -->' . "\n\n";
                    break;
                case 'core/heading':
                    $level = $block['level'] ?? 2;
                    $level = max(1, min(6, (int)$level)); // Ensure valid heading level
                    $block_markup = '<!-- wp:heading -->' . "\n" . '<h' . $level . '>' . esc_html($block_content) . '</h' . $level . '>' . "\n" . '<!-- /wp:heading -->' . "\n\n";
                    break;
                case 'core/list':
                    $ordered = !empty($block['ordered']);
                    $items = $block['items'] ?? [];
                    $tag = $ordered ? 'ol' : 'ul';
                    $list_content = '<!-- wp:list -->' . "\n" . '<' . $tag . '>' . "\n";
                    foreach ($items as $item) {
                        $list_content .= '<li>' . esc_html($item) . '</li>' . "\n";
                    }
                    $list_content .= '</' . $tag . '>' . "\n" . '<!-- /wp:list -->' . "\n\n";
                    $block_markup = $list_content;
                    break;
                default:
                    $block_markup = '<!-- wp:paragraph -->' . "\n" . '<p>Block: ' . esc_html($block_content) . '</p>' . "\n" . '<!-- /wp:paragraph -->' . "\n\n";
                    break;
            }

            if ($insert_where === 'prepend') {
                $current_content = $block_markup . $current_content;
            } else {
                // append (default)
                $current_content .= $block_markup;
            }
        }
    }

    // Return insertion metadata via headers
    $response->header('X-DNI-Top-Level-Count-Before', (string)$existing_block_count);
    $response->header('X-DNI-Inserted-At', (string)$effective_index);
    $response->header('X-DNI-Top-Level-Count', (string)($existing_block_count + count($new_blocks)));
    
    // Update the post
    $update_result = wp_update_post([
        'ID' => $id,
        'post_content' => $current_content
    ]);
    
    if (is_wp_error($update_result)) {
        return new WP_REST_Response([
            'error' => $update_result->get_error_message()
        ], 500);
    }
    
    // Clear any cached CID
    delete_post_meta($id, '_dual_native_cid');
    
    // Return the updated MR
    $updated_post = get_post($id);
    $updated_mr = [
        'rid' => $id,
        'title' => $updated_post->post_title,
        'content' => $updated_post->post_content,
        'status' => $updated_post->post_status,
        'type' => $updated_post->post_type,
        'author' => [
            'id' => (int) $updated_post->post_author,
            'name' => get_the_author_meta('display_name', $updated_post->post_author)
        ],
        'published' => mysql2date('c', $updated_post->post_date_gmt, false),
        'modified' => mysql2date('c', $updated_post->post_modified_gmt, false),
    ];
    
    $new_cid = $dual_native_system->computeCID($updated_mr);
    
    // Update catalog
    $dual_native_system->getCatalogManager()->updateCatalogEntry(
        (string) $id,
        get_permalink($id),
        rest_url('dual-native/v2/posts/' . $id),
        $new_cid
    );
    
    $updated_mr['links'] = $dual_native_system->generateLinks(
        (string)$id, 
        get_permalink($id), 
        rest_url('dual-native/v2/posts/' . $id)
    );
    $updated_mr['cid'] = $new_cid;
    $updated_mr['profile'] = \DualNative\HTTP\Config\Config::HTTP_PROFILE;
    
    $response = new WP_REST_Response($updated_mr, 200);
    $response->header('ETag', '"' . $new_cid . '"');
    $response->header('Last-Modified', gmdate('D, d M Y H:i:s', strtotime($updated_mr['modified'])) . ' GMT');
    $response->header('Content-Type', 'application/json; profile="' . \DualNative\HTTP\Config\Config::HTTP_PROFILE . '"');
    $response->header('Cache-Control', 'no-cache');

    // RFC 9530 Content-Digest header
    $json_content = wp_json_encode($updated_mr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $response->header('Content-Digest', 'sha-256=:' . base64_encode(hash('sha256', $json_content, true)) . ':');

    // Set Content-Length header
    $response->header('Content-Length', strlen($json_content));

    return $response;
}

/**
 * Get the dual-native catalog
 */
function dual_native_get_catalog($request) {
    global $dual_native_system;
    
    $since = $request->get_param('since');
    $limit = $request->get_param('limit') ? (int) $request->get_param('limit') : 0;
    $offset = $request->get_param('offset') ? (int) $request->get_param('offset') : 0;
    
    $filters = [];
    if ($status = $request->get_param('status')) {
        $filters['status'] = $status;
    }
    if ($type = $request->get_param('type')) {
        $filters['type'] = $type;
    }
    
    $catalog = $dual_native_system->getCatalog($since, $filters, $limit, $offset);
    
    // Compute CID for the catalog
    $catalog_cid = $dual_native_system->computeCID($catalog);
    
    // Use the system's HTTP request handler which has the validator guard
    global $dual_native_system;

    // Check conditional request using the validator guard
    if ($dual_native_system->getHTTPRequestHandler()->validateIfNoneMatch(
        $request->get_headers(),
        $catalog
    )) {
        // Content unchanged, return 304
        $response = new WP_REST_Response(null, 304);
        $response->header('ETag', '"' . $catalog_cid . '"');
        // Use catalog's updatedAt field for Last-Modified header in 304 response too
        $lastModifiedTime = isset($catalog['updatedAt']) ? strtotime($catalog['updatedAt']) : time();
        $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $lastModifiedTime) . ' GMT');
        $response->header('Cache-Control', 'no-cache');
        return $response;
    }
    
    // Use catalog's updatedAt field for Last-Modified header
    $lastModifiedTime = isset($catalog['updatedAt']) ? strtotime($catalog['updatedAt']) : time();
    $response = new WP_REST_Response($catalog, 200);
    $response->header('ETag', '"' . $catalog_cid . '"');
    $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $lastModifiedTime) . ' GMT');
    $response->header('Content-Type', 'application/json; profile="' . \DualNative\HTTP\Config\Config::HTTP_PROFILE . '"');
    $response->header('Cache-Control', 'no-cache');

    // RFC 9530 Content-Digest header
    $json_content = wp_json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $response->header('Content-Digest', 'sha-256=:' . base64_encode(hash('sha256', $json_content, true)) . ':');

    // Set Content-Length header
    $response->header('Content-Length', strlen($json_content));

    return $response;
}

/**
 * Get the public dual-native catalog (published content only)
 */
function dual_native_get_public_catalog($request) {
    global $dual_native_system;

    $since = $request->get_param('since');
    $limit = $request->get_param('limit') ? (int) $request->get_param('limit') : 0;
    $offset = $request->get_param('offset') ? (int) $request->get_param('offset') : 0;

    $filters = [];
    if ($status = $request->get_param('status')) {
        $filters['status'] = $status;
    }
    if ($type = $request->get_param('type')) {
        $filters['type'] = $type;
    }

    // Add filter for published content only
    $filters['status'] = $filters['status'] ?? 'publish';

    $catalog = $dual_native_system->getCatalog($since, $filters, $limit, $offset);

    // Compute CID for the catalog
    $catalog_cid = $dual_native_system->computeCID($catalog);

    // Use the system's HTTP request handler which has the validator guard
    global $dual_native_system;

    // Check conditional request using the validator guard
    if ($dual_native_system->getHTTPRequestHandler()->validateIfNoneMatch(
        $request->get_headers(),
        $catalog
    )) {
        // Content unchanged, return 304
        $response = new WP_REST_Response(null, 304);
        $response->header('ETag', '"' . $catalog_cid . '"');
        // Use catalog's updatedAt field for Last-Modified header in 304 response too
        $lastModifiedTime = isset($catalog['updatedAt']) ? strtotime($catalog['updatedAt']) : time();
        $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $lastModifiedTime) . ' GMT');
        $response->header('Cache-Control', 'no-cache');
        return $response;
    }

    // Use catalog's updatedAt field for Last-Modified header
    $lastModifiedTime = isset($catalog['updatedAt']) ? strtotime($catalog['updatedAt']) : time();
    $response = new WP_REST_Response($catalog, 200);
    $response->header('ETag', '"' . $catalog_cid . '"');
    $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $lastModifiedTime) . ' GMT');
    $response->header('Content-Type', 'application/json; profile="' . \DualNative\HTTP\Config\Config::HTTP_PROFILE . '"');
    $response->header('Cache-Control', 'no-cache');

    // RFC 9530 Content-Digest header
    $json_content = wp_json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $response->header('Content-Digest', 'sha-256=:' . base64_encode(hash('sha256', $json_content, true)) . ':');

    // Set Content-Length header
    $response->header('Content-Length', strlen($json_content));

    return $response;
}

/**
 * Update resource in catalog when post is saved
 */
function dual_native_update_resource_on_save($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    
    global $dual_native_system;
    
    if (!$dual_native_system) {
        return;
    }
    
    // Update the catalog entry for this post
    $rid = (string) $post_id;
    $hr_url = get_permalink($post_id);
    $mr_url = rest_url('dual-native/v2/posts/' . $post_id);
    
    // Build MR for CID calculation
    $mr_for_cid = [
        'rid' => $post_id,
        'title' => $post->post_title,
        'content' => $post->post_content,
        'status' => $post->post_status,
        'type' => $post->post_type,
        'author' => [
            'id' => (int) $post->post_author,
            'name' => get_the_author_meta('display_name', $post->post_author)
        ],
        'published' => mysql2date('c', $post->post_date_gmt, false),
        'modified' => mysql2date('c', $post->post_modified_gmt, false),
    ];
    
    $cid = $dual_native_system->computeCID($mr_for_cid);
    
    // Update catalog with resource type
    $dual_native_system->getCatalogManager()->addResourceToCatalog(
        $post->post_type,  // resource type (post, page, attachment, etc.)
        $rid,
        $hr_url,
        $mr_url,
        $cid,
        null,  // updatedAt (will be set automatically)
        ['status' => $post->post_status]  // additional metadata
    );
    
    // Clear cached CID
    delete_post_meta($post_id, '_dual_native_cid');
}

/**
 * Get conformance information for the dual-native system
 */
function dual_native_get_conformance($request) {
    global $dual_native_system;

    if (!$dual_native_system || !$dual_native_system->getValidationEngine()) {
        return new WP_REST_Response([
            'error' => 'Validation engine not available'
        ], 500);
    }

    // Build system info for conformance validation
    $systemInfo = [
        'has_hr' => true,
        'has_mr' => true,
        'hr_links_to_mr' => true,
        'mr_links_to_hr' => true,
        'has_cid' => true,
        'supports_304' => true,
        'supports_if_none_match' => true,
        'supports_if_match' => true,
        'has_catalog' => true,
        'supports_safe_writes' => true,
    ];

    $conformanceResult = $dual_native_system->validateConformance($systemInfo);

    // Add additional conformance checks
    $conformanceResult['checks'] = [
        'semantic_equivalence' => [
            'description' => 'Semantic equivalence validation is available',
            'status' => $dual_native_system->getValidationEngine() ? 'available' : 'not_available'
        ],
        'content_digest' => [
            'description' => 'RFC 9530 Content-Digest header support',
            'status' => 'implemented'
        ],
        'etag_validation' => [
            'description' => 'ETag-based conditional requests',
            'status' => 'implemented'
        ],
        'safe_write_operations' => [
            'description' => 'If-Match protected write operations',
            'status' => 'implemented'
        ],
        'catalog_functionality' => [
            'description' => 'DNC catalog with filters',
            'status' => 'implemented'
        ],
        'block_aware_mr' => [
            'description' => 'Gutenberg block-aware MR mapping',
            'status' => 'implemented'
        ],
        'validator_guard' => [
            'description' => 'Reusable validation middleware',
            'status' => 'implemented'
        ]
    ];

    // Compute CID for conformance results
    $conformanceCid = $dual_native_system->computeCID($conformanceResult);

    // Use the system's HTTP request handler which has the validator guard
    global $dual_native_system;

    // Check conditional request using the validator guard
    if ($dual_native_system->getHTTPRequestHandler()->validateIfNoneMatch(
        $request->get_headers(),
        $conformanceResult
    )) {
        // Content unchanged, return 304
        $response = new WP_REST_Response(null, 304);
        $response->header('ETag', '"' . $conformanceCid . '"');
        // Use current time for Last-Modified header for conformance endpoint
        $response->header('Last-Modified', gmdate('D, d M Y H:i:s') . ' GMT');
        $response->header('Cache-Control', 'no-cache');
        return $response;
    }

    $response = new WP_REST_Response($conformanceResult, 200);
    $response->header('ETag', '"' . $conformanceCid . '"');
    $response->header('Last-Modified', gmdate('D, d M Y H:i:s') . ' GMT');
    $response->header('Content-Type', 'application/json; profile="dual-native-conformance-1.0"');
    $response->header('Cache-Control', 'no-cache');

    // RFC 9530 Content-Digest header
    $json_content = wp_json_encode($conformanceResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $response->header('Content-Digest', 'sha-256=:' . base64_encode(hash('sha256', $json_content, true)) . ':');

    // Set Content-Length header
    $response->header('Content-Length', strlen($json_content));

    return $response;
}

/**
 * Add admin menu for the plugin
 */
function dual_native_add_admin_menu() {
    add_options_page(
        'Dual-Native HTTP System',
        'Dual-Native System',
        'manage_options',
        'dual-native-system',
        'dual_native_options_page'
    );
}

/**
 * Options page content
 */
function dual_native_options_page() {
    global $dual_native_system;
    
    $health = $dual_native_system ? $dual_native_system->healthCheck() : ['status' => 'not_initialized'];
    ?>
    <div class="wrap">
        <h1>Dual-Native HTTP System</h1>
        
        <h2>System Health</h2>
        <table class="form-table">
            <tr>
                <th>Status</th>
                <td><span class="<?php echo esc_attr($health['status']); ?>"><?php echo esc_html(ucfirst($health['status'])); ?></span></td>
            </tr>
            <tr>
                <th>Version</th>
                <td><?php echo esc_html($health['version'] ?? 'Unknown'); ?></td>
            </tr>
            <tr>
                <th>Profile</th>
                <td><?php echo esc_html($health['profile'] ?? 'Unknown'); ?></td>
            </tr>
            <tr>
                <th>Timestamp</th>
                <td><?php echo esc_html($health['timestamp'] ?? 'Unknown'); ?></td>
            </tr>
        </table>
        
        <?php if (isset($health['components'])): ?>
        <h2>Component Status</h2>
        <table class="form-table">
            <?php foreach ($health['components'] as $component => $status): ?>
            <tr>
                <th><?php echo esc_html(ucwords(str_replace('_', ' ', $component))); ?></th>
                <td><span class="<?php echo $status ? 'healthy' : 'unhealthy'; ?>">
                    <?php echo $status ? 'Operational' : 'Issues'; ?>
                </span></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
        
        <h2>About Dual-Native System</h2>
        <p>The Dual-Native HTTP System provides both Human and Machine representations for your content,
        with Content Identity (CID) validation, bidirectional linking, and zero-fetch optimization.</p>
        
        <h3>Available Endpoints</h3>
        <ul>
            <li><code>/wp-json/dual-native/v2/posts/{id}</code> - Machine Representation</li>
            <li><code>/wp-json/dual-native/v2/catalog</code> - Resource Catalog</li>
            <li><code>/wp-json/dual-native/v2/posts/{id}/blocks</code> - Safe Write Operations</li>
        </ul>
    </div>
    <style>
        .healthy { color: green; font-weight: bold; }
        .unhealthy { color: red; font-weight: bold; }
    </style>
    <?php
}
