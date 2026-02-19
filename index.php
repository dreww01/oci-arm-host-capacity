<?php
declare(strict_types=1);

/**
 * index.php - OCI ARM Instance Capacity Checker & Creator
 * Runs via Task Scheduler every 5 minutes
 */

// ────────────────────────────────────────────────
//   DEBUG LOGGING - Critical for Task Scheduler troubleshooting
// ────────────────────────────────────────────────

$logDir       = 'C:\\Users\\User\\oci-arm-host-capacity\\oci-arm-host-capacity';
$logFile      = $logDir . '\\task-log.txt';
$emergencyLog = 'C:\\Users\\User\\Desktop\\oci-emergency-log.txt'; // fallback if main log fails

$time = date('Y-m-d H:i:s');
$logLines = [
    "[$time] Script STARTED",
    "[$time] Current working directory: " . getcwd(),
    "[$time] __DIR__: " . __DIR__,
    "[$time] PHP SAPI: " . php_sapi_name(),
    "[$time] Script path: " . $_SERVER['SCRIPT_FILENAME'] ?? 'unknown',
];

$logContent = implode("\n", $logLines) . "\n\n";

// Try to write to main log
$writeSuccess = @file_put_contents($logFile, $logContent, FILE_APPEND);

if ($writeSuccess === false) {
    // Emergency fallback log on Desktop
    $emergencyMessage = "[$time] MAIN LOG WRITE FAILED\n" .
                        "Tried path: $logFile\n" .
                        "getcwd(): " . getcwd() . "\n" .
                        "Permissions or path issue?\n\n";
    @file_put_contents($emergencyLog, $emergencyMessage, FILE_APPEND);
}

// ────────────────────────────────────────────────
//   ACTUAL SCRIPT LOGIC
// ────────────────────────────────────────────────

$pathPrefix = ''; // adjust only if needed (usually empty for this setup)

require $pathPrefix . 'vendor/autoload.php';

use Dotenv\Dotenv;
use Hitrov\Exception\ApiCallException;
use Hitrov\FileCache;
use Hitrov\OciApi;
use Hitrov\OciConfig;
use Hitrov\TooManyRequestsWaiter;

// Load .env
$envFilename = empty($argv[1]) ? '.env' : $argv[1];
$dotenv = Dotenv::createUnsafeImmutable(__DIR__, $envFilename);
$dotenv->safeLoad();

// Build config from environment variables
$config = new OciConfig(
    getenv('OCI_REGION'),
    getenv('OCI_USER_ID'),
    getenv('OCI_TENANCY_ID'),
    getenv('OCI_KEY_FINGERPRINT'),
    getenv('OCI_PRIVATE_KEY_FILENAME'),
    getenv('OCI_AVAILABILITY_DOMAIN') ?: null,
    getenv('OCI_SUBNET_ID'),
    getenv('OCI_IMAGE_ID'),
    (int) getenv('OCI_OCPUS'),
    (int) getenv('OCI_MEMORY_IN_GBS')
);

// Optional boot volume settings
$bootVolumeSizeInGBs = (string) getenv('OCI_BOOT_VOLUME_SIZE_IN_GBS');
$bootVolumeId        = (string) getenv('OCI_BOOT_VOLUME_ID');

if ($bootVolumeSizeInGBs) {
    $config->setBootVolumeSizeInGBs($bootVolumeSizeInGBs);
} elseif ($bootVolumeId) {
    $config->setBootVolumeId($bootVolumeId);
}

// Initialize API with optional caching & rate limiting
$api = new OciApi();

if (getenv('CACHE_AVAILABILITY_DOMAINS')) {
    $api->setCache(new FileCache($config));
}

if (getenv('TOO_MANY_REQUESTS_TIME_WAIT')) {
    $api->setWaiter(new TooManyRequestsWaiter((int) getenv('TOO_MANY_REQUESTS_TIME_WAIT')));
}

// Telegram notifier (or your custom one)
$notifier = new \Hitrov\Notification\Telegram();

$shape = getenv('OCI_SHAPE');
$maxRunningInstancesOfThatShape = (int) (getenv('OCI_MAX_INSTANCES') ?: 1);

// Check existing instances first
$instances = $api->getInstances($config);
$existing = $api->checkExistingInstances($config, $instances, $shape, $maxRunningInstancesOfThatShape);

if ($existing) {
    echo "$existing\n";
    // Optional: log success/failure
    @file_put_contents($logFile, "[$time] Existing instance found: $existing\n", FILE_APPEND);
    exit(0);
}

// Get availability domains
if (!empty($config->availabilityDomains)) {
    $availabilityDomains = is_array($config->availabilityDomains)
        ? $config->availabilityDomains
        : [$config->availabilityDomains];
} else {
    $availabilityDomains = $api->getAvailabilityDomains($config);
}

// Try each availability domain
foreach ($availabilityDomains as $availabilityDomainEntity) {
    $availabilityDomain = is_array($availabilityDomainEntity)
        ? $availabilityDomainEntity['name']
        : $availabilityDomainEntity;

    try {
        $instanceDetails = $api->createInstance(
            $config,
            $shape,
            getenv('OCI_SSH_PUBLIC_KEY'),
            $availabilityDomain
        );

        // Success!
        $message = json_encode($instanceDetails, JSON_PRETTY_PRINT);
        echo "$message\n";

        if ($notifier->isSupported()) {
            $notifier->notify($message);
        }

        // Log success
        @file_put_contents($logFile, "[$time] SUCCESS: Instance created in $availabilityDomain\n$message\n\n", FILE_APPEND);
        exit(0);

    } catch (ApiCallException $e) {
        $errorMessage = $e->getMessage();
        echo "$errorMessage\n";

        // Log error
        @file_put_contents($logFile, "[$time] ERROR in $availabilityDomain: $errorMessage (code: {$e->getCode()})\n", FILE_APPEND);

        // Special handling for capacity issues
        if (
            $e->getCode() === 500 &&
            str_contains($errorMessage, 'InternalError') &&
            str_contains($errorMessage, 'Out of host capacity')
        ) {
            sleep(16); // backoff before next AD
            continue;
        }

        // Other errors → stop
        if ($notifier->isSupported()) {
            $notifier->notify("Failed: $errorMessage");
        }
        exit(1);
    }
}

// If we reach here, no capacity anywhere
$finalMessage = "[$time] No capacity in any availability domain\n";
echo $finalMessage;
@file_put_contents($logFile, $finalMessage, FILE_APPEND);