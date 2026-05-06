<?php
require_once '../session_config.php';
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once '../logger/logger.php';

// Initialize logger
$logger = initLogger('weather', [
    'log_file' => '../logger/weather.log',
    'log_level' => 'INFO'
]);

/**
 * Temperature Data Retriever
 * 
 * This script retrieves temperature data (maximum) based on date:
 * - Historical data for past dates
 * - Forecast data for dates within the next 14 days
 * - 30-year average for dates beyond 14 days in the future
 * 
 * Caching mechanism:
 * - Historical and average data: cached for 1 year
 * - Forecast data: cached for 8 hours
 */


// Caching directory - make sure this directory exists and is writable
$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

/**
 * Gets cached data if available and not expired
 * 
 * @param string $cacheKey The cache key
 * @param string $dataType The type of data (historical, average, or forecast)
 * @return mixed|null The cached data if valid, null otherwise
 */
function getCache($cacheKey, $dataType) {
    global $cacheDir;
    $cacheFile = $cacheDir . '/' . md5($cacheKey) . '.json';
    
	// Use this to bypass cache for debugging:
	//logMessage('DEBUG: bypassing cache');	
	//return null;
	
    if (file_exists($cacheFile)) {
        $cacheData = file_get_contents($cacheFile);
        $cache = json_decode($cacheData, true);
        
        // Different expiration times based on data type
        $expirationTime = 8 * 3600; // Default: 8 hours (for forecast)
        
        if ($dataType === 'historical' || $dataType === 'average') {
            $expirationTime = 365 * 24 * 3600; // 1 year for historical and average data
        }
        
        // Check if cache is still valid
        if (time() - $cache['timestamp'] < $expirationTime) {
            return $cache['data'];
        }
    }
    
    return null;
}

/**
 * Saves data to cache
 * 
 * @param string $cacheKey The cache key
 * @param mixed $data The data to cache
 */
function saveCache($cacheKey, $data) {
    global $cacheDir;
    $cacheFile = $cacheDir . '/' . md5($cacheKey) . '.json';
    
    $cache = [
        'timestamp' => time(),
        'data' => $data
    ];
    
    file_put_contents($cacheFile, json_encode($cache));
}

/**
 * Makes API request with timeout
 */
