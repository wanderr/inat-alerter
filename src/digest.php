<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inat-api.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/email.php';

/**
 * Calculate the age of an observation in days
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

/**
 * Separate observations into new and old based on age threshold
 */
function separateByAge(array $observations, int $daysOldThreshold): array {
    $new = [];
    $old = [];
    
    foreach ($observations as $obs) {
        $age = calculateObservedAge($obs);
        
        if ($age === -1 || $age <= $daysOldThreshold) {
            $new[] = $obs;
        } else {
            $old[] = $obs;
        }
    }
    
    return ['new' => $new, 'old' => $old];
}

/**
 * Enrich observations with rarity counts
 */
function enrichWithRarity(array $observations, array $config): array {
    $enriched = [];
    
    foreach ($observations as $obs) {
        $taxonId = $obs['taxon']['id'] ?? null;
        
        if ($taxonId) {
            $obs['rarity_count'] = getTaxonObservationCount($taxonId, $config);
            $obs['rarity_method'] = $config['rarity']['method'];
        } else {
            $obs['rarity_count'] = null;
        }
        
        $enriched[] = $obs;
    }
    
    return $enriched;
}

/**
 * Sort observations by rarity (ascending) then by created_at (descending)
 */
function sortByRarity(array $observations): array {
    usort($observations, function($a, $b) {
        // Primary sort: rarity (ascending - rarer first)
        $rarityA = $a['rarity_count'] ?? PHP_INT_MAX;
        $rarityB = $b['rarity_count'] ?? PHP_INT_MAX;
        
        if ($rarityA !== $rarityB) {
            return $rarityA <=> $rarityB;
        }
        
        // Secondary sort: created_at (descending - newer first)
        $createdA = $a['created_at'] ?? '';
        $createdB = $b['created_at'] ?? '';
        
        return $createdB <=> $createdA;
    });
    
    return $observations;
}

/**
 * Main digest workflow
 */
function runDigest(array $config): void {
    echo "Starting digest workflow...\n";
    
    // Load state
    $state = loadState();
    
    // Calculate time window
    $endTime = gmdate('Y-m-d\TH:i:s\Z');
    $lastRun = getLastRunTime($state, 'digest');
    
    if ($lastRun) {
        $startTime = $lastRun;
        echo "Using last digest run time: $startTime\n";
    } else {
        $startTime = gmdate('Y-m-d\TH:i:s\Z', strtotime('-7 days'));
        echo "No previous digest run found, using 7-day lookback: $startTime\n";
    }
    
    echo "Fetching observations from $startTime to $endTime...\n";
    
    // Fetch observations
    $result = fetchObservations($config, $startTime, $endTime);
    $observations = $result['observations'];
    $hitLimit = $result['hit_limit'];
    $totalAvailable = $result['total_available'];
    
    echo "Fetched " . count($observations) . " observations\n";
    
    if ($hitLimit) {
        echo "WARNING: API limit reached. Total available: $totalAvailable, retrieved: " . count($observations) . "\n";
    }
    
    // Deduplicate
    $beforeDedup = count($observations);
    $observations = array_filter($observations, function($obs) use ($state) {
        return !isObservationSeen($state, $obs['id'], 'digest');
    });
    $observations = array_values($observations); // Re-index
    
    echo "After deduplication: " . count($observations) . " observations (removed " . ($beforeDedup - count($observations)) . ")\n";
    
    // If no observations, skip email
    if (empty($observations)) {
        echo "No new observations to report. Skipping email.\n";
        
        // Still update the last run time
        $state = updateLastRunTime($state, 'digest', $endTime);
        saveState($state);
        
        return;
    }
    
    // Separate by age
    $separated = separateByAge($observations, $config['old_observation']['days_old_threshold']);
    $newObs = $separated['new'];
    $oldObs = $separated['old'];
    
    echo "New observations: " . count($newObs) . "\n";
    echo "Old observations: " . count($oldObs) . "\n";
    
    // Enrich with rarity
    echo "Enriching observations with rarity counts...\n";
    $newObs = enrichWithRarity($newObs, $config);
    $oldObs = enrichWithRarity($oldObs, $config);
    
    // Sort by rarity
    $newObs = sortByRarity($newObs);
    $oldObs = sortByRarity($oldObs);
    
    // Build email
    echo "Building digest email...\n";
    $emailHtml = buildDigestEmail($newObs, $oldObs, $config, $startTime, $endTime, $hitLimit);
    
    // Send email
    $subject = "iNat Digest - " . gmdate('M j', strtotime($startTime)) . " to " . gmdate('M j, Y', strtotime($endTime));
    
    echo "Sending digest email with subject: $subject\n";
    sendEmail(getenv('EMAIL_RECIPIENTS'), $subject, $emailHtml);
    
    // Update state
    echo "Updating state...\n";
    $state = updateLastRunTime($state, 'digest', $endTime);
    
    foreach (array_merge($newObs, $oldObs) as $obs) {
        $state = markObservationSeen($state, $obs['id'], 'digest');
    }
    
    saveState($state);
    
    echo "Digest workflow completed successfully!\n";
}
