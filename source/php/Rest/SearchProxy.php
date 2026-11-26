<?php

declare(strict_types=1);

namespace AlgoliaIndexTypesenseProvider\Rest;

use AlgoliaIndexTypesenseProvider\Helper\Options;
use AlgoliaIndexTypesenseProvider\Provider\Typesense\TypesenseProvider;

/**
 * Same-origin, login-only search proxy, enabled with
 * define('TYPESENSEINDEX_SEARCH_MODE', 'wp-rest').
 *
 * The built search page addon passes its SearchConfig to typesense-js as a
 * node, and typesense-js requests protocol://host:port + node.path + endpoint.
 * Pointing host and path at this route keeps that frontend unchanged. Its
 * apiKey slot carries the wp_rest nonce, which arrives in the
 * X-TYPESENSE-API-KEY header and is handed to WordPress cookie auth.
 *
 * The browser never chooses the collection, fields or filter_by: the query is
 * built here. No site configures facets, so facet filters are not proxied.
 */
class SearchProxy
{
    public const NAMESPACE = 'algolia-index-typesense/v1';
    public const BASE = '/proxy';

    public const MAX_QUERY_LENGTH = 200;
    public const MAX_PAGE = 100;
    public const MAX_PER_PAGE = 50;

    // The addon searches on every input event without debounce; allow typing bursts.
    public const RATE_LIMIT = 120;
    public const RATE_WINDOW_SECONDS = 60;

    private string $restRoute = '';

    public static function isEnabled(): bool
    {
        return defined('TYPESENSEINDEX_SEARCH_MODE') && TYPESENSEINDEX_SEARCH_MODE === 'wp-rest';
    }

    public function register(): void
    {
        // Core serves REST from rest_api_loaded on parse_request (priority 10).
        add_action('parse_request', [$this, 'captureRoute'], 9);
        // Core validates the cookie nonce in rest_cookie_check_errors (priority 100).
        add_filter('rest_authentication_errors', [$this, 'mapNonceHeader'], 99);
        add_action('rest_api_init', [$this, 'registerRoute']);
    }

    /**
     * Read-only search mode status in the Typesense settings group. The mode is
     * server config, deliberately not an option an admin or a database copy can change.
     */
    public static function registerSettingsStatus(): void
    {
        // The settings group is imported on acf/init at the default priority.
        add_action('acf/init', static function (): void {
            $enabled = self::isEnabled();

            acf_add_local_field([
                'key' => 'field_aitp_search_mode_status',
                'label' => '',
                'name' => '',
                // A message field posts nothing; ACF true_false has no disabled state.
                'type' => 'message',
                'parent' => 'group_68bfb035c379f',
                'esc_html' => 0,
                'new_lines' => '',
                // ACF's own switch markup without an input, so it shows state but cannot be toggled.
                // ACF's sanitizer drops aria-checked, so screen-reader text carries the state.
                // ACF's switch is display:grid and WordPress strips inline display rules, so the
                // label floats left and the switch (its own formatting context) sits beside it.
                'message' => '<div style="overflow:hidden;margin-bottom:8px">'
                    . '<strong style="float:left;line-height:32px;margin-right:12px">' . esc_html__('Secure search enabled', 'algolia-index-typesense-provider') . '</strong>'
                    . '<span class="screen-reader-text">' . ($enabled ? esc_html__('Yes', 'acf') : esc_html__('No', 'acf')) . '</span>'
                    . '<div class="acf-switch' . ($enabled ? ' -on' : '') . '" aria-hidden="true" style="opacity:.6;cursor:not-allowed">'
                    . '<span class="acf-switch-on">' . esc_html__('Yes', 'acf') . '</span>'
                    . '<span class="acf-switch-off">' . esc_html__('No', 'acf') . '</span>'
                    . '<div class="acf-switch-slider"></div></div></div>'
                    . '<p class="description">'
                    . esc_html__('Enable for intranets and sites where some content is private or for logged-in users only. Search then requires login, runs through this site and only returns content the user may read; Typesense is never exposed to the browser. Leave it off for public sites: visitors who are not logged in cannot search when it is on.', 'algolia-index-typesense-provider')
                    . '</p><p class="description">'
                    . esc_html__('Read-only here. A server administrator sets it in the site config with define(\'TYPESENSEINDEX_SEARCH_MODE\', \'wp-rest\');', 'algolia-index-typesense-provider')
                    . '</p>',
            ]);
        }, 20);
    }

    /**
     * Browser config for the addon: same origin, nonce in place of a Typesense key.
     */
    public static function browserConfig(): array
    {
        $url = wp_parse_url(home_url('/' . rest_get_url_prefix() . '/' . self::NAMESPACE . self::BASE));
        $protocol = $url['scheme'] ?? 'https';

        return [
            'type' => 'typesense',
            'host' => $url['host'] ?? null,
            'port' => $url['port'] ?? ($protocol === 'http' ? 80 : 443),
            'protocol' => $protocol,
            'path' => rtrim($url['path'] ?? '', '/'),
            'apiKey' => wp_create_nonce('wp_rest'),
            // Part of the request path only; the proxy always searches its own collection.
            'collectionName' => 'site',
        ];
    }

