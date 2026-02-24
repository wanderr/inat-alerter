<?php

/**
 * Email Sending Functionality
 * Uses SendGrid API for email delivery
 */

/**
 * Render a template with data
 */
function renderTemplate(string $templateFile, array $data): string {
    if (!file_exists($templateFile)) {
        fwrite(STDERR, "Template file not found: $templateFile\n");
        exit(1);
    }
    
    $html = file_get_contents($templateFile);
    
    foreach ($data as $key => $value) {
        $html = str_replace('{{' . $key . '}}', $value, $html);
    }
    
    return $html;
}

/**
 * Send email via SendGrid API
 */
function sendEmail(string $to, string $subject, string $htmlBody, bool $dryRun = false): void {
    $apiKey = getenv('SENDGRID_API_KEY');
    $fromEmail = getenv('SENDGRID_FROM_EMAIL');
    $fromName = getenv('SENDGRID_FROM_NAME') ?: 'iNat Alerter';
    
    if (!$apiKey || !$fromEmail) {
        fwrite(STDERR, "ERROR: Missing SendGrid configuration (SENDGRID_API_KEY or SENDGRID_FROM_EMAIL)\n");
        exit(1);
    }
    
    // Parse comma-separated recipients
    $recipients = array_map('trim', explode(',', $to));
    $recipientList = array_map(fn($email) => ['email' => $email], $recipients);
    
    $data = [
        'personalizations' => [['to' => $recipientList]],
        'from' => ['email' => $fromEmail, 'name' => $fromName],
        'subject' => $subject,
        'content' => [['type' => 'text/html', 'value' => $htmlBody]]
    ];
    
    if ($dryRun) {
        echo "DRY RUN: Would send email\n";
        echo "  To: $to\n";
        echo "  Subject: $subject\n";
        echo "  Body length: " . strlen($htmlBody) . " bytes\n";
        return;
    }
    
    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        echo "✓ Email sent successfully to: $to\n";
    } else {
        fwrite(STDERR, "ERROR: Failed to send email. HTTP Code: $httpCode\n");
        fwrite(STDERR, "Response: $response\n");
        exit(1);
    }
}

/**
 * Build digest email HTML
 */
function buildDigestEmail(array $observations, array $oldObservations, array $config, string $coverageStart, string $coverageEnd, bool $hitLimit = false): string {
    $timezone = new DateTimeZone($config['timezone']);
    
    $start = new DateTime($coverageStart, new DateTimeZone('UTC'));
    $start->setTimezone($timezone);
    $end = new DateTime($coverageEnd, new DateTimeZone('UTC'));
    $end->setTimezone($timezone);
    
    $coverageWindow = $start->format('M j, Y H:i') . ' - ' . $end->format('M j, Y H:i T');
    
    if ($hitLimit) {
        $coverageWindow .= '<br><span style="color: #d9534f;">⚠️ API limit reached - results may be incomplete (maximum 200 observations returned)</span>';
    }
    
    $locationSummary = sprintf(
        "%.4f, %.4f (radius: %d km)",
        $config['location']['lat'],
        $config['location']['lng'],
        $config['location']['radius']
    );
    
    $taxaSummary = count($config['taxa']['include']) . ' taxa configured';
    
    $observationsHtml = buildObservationsList($observations, $config);
    $oldObservationsHtml = buildObservationsList($oldObservations, $config);
    
    return renderTemplate('templates/digest.html', [
        'COVERAGE_WINDOW' => $coverageWindow,
        'LOCATION_SUMMARY' => $locationSummary,
        'TAXA_SUMMARY' => $taxaSummary,
        'OBSERVATIONS' => $observationsHtml ?: '<p>No new observations in this period.</p>',
        'OLD_OBSERVATIONS' => $oldObservationsHtml
    ]);
}

/**
 * Build alert email HTML
 */
function buildAlertEmail(array $observations, array $config): string {
    $observationsHtml = buildObservationsList($observations, $config);
    
    return renderTemplate('templates/alert.html', [
        'ALERT_COUNT' => count($observations),
        'OBSERVATIONS' => $observationsHtml
    ]);
}

/**
 * Build HTML list of observations
 */
function buildObservationsList(array $observations, array $config): string {
    if (empty($observations)) {
        return '';
    }
    
    $timezone = new DateTimeZone($config['timezone']);
    $html = '';
    
    foreach ($observations as $obs) {
        $commonName = $obs['taxon']['preferred_common_name'] ?? '';
        $scientificName = $obs['taxon']['name'] ?? 'Unknown';
        
        $created = new DateTime($obs['created_at'], new DateTimeZone('UTC'));
        $created->setTimezone($timezone);
        $createdStr = $created->format('M j, Y H:i T');
        
        $observedStr = 'Unknown';
        if (!empty($obs['observed_on'])) {
            $observedStr = $obs['observed_on'];
        }
        
        $photoUrl = $obs['photos'][0]['url'] ?? '';
        $obsUrl = "https://www.inaturalist.org/observations/{$obs['id']}";
        $username = $obs['user']['login'] ?? 'Unknown';
        $userUrl = "https://www.inaturalist.org/people/$username";
        $qualityGrade = ucfirst($obs['quality_grade'] ?? 'unknown');
        
        $location = $obs['location'] ?? '';
        $rarityCount = $obs['rarity_count'] ?? null;
        $rarityMethod = $obs['rarity_method'] ?? '';
        
        $methodLabel = '';
        if ($rarityMethod === 'radius') {
            $methodLabel = 'in area';
        } elseif ($rarityMethod === 'place') {
            $methodLabel = 'in place';
        } elseif ($rarityMethod === 'global') {
            $methodLabel = 'globally';
        }
        
        // Build conditional sections using templates
        $photoHtml = $photoUrl ? renderTemplate('templates/fragments/photo.html', ['PHOTO_URL' => $photoUrl]) : '';
        $commonNameHeader = $commonName ? renderTemplate('templates/fragments/common-name.html', ['COMMON_NAME' => $commonName]) : '';
        $obscuredIndicator = (isset($obs['obscured']) && $obs['obscured']) ? renderTemplate('templates/fragments/obscured.html', []) : '';
        $locationLine = $location ? renderTemplate('templates/fragments/location.html', ['LOCATION' => $location]) : '';
        $rarityLine = $rarityCount ? renderTemplate('templates/fragments/rarity.html', ['RARITY_COUNT' => $rarityCount, 'RARITY_METHOD_LABEL' => $methodLabel]) : '';
        
        // Build template data
        $data = [
            'PHOTO' => $photoHtml,
            'COMMON_NAME_HEADER' => $commonNameHeader,
            'SCIENTIFIC_NAME' => $scientificName,
            'CREATED_AT' => $createdStr,
            'OBSERVED_ON' => $observedStr,
            'OBSCURED_INDICATOR' => $obscuredIndicator,
            'USERNAME' => $username,
            'USER_URL' => $userUrl,
            'QUALITY_GRADE' => $qualityGrade,
            'LOCATION_LINE' => $locationLine,
            'RARITY_LINE' => $rarityLine,
            'OBS_URL' => $obsUrl
        ];
        
        $html .= renderTemplate('templates/observation-card.html', $data);
    }
    
    return $html;
}
