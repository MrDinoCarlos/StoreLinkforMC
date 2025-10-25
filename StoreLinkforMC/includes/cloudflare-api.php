<?php
if (!defined('ABSPATH')) exit;

/**
 * Cloudflare helper to create or update a Cache Rule (Rulesets API)
 * that sets BYPASS for:
 *  - /wp-json/storelinkformc/...
 *  - ?rest_route=/storelinkformc/...
 *
 * Requires: Zone ID + API Token with Rulesets permissions.
 */

function storelinkformc_cf_request(string $method, string $path, string $api_token, array $body = null) {
    $url = "https://api.cloudflare.com/client/v4" . $path;
    $args = [
        'method'  => $method,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_token,
            'Content-Type'  => 'application/json',
        ],
        'timeout' => 25,
    ];
    if (!is_null($body)) $args['body'] = wp_json_encode($body);

    $resp = wp_remote_request($url, $args);
    if (is_wp_error($resp)) return $resp;

    $code = wp_remote_retrieve_response_code($resp);
    $json = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300 || !isset($json['success']) || !$json['success']) {
        return new WP_Error('cf_api', 'Cloudflare API error', ['code' => $code, 'body' => $json]);
    }
    return $json;
}

/**
 * Creates or updates a BYPASS cache rule for StoreLinkforMC.
 * - Looks for a ruleset in the phase http_request_cache_settings
 * - If it exists, adds (or updates) a rule with a fixed name
 * - If it doesn’t exist, creates a new custom ruleset with our rule
 */
function storelinkformc_cf_upsert_cache_rule(string $zone_id, string $api_token) {
    // Cloudflare expression (Rulesets)
    // Matches the REST route and fallback ?rest_route=
    $expr = '(http.request.uri.path starts_with "/wp-json/storelinkformc/") or (http.request.uri.query contains "rest_route=/storelinkformc/")';

    $rule_name = 'StoreLinkforMC Bypass REST';
    $action = [
        'id'    => 'set_cache_settings',
        'value' => ['cache' => 'bypass'],
    ];

    // 1) Retrieve rulesets for the cache phase
    $list = storelinkformc_cf_request('GET', "/zones/{$zone_id}/rulesets?phase=http_request_cache_settings", $api_token);
    if (is_wp_error($list)) return $list;

    $ruleset_id = null;
    if (!empty($list['result'])) {
        // Get the existing zone ruleset (usually the "Default" one)
        foreach ($list['result'] as $rs) {
            if (isset($rs['id'])) { $ruleset_id = $rs['id']; break; }
        }
    }

    if ($ruleset_id) {
        // 2) Try to find a rule with our description
        $rules = storelinkformc_cf_request('GET', "/zones/{$zone_id}/rulesets/{$ruleset_id}", $api_token);
        if (is_wp_error($rules)) return $rules;

        $existing_rule_id = null;
        foreach (($rules['result']['rules'] ?? []) as $r) {
            if (isset($r['description']) && $r['description'] === $rule_name) {
                $existing_rule_id = $r['id'];
                break;
            }
        }

        if ($existing_rule_id) {
            // 3a) Update the existing rule (PUT rule)
            $body = [
                'action'              => $action['id'],
                'action_parameters'   => $action['value'],
                'expression'          => $expr,
                'description'         => $rule_name,
                'enabled'             => true,
            ];
            return storelinkformc_cf_request('PUT', "/zones/{$zone_id}/rulesets/{$ruleset_id}/rules/{$existing_rule_id}", $api_token, $body);
        } else {
            // 3b) Create a new rule (POST rule)
            $body = [
                'action'              => $action['id'],
                'action_parameters'   => $action['value'],
                'expression'          => $expr,
                'description'         => $rule_name,
                'enabled'             => true,
            ];
            return storelinkformc_cf_request('POST', "/zones/{$zone_id}/rulesets/{$ruleset_id}/rules", $api_token, $body);
        }
    } else {
        // 4) No ruleset in that phase: create one with our rule
        $body = [
            'name'        => 'StoreLinkforMC Cache Rules',
            'description' => 'Bypass cache for StoreLinkforMC REST',
            'kind'        => 'zone',
            'phase'       => 'http_request_cache_settings',
            'rules'       => [[
                'action'              => $action['id'],
                'action_parameters'   => $action['value'],
                'expression'          => $expr,
                'description'         => $rule_name,
                'enabled'             => true,
            ]],
        ];
        return storelinkformc_cf_request('POST', "/zones/{$zone_id}/rulesets", $api_token, $body);
    }
}
