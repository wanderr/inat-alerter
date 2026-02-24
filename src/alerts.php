<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inat-api.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/email.php';

/**
 * Main alerts workflow
 */
function runAlerts(array $config): void {
    echo "Starting alerts workflow...\n";
    
    // Load state
    $state = loadState();
    
    // Calculate time window
    $endTime = gmdate('Y-m-d\TH:i:s\Z');
    $lastRun = getLastRunTime($state, 'alert');
    
    if ($lastRun) {
        $startTime = $lastRun;
        echo "Using last alert run time: $startTime\n";
    } else {
        $startTime = gmdate('Y-m-d\TH:i:s\Z', strtotime('-1 hour'));
        echo "No previous alert run found, using 1-hour lookback: $startTime\n";
    }
    
    echo "Fetching observations from $startTime to $endTime...\n";
    
    // Create watchlist-specific config
    $watchlistConfig = $config;
    $watchlistConfig['taxa']['include'] = $config['watchlist']['taxa_ids'];
    // Keep the global exclude list
    
    // Fetch observations
    $result = fetchObservations($watchlistConfig, $startTime, $endTime);
    $observations = $result['observations'];
    
    echo "Fetched " . count($observations) . " watchlist observations\n";
    
    // Filter out old observations (based on observed age threshold)
    $daysOldThreshold = $config['old_observation']['days_old_threshold'];
    $beforeAgeFilter = count($observations);
    
    $observations = array_filter($observations, function($obs) use ($daysOldThreshold) {
        $age = calculateObservedAge($obs);
        // Keep if age is unknown (-1) or within threshold
        return $age === -1 || $age <= $daysOldThreshold;
    });
    $observations = array_values($observations); // Re-index
    
    echo "After age filter: " . count($observations) . " observations (removed " . ($beforeAgeFilter - count($observations)) . " old)\n";
    
    // Deduplicate
    $beforeDedup = count($observations);
    $observations = array_filter($observations, function($obs) use ($state) {
        return !isObservationSeen($state, $obs['id'], 'alert');
    });
    $observations = array_values($observations); // Re-index
    
    echo "After deduplication: " . count($observations) . " observations (removed " . ($beforeDedup - count($observations)) . ")\n";
    
    // If no observations, skip email
    if (empty($observations)) {
        echo "No new watchlist observations to alert. Skipping email.\n";
        
        // Still update the last run time
        $state = updateLastRunTime($state, 'alert', $endTime);
        saveState($state);
        
        return;
    }
    
    // Build email
    echo "Building alert email...\n";
    $emailHtml = buildAlertEmail($observations, $config);
    
    // Send email
    $subject = "iNat Alert - " . count($observations) . " new observation(s)";
    
    echo "Sending alert email with subject: $subject\n";
    sendEmail(getenv('EMAIL_RECIPIENTS'), $subject, $emailHtml);
    
    // Update state
    echo "Updating state...\n";
    $state = updateLastRunTime($state, 'alert', $endTime);
    
    foreach ($observations as $obs) {
        $state = markObservationSeen($state, $obs['id'], 'alert');
    }
    
    saveState($state);
    
    echo "Alerts workflow completed successfully!\n";
}

/**
 * Helper function to calculate observed age (shared with digest)
 */
function calculateObservedAge(array $observation): int {
    if (empty($observation['observed_on'])) {
        return -1; // Unknown
    }
    
    $observedDate = new DateTime($observation['observed_on']);
    $now = new DateTime();
    $diff = $now->diff($observedDate);
    
    return $diff->days;
}