    public function captureRoute(\WP $wp): void
    {
        $this->restRoute = (string) ($wp->query_vars['rest_route'] ?? '');
    }

    public function mapNonceHeader($result)
    {
        if (
            ($_SERVER['HTTP_X_WP_NONCE'] ?? '') === ''
            && ($_SERVER['HTTP_X_TYPESENSE_API_KEY'] ?? '') !== ''
            && self::isProxyRoute($this->restRoute)
        ) {
            $_SERVER['HTTP_X_WP_NONCE'] = $_SERVER['HTTP_X_TYPESENSE_API_KEY'];
        }

        return $result;
    }

    public static function isProxyRoute(string $route): bool
    {
        return str_starts_with($route, '/' . self::NAMESPACE . self::BASE . '/');
    }

    public function registerRoute(): void
    {
        register_rest_route(self::NAMESPACE, self::BASE . '/collections/(?P<collection>[A-Za-z0-9_.-]+)/documents/search', [
            'methods' => 'GET',
            'callback' => [$this, 'search'],
            'permission_callback' => [$this, 'permission'],
        ]);
    }

    public function permission()
    {
        if (!is_user_logged_in()) {
            return new \WP_Error('rest_not_logged_in', 'You must be logged in to search.', ['status' => 401]);
        }

        if (!is_super_admin() && !is_user_member_of_blog()) {
            return new \WP_Error('rest_forbidden', 'You are not a member of this site.', ['status' => 403]);
        }

        return true;
    }

    public function search(\WP_REST_Request $request)
    {
        if (!$this->withinRateLimit(get_current_user_id(), get_current_blog_id())) {
            return new \WP_Error('rest_too_many_requests', 'Too many search requests.', ['status' => 429]);
        }

        $provider = new TypesenseProvider((string) Options::publicApiKey(), (string) Options::apiUrl(), Options::collectionName());
        $response = $provider->searchDocuments(self::buildQuery(
            $request->get_param('q'),
            $request->get_param('page'),
            $request->get_param('per_page'),
        ));

        if ($response['statusCode'] < 200 || $response['statusCode'] >= 300 || !is_array($response['result'])) {
            error_log('Typesense search proxy: upstream status ' . (int) $response['statusCode']);
            return new \WP_Error('search_unavailable', 'Search is unavailable.', ['status' => 502]);
        }

        $result = $response['result'];
        $hits = self::filterHits($result['hits'] ?? [], [self::class, 'canRead']);
        $dropped = count($result['hits'] ?? []) - count($hits);
        if ($dropped > 0) {
            // The index and WordPress disagree about access: treat as an alarm.
            error_log('Typesense search proxy: dropped ' . $dropped . ' unreadable hit(s) for blog ' . get_current_blog_id());
        }

        $rest = rest_ensure_response([
            'found' => (int) ($result['found'] ?? 0),
            'out_of' => (int) ($result['out_of'] ?? 0),
            'page' => (int) ($result['page'] ?? 1),
            'search_time_ms' => (int) ($result['search_time_ms'] ?? 0),
            'facet_counts' => [],
            'hits' => $hits,
        ]);
        $rest->header('Cache-Control', 'private, no-store');
        $rest->header('Vary', 'Cookie');

        return $rest;
    }

    /**
     * The complete Typesense query; only q, page and per_page come from the browser.
     */
    public static function buildQuery(mixed $query, mixed $page, mixed $perPage): array
    {
        return [
            'q' => is_string($query) ? mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH) : '',
            'query_by' => 'post_title,post_excerpt,content',
            'highlight_full_fields' => 'post_title,post_excerpt',
            'page' => self::clamp($page, 1, self::MAX_PAGE, 1),
            'per_page' => self::clamp($perPage, 1, self::MAX_PER_PAGE, 20),
        ];
    }

    /**
     * @param callable(array): bool $canRead
     */
    public static function filterHits(array $hits, callable $canRead): array
    {
        return array_values(array_filter(
            $hits,
            static fn($hit): bool => is_array($hit) && is_array($hit['document'] ?? null) && $canRead($hit['document']),
        ));
    }

    /**
     * Defence in depth: the hit must be a readable, published, unprotected post of this blog.
     */
    public static function canRead(array $document): bool
    {
        if (is_multisite() && (int) ($document['blog_id'] ?? 0) !== get_current_blog_id()) {
            return false;
        }

        $post = get_post((int) ($document['ID'] ?? 0));

        return $post instanceof \WP_Post
            && $post->post_status === 'publish'
            && $post->post_password === ''
            && current_user_can('read_post', $post->ID);
    }

    private function withinRateLimit(int $userId, int $blogId): bool
    {
        $key = 'aitp_search_rate_' . $blogId . '_' . $userId;
        $now = time();
        $state = get_transient($key);
        if (!is_array($state) || ($state['until'] ?? 0) <= $now) {
            $state = ['until' => $now + self::RATE_WINDOW_SECONDS, 'count' => 0];
        }

        if ($state['count'] >= self::RATE_LIMIT) {
            return false;
        }

        $state['count']++;
        set_transient($key, $state, max(1, $state['until'] - $now));

        return true;
    }

    private static function clamp(mixed $value, int $min, int $max, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
