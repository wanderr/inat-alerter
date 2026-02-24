<?php

/**
 * iNaturalist API Client
 * 
 * Provides functions to interact with the public iNaturalist API v1.
 */

const INAT_API_BASE = 'https://api.inaturalist.org/v1';
const API_TIMEOUT = 30;
const MAX_RETRIES = 8;
const MAX_BACKOFF = 120; // 2 minutes

/**
 * Make an API request with retry logic and rate limiting handling
 * 
 * @param string $url Full API URL
 * @return array Decoded JSON response
 * @throws Exception on failure after all retries
 */
function makeApiRequest(string $url): array {
    $attempt = 0;
    $backoff = 1;
    
    while ($attempt < MAX_RETRIES) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'iNat-Alerter/1.0');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            // Network error
            $attempt++;
            if ($attempt >= MAX_RETRIES) {
                throw new Exception("API request failed after {$attempt} attempts: {$error}");
            }
            error_log("API request failed (attempt {$attempt}/{MAX_RETRIES}): {$error}. Retrying in {$backoff}s...");
            sleep($backoff);
            $backoff = min($backoff * 2, MAX_BACKOFF);
            continue;
        }
        
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // Check for rate limiting
        if ($httpCode == 429) {
            // Extract Retry-After header
            if (preg_match('/Retry-After:\s*(\d+)/i', $headers, $matches)) {
                $retryAfter = (int)$matches[1];
                error_log("Rate limited. Waiting {$retryAfter} seconds as indicated by Retry-After header...");
                sleep($retryAfter);
                $attempt++;
                continue;
            } else {
                // No Retry-After header, use exponential backoff
                $attempt++;
                if ($attempt >= MAX_RETRIES) {
                    throw new Exception("Rate limited and max retries exceeded");
                }
                error_log("Rate limited (attempt {$attempt}/{MAX_RETRIES}). Retrying in {$backoff}s...");
                sleep($backoff);
                $backoff = min($backoff * 2, MAX_BACKOFF);
                continue;
            }
        }
        
        // Check for server errors (5xx)
        if ($httpCode >= 500) {
            $attempt++;
            if ($attempt >= MAX_RETRIES) {
                throw new Exception("Server error (HTTP {$httpCode}) after {$attempt} attempts");
            }
            error_log("Server error HTTP {$httpCode} (attempt {$attempt}/{MAX_RETRIES}). Retrying in {$backoff}s...");
            sleep($backoff);
            $backoff = min($backoff * 2, MAX_BACKOFF);
            continue;
        }
        
        // Check for client errors (4xx except 429)
        if ($httpCode >= 400 && $httpCode < 500) {
            throw new Exception("Client error (HTTP {$httpCode}): {$body}");
        }
        
        // Success
        if ($httpCode == 200) {
            $data = json_decode($body, true);
            if ($data === null) {
                throw new Exception("Failed to decode JSON response: " . json_last_error_msg());
            }
            return $data;
        }
        
        // Unexpected HTTP code
        throw new Exception("Unexpected HTTP code {$httpCode}");
    }
    
    throw new Exception("Max retries exceeded");
}

/**
 * Fetch observations from iNaturalist API
 * 
 * @param array $config Configuration array
 * @param string $createdAfter ISO 8601 datetime in UTC
 * @param string $createdBefore ISO 8601 datetime in UTC
 * @return array ['observations' => array, 'hit_limit' => bool, 'total_available' => int]
 */
function fetchObservations(array $config, string $createdAfter, string $createdBefore): array {
    $params = [
        'photos' => 'true',
        'captive' => 'false',
        'created_d1' => $createdAfter,
        'created_d2' => $createdBefore,
        'per_page' => '200',
        'order' => 'desc',
        'order_by' => 'created_at',
    ];
    
    // Add taxon filters
    if (!empty($config['taxa']['include'])) {
        $params['taxon_id'] = implode(',', $config['taxa']['include']);
    }
    
    if (!empty($config['taxa']['exclude'])) {
        $params['without_taxon_id'] = implode(',', $config['taxa']['exclude']);
    }
    
    // Add location filters
    $params['lat'] = $config['location']['lat'];
    $params['lng'] = $config['location']['lng'];
    $params['radius'] = $config['location']['radius'];
    
    $allObservations = [];
    $page = 1;
    $totalResults = 0;
    $hitLimit = false;
    
    while (true) {
        $params['page'] = $page;
        $url = INAT_API_BASE . '/observations?' . http_build_query($params);
        
        error_log("Fetching observations page {$page}...");
        $response = makeApiRequest($url);
        
        if ($page == 1) {
            $totalResults = $response['total_results'] ?? 0;
            error_log("Total results available: {$totalResults}");
        }
        
        $results = $response['results'] ?? [];
        if (empty($results)) {
            break;
        }
        
        $allObservations = array_merge($allObservations, $results);
        
        // Check if we've hit the 200 result limit
        if (count($allObservations) >= 200) {
            $hitLimit = ($totalResults > 200);
            if ($hitLimit) {
                error_log("WARNING: Results limited to 200. Total available: {$totalResults}");
            }
            break;
        }
        
        // Check if there are more pages
        if (count($results) < 200) {
            break;
        }
        
        $page++;
    }
    
    error_log("Fetched " . count($allObservations) . " observations");
    
    return [
        'observations' => $allObservations,
        'hit_limit' => $hitLimit,
        'total_available' => $totalResults,
    ];
}

/**
 * Get observation count for a taxon (for rarity calculation)
 * Uses static caching to avoid redundant API calls
 * 
 * @param int $taxonId The taxon ID
 * @param array $config Configuration array
 * @return int Observation count
 */
function getTaxonObservationCount(int $taxonId, array $config): int {
    static $cache = [];
    
    // Check cache
    $cacheKey = $taxonId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    
    $params = [
        'taxon_id' => $taxonId,
        'per_page' => '0', // We only want the count
    ];
    
    // Try radius-based count first
    if ($config['rarity']['method'] === 'radius') {
        $params['lat'] = $config['location']['lat'];
        $params['lng'] = $config['location']['lng'];
        $params['radius'] = $config['location']['radius'];
        
        $url = INAT_API_BASE . '/observations?' . http_build_query($params);
        try {
            $response = makeApiRequest($url);
            $count = $response['total_results'] ?? 0;
            $cache[$cacheKey] = $count;
            error_log("Rarity count for taxon {$taxonId} (radius): {$count}");
            return $count;
        } catch (Exception $e) {
            error_log("Failed to get radius-based count for taxon {$taxonId}: " . $e->getMessage());
        }
    }
    
    // Try place-based count if configured
    if (!empty($config['rarity']['place_id'])) {
        $params = [
            'taxon_id' => $taxonId,
            'place_id' => $config['rarity']['place_id'],
            'per_page' => '0',
        ];
        
        $url = INAT_API_BASE . '/observations?' . http_build_query($params);
        try {
            $response = makeApiRequest($url);
            $count = $response['total_results'] ?? 0;
            $cache[$cacheKey] = $count;
            error_log("Rarity count for taxon {$taxonId} (place): {$count}");
            return $count;
        } catch (Exception $e) {
            error_log("Failed to get place-based count for taxon {$taxonId}: " . $e->getMessage());
        }
    }
    
    // Fall back to global count
    $params = [
        'taxon_id' => $taxonId,
        'per_page' => '0',
    ];
    
    $url = INAT_API_BASE . '/observations?' . http_build_query($params);
    try {
        $response = makeApiRequest($url);
        $count = $response['total_results'] ?? 0;
        $cache[$cacheKey] = $count;
        error_log("Rarity count for taxon {$taxonId} (global): {$count}");
        return $count;
    } catch (Exception $e) {
        error_log("Failed to get global count for taxon {$taxonId}: " . $e->getMessage());
        // Return 0 as fallback
        $cache[$cacheKey] = 0;
        return 0;
    }
}
