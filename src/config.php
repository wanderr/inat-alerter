<?php
/**
 * Configuration loader and validator for iNat Alerter
 */

/**
 * Load and validate configuration from YAML file
 * 
 * @param string $configPath Path to config.yaml file
 * @return array Validated configuration array
 * @throws Exception if configuration is invalid
 */
function loadConfig(string $configPath = 'config.yaml'): array {
    // Check if config file exists
    if (!file_exists($configPath)) {
        fwrite(STDERR, "ERROR: Configuration file not found: {$configPath}\n");
        fwrite(STDERR, "Copy config.example.yaml to config.yaml and customize it.\n");
        exit(1);
    }

    // Load YAML using Spyc
    require_once __DIR__ . '/../vendor/autoload.php';
    
    try {
        $config = \Spyc::YAMLLoad($configPath);
    } catch (Exception $e) {
        fwrite(STDERR, "ERROR: Failed to parse YAML configuration: {$e->getMessage()}\n");
        exit(1);
    }

    // Validate configuration
    validateConfig($config);

    return $config;
}

/**
 * Validate configuration structure and types
 * 
 * @param array $config Configuration array
 * @return void
 * @throws Exception if validation fails
 */
function validateConfig(array $config): void {
    $errors = [];

    // Validate timezone
    if (!isset($config['timezone']) || !is_string($config['timezone'])) {
        $errors[] = "timezone is required and must be a string";
    } else {
        // Validate against PHP's timezone list
        $validTimezones = timezone_identifiers_list();
        if (!in_array($config['timezone'], $validTimezones, true)) {
            fwrite(STDERR, "WARNING: Invalid timezone '{$config['timezone']}'. This may cause issues with date/time formatting.\n");
            fwrite(STDERR, "Valid timezones: https://www.php.net/manual/en/timezones.php\n");
        }
    }

    // Validate location
    if (!isset($config['location']) || !is_array($config['location'])) {
        $errors[] = "location is required and must be an object";
    } else {
        if (!isset($config['location']['lat']) || !is_numeric($config['location']['lat'])) {
            $errors[] = "location.lat is required and must be numeric";
        }
        if (!isset($config['location']['lng']) || !is_numeric($config['location']['lng'])) {
            $errors[] = "location.lng is required and must be numeric";
        }
        if (!isset($config['location']['radius']) || !is_numeric($config['location']['radius'])) {
            $errors[] = "location.radius is required and must be numeric";
        }
    }

    // Validate taxa
    if (!isset($config['taxa']) || !is_array($config['taxa'])) {
        $errors[] = "taxa is required and must be an object";
    } else {
        if (!isset($config['taxa']['include']) || !is_array($config['taxa']['include'])) {
            $errors[] = "taxa.include is required and must be an array";
        }
        if (isset($config['taxa']['exclude']) && !is_array($config['taxa']['exclude'])) {
            $errors[] = "taxa.exclude must be an array";
        }
    }

    // Validate watchlist
    if (!isset($config['watchlist']) || !is_array($config['watchlist'])) {
        $errors[] = "watchlist is required and must be an object";
    } else {
        if (!isset($config['watchlist']['taxa_ids']) || !is_array($config['watchlist']['taxa_ids'])) {
            $errors[] = "watchlist.taxa_ids is required and must be an array";
        }
    }

    // Validate rarity
    if (!isset($config['rarity']) || !is_array($config['rarity'])) {
        $errors[] = "rarity is required and must be an object";
    } else {
        if (!isset($config['rarity']['method']) || !is_string($config['rarity']['method'])) {
            $errors[] = "rarity.method is required and must be a string";
        }
        // place_id is optional, but if present should be numeric or null
        if (isset($config['rarity']['place_id']) && 
            $config['rarity']['place_id'] !== null && 
            !is_numeric($config['rarity']['place_id'])) {
            $errors[] = "rarity.place_id must be numeric or null";
        }
    }

    // Validate old_observation
    if (!isset($config['old_observation']) || !is_array($config['old_observation'])) {
        $errors[] = "old_observation is required and must be an object";
    } else {
        if (!isset($config['old_observation']['days_old_threshold']) || 
            !is_numeric($config['old_observation']['days_old_threshold'])) {
            $errors[] = "old_observation.days_old_threshold is required and must be numeric";
        }
    }

    // Validate digest
    if (!isset($config['digest']) || !is_array($config['digest'])) {
        $errors[] = "digest is required and must be an object";
    } else {
        if (!isset($config['digest']['enabled']) || !is_bool($config['digest']['enabled'])) {
            $errors[] = "digest.enabled is required and must be boolean";
        }
        if (!isset($config['digest']['day_of_week']) || !is_int($config['digest']['day_of_week'])) {
            $errors[] = "digest.day_of_week is required and must be an integer (0-6)";
        } elseif ($config['digest']['day_of_week'] < 0 || $config['digest']['day_of_week'] > 6) {
            $errors[] = "digest.day_of_week must be between 0 (Sunday) and 6 (Saturday)";
        }
        if (!isset($config['digest']['local_hour']) || !is_int($config['digest']['local_hour'])) {
            $errors[] = "digest.local_hour is required and must be an integer (0-23)";
        } elseif ($config['digest']['local_hour'] < 0 || $config['digest']['local_hour'] > 23) {
            $errors[] = "digest.local_hour must be between 0 and 23";
        }
    }

    // Validate alerts
    if (!isset($config['alerts']) || !is_array($config['alerts'])) {
        $errors[] = "alerts is required and must be an object";
    } else {
        if (!isset($config['alerts']['enabled']) || !is_bool($config['alerts']['enabled'])) {
            $errors[] = "alerts.enabled is required and must be boolean";
        }
    }

    // Validate state
    if (!isset($config['state']) || !is_array($config['state'])) {
        $errors[] = "state is required and must be an object";
    } else {
        if (!isset($config['state']['artifact_retention_days']) || 
            !is_numeric($config['state']['artifact_retention_days'])) {
            $errors[] = "state.artifact_retention_days is required and must be numeric";
        }
    }

    // If there are errors, output them and exit
    if (!empty($errors)) {
        fwrite(STDERR, "ERROR: Configuration validation failed:\n");
        foreach ($errors as $error) {
            fwrite(STDERR, "  - {$error}\n");
        }
        exit(1);
    }
}
