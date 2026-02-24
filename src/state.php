<?php

/**
 * Load state from state.json file.
 * Returns default state if file doesn't exist.
 * 
 * @return array State array
 */
function loadState(): array {
    $stateFile = 'state.json';
    
    if (!file_exists($stateFile)) {
        error_log("State file not found. Using default state (first run or artifact expired).");
        return getDefaultState();
    }
    
    $json = file_get_contents($stateFile);
    if ($json === false) {
        error_log("Failed to read state file. Using default state.");
        return getDefaultState();
    }
    
    $state = json_decode($json, true);
    if ($state === null) {
        error_log("Failed to parse state file. Using default state.");
        return getDefaultState();
    }
    
    error_log("State loaded successfully.");
    return $state;
}

/**
 * Save state to state.json file.
 * Prunes old observation IDs before saving.
 * 
 * @param array $state State array to save
 * @return void
 */
function saveState(array $state): void {
    // Prune old observation IDs
    $state['digest_observation_ids'] = pruneOldObservationIds($state['digest_observation_ids']);
    $state['alert_observation_ids'] = pruneOldObservationIds($state['alert_observation_ids']);
    
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        error_log("ERROR: Failed to encode state to JSON");
        exit(1);
    }
    
    $result = file_put_contents('state.json', $json);
    if ($result === false) {
        error_log("ERROR: Failed to write state file");
        exit(1);
    }
    
    error_log("State saved successfully.");
}

/**
 * Get default state structure.
 * 
 * @return array Default state
 */
function getDefaultState(): array {
    return [
        'last_digest_run' => null,
        'last_alert_run' => null,
        'digest_observation_ids' => [],
        'alert_observation_ids' => [],
    ];
}

/**
 * Prune observation IDs older than threshold.
 * 
 * @param array $observationIds Map of observation_id => timestamp
 * @param int $maxAgeDays Maximum age in days to keep
 * @return array Pruned observation IDs
 */
function pruneOldObservationIds(array $observationIds, int $maxAgeDays = 30): array {
    $cutoffTime = time() - ($maxAgeDays * 86400);
    $pruned = [];
    $prunedCount = 0;
    
    foreach ($observationIds as $id => $timestamp) {
        $time = strtotime($timestamp);
        if ($time !== false && $time >= $cutoffTime) {
            $pruned[$id] = $timestamp;
        } else {
            $prunedCount++;
        }
    }
    
    if ($prunedCount > 0) {
        error_log("Pruned $prunedCount old observation IDs (older than $maxAgeDays days).");
    }
    
    return $pruned;
}

/**
 * Get last run time for digest or alert.
 * 
 * @param array $state State array
 * @param string $type 'digest' or 'alert'
 * @return string|null ISO 8601 datetime or null
 */
function getLastRunTime(array $state, string $type): ?string {
    $key = "last_{$type}_run";
    return $state[$key] ?? null;
}

/**
 * Update last run time for digest or alert.
 * 
 * @param array $state State array (modified by reference)
 * @param string $type 'digest' or 'alert'
 * @param string $datetime ISO 8601 datetime
 * @return void
 */
function updateLastRunTime(array &$state, string $type, string $datetime): void {
    $key = "last_{$type}_run";
    $state[$key] = $datetime;
    error_log("Updated {$type} last run time to: $datetime");
}

/**
 * Mark observations as processed.
 * 
 * @param array $state State array (modified by reference)
 * @param string $type 'digest' or 'alert'
 * @param array $observationIds Array of observation IDs
 * @param string $datetime ISO 8601 datetime when processed
 * @return void
 */
function markObservationsProcessed(array &$state, string $type, array $observationIds, string $datetime): void {
    $key = "{$type}_observation_ids";
    
    foreach ($observationIds as $id) {
        $state[$key][$id] = $datetime;
    }
    
    $count = count($observationIds);
    error_log("Marked $count observation(s) as processed for {$type}.");
}

/**
 * Check if observation has been processed.
 * 
 * @param array $state State array
 * @param string $type 'digest' or 'alert'
 * @param int $observationId Observation ID to check
 * @return bool True if already processed
 */
function hasBeenProcessed(array $state, string $type, int $observationId): bool {
    $key = "{$type}_observation_ids";
    return isset($state[$key][$observationId]);
}