function makeApiRequest($url) {
    global $logger;
    logMessage('Calling URL: ' . $url, 'DEBUG', [], $logger);    
    $context = stream_context_create([
        'http' => [
            'timeout' => 0.1 // 100ms timeout
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        logMessage('API request failed', 'ERROR', ['url' => $url], $logger);
        return null;
    }
    
    logMessage('Getting the API response', 'DEBUG', json_decode($response), $logger);
    return json_decode($response, true);
}

/**
 * Gets historical temperature for a past date
 */
function getHistoricalTemperature($latitude, $longitude, $date) {
    global $logger;
    logMessage('Gets historical temperature for a past date', 'INFO', [], $logger);
    
    $cacheKey = "historical_{$latitude}_{$longitude}_{$date}";
    $cached = getCache($cacheKey, 'historical');
    
    if ($cached !== null) {
        logMessage('Returning cached temperature', 'INFO', ['temp' => $cached], $logger);
        return [
            'temperature' => $cached,
            'type' => 'historical (cached)'
        ];
    }
    
    $url = "https://archive-api.open-meteo.com/v1/archive?latitude=$latitude&longitude=$longitude&start_date=$date&end_date=$date&daily=temperature_2m_max";
    $data = makeApiRequest($url);
    
    if ($data !== null && isset($data['daily']['temperature_2m_max'][0])) {
        $temperature = $data['daily']['temperature_2m_max'][0];
        saveCache($cacheKey, $temperature);
        
        return [
            'temperature' => $temperature,
            'type' => 'historical'
        ];
    }
    
    return null;
}

/**
 * Gets forecast temperature for a date within the next 14 days
 */
function getForecastTemperature($latitude, $longitude, $date) {
    global $logger;
    logMessage('Gets forecast temperature for a date within the next 14 days', 'INFO', ['date' => $date], $logger);
    
    $cacheKey = "forecast_{$latitude}_{$longitude}_{$date}";
    $cached = getCache($cacheKey, 'forecast');
    
    if ($cached !== null) {
        logMessage('Returning cached temperature', 'DEBUG', ['temp' => $cached], $logger);
        return [
            'temperature' => $cached,
            'type' => 'forecast (cached)'
        ];
    }
    
    $url = "https://api.open-meteo.com/v1/forecast?latitude=$latitude&longitude=$longitude&daily=temperature_2m_max&timezone=auto&start_date=$date&end_date=$date";
    $data = makeApiRequest($url);
    
    if ($data !== null && isset($data['daily']['temperature_2m_max'][0])) {
        $temperature = $data['daily']['temperature_2m_max'][0];
        saveCache($cacheKey, $temperature);
        
        return [
            'temperature' => $temperature,
            'type' => 'forecast'
        ];
    }
    
    return null;
}

/**
 * Gets average temperature based on 10 years of historical data
 */
function getAverageHistoricalTemperature($latitude, $longitude, $date) {
    global $logger;
    logMessage('Gets average temperature based on 10 years of historical data', 'INFO', [], $logger);
    
    $cacheKey = "average_{$latitude}_{$longitude}_{$date}";
    $cached = getCache($cacheKey, 'average');
    
    if ($cached !== null) {
        logMessage('Returning cached temperature', 'DEBUG', ['temp' => $cached], $logger);
        return [
            'temperature' => $cached,
            'type' => 'average (cached)'
        ];
    }
    
    // Parse the input date
    $dateObj = new DateTime($date);
    $month = $dateObj->format('m');
    $day = $dateObj->format('d');
    $currentYear = (int)date('Y');
    
    $temperatures = [];
    
    // Loop through the past 10 years
    for ($year = $currentYear - 10; $year < $currentYear; $year++) {
        // Create the historical date strings for the day before, same day, and day after
        $historicalDates = [
            date('Y-m-d', strtotime("-1 day", strtotime("$year-$month-$day"))),
            "$year-$month-$day",
            date('Y-m-d', strtotime("+1 day", strtotime("$year-$month-$day")))
        ];
        
        foreach ($historicalDates as $historicalDate) {
            // Skip future dates
            if (strtotime($historicalDate) > time()) {
                continue;
            }
            
            // Build the API URL
            $url = "https://archive-api.open-meteo.com/v1/archive?latitude=$latitude&longitude=$longitude&start_date=$historicalDate&end_date=$historicalDate&daily=temperature_2m_max";
            
            // Make the API request
            $data = makeApiRequest($url);
            
            // Extract and store the temperature
            if ($data !== null && isset($data['daily']['temperature_2m_max'][0])) {
                $temperatures[] = $data['daily']['temperature_2m_max'][0];
            }

			// Add a small delay
            //usleep(100000); // 0.1 seconds
        }
    }
    
    // Calculate average if we have data
    if (count($temperatures) > 0) {
        // debug:
		//print_r($temperatures);
		
		$average = array_sum($temperatures) / count($temperatures);
        // Round to 1 decimal place
        $average = round($average, 1);
        saveCache($cacheKey, $average);
        
        return [
            'temperature' => $average,
            'type' => 'average'
        ];
    }
    
    return null;
}

/**
 * Main function to get temperature data based on date
 */
function getTemperature($latitude, $longitude, $date) {
    $today = new DateTime();
	$today->setTime(0, 0); // Reset time to midnight
    $targetDate = new DateTime($date);
	$targetDate->setTime(0, 0); // Reset time to midnight
    $dateDiff = $targetDate->diff($today)->days;
    $isInFuture = $targetDate >= $today;
    
	// Case 1: Date is in the past - use historical data
    if (!$isInFuture) {
        return getHistoricalTemperature($latitude, $longitude, $date);
    }
    
    // Case 2: Date is within next 14 days - use forecast
    if ($isInFuture && $dateDiff <= 14) {
        return getForecastTemperature($latitude, $longitude, $date);
    }
    
    // Case 3: Date is more than 14 days in the future - use 30-year average
    return getAverageHistoricalTemperature($latitude, $longitude, $date);
}

// Check if script is being run directly
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get parameters from query string
    $latitude = isset($_GET['latitude']) ? (float)$_GET['latitude'] : null;
    $longitude = isset($_GET['longitude']) ? (float)$_GET['longitude'] : null;
    $date = isset($_GET['date']) ? $_GET['date'] : null;
    
    logMessage('# Processing request', 'INFO', [
        'latitude' => $latitude,
        'longitude' => $longitude,
        'date' => $date,
        'requestor' => $_SERVER['REMOTE_ADDR']
    ], $logger);
    
    // Validate inputs
    if ($latitude === null || $longitude === null || $date === null) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Missing required parameters']);
        logMessage('Missing required parameters', 'ERROR', [], $logger);
        writeLogEntries($logger);
        exit;
    }
    
    // Check date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD']);
        logMessage('Invalid date format. Use YYYY-MM-DD', 'ERROR', [], $logger);
        writeLogEntries($logger);
        exit;
    }
    
    // Get temperature data
    $result = getTemperature($latitude, $longitude, $date);
    
    if ($result === null) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unable to retrieve temperature data']);
        logMessage('Unable to retrieve temperature data', 'ERROR', [], $logger);
        writeLogEntries($logger);
        exit;
    }
    
    // Return simple output with temperature and type
    header('Content-Type: application/json');
    echo json_encode([
        'temperature' => $result['temperature'],
        'type' => $result['type'],
        'unit' => '°C'
    ]);
    
    logMessage('Request completed', 'INFO', [
        'temperature' => $result['temperature'],
        'type' => $result['type'],
        'unit' => '°C'
    ], $logger);
    logMessage('# Ending the script', 'INFO', [], $logger);
    writeLogEntries($logger);
}
?>