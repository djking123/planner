<?php
/**
 * Advanced PHP Logger
 * Provides detailed logging functionality with multiple log levels,
 * memory tracking, and log rotation
 */

$logger_instances = [];

function initLogger($instance_name, $config_override = []) {
    global $logger_instances;
    
    $default_config = [
        'log_file' => 'log.log',
        'max_file_size' => 5 * 1024 * 1024,
        'timezone' => 'Europe/Amsterdam',
        'date_format' => 'Y-m-d H:i:s',
        'log_level' => 'DEBUG'  // Add default log level
    ];

    $logger_instances[$instance_name] = [
        'config' => array_merge($default_config, $config_override),
        'start_time' => microtime(true),
        'log_entries' => []
    ];

    date_default_timezone_set($logger_instances[$instance_name]['config']['timezone']);
    return $instance_name;
}

// Define log levels
const LOG_LEVEL = [
    'DEBUG' => 0,
    'INFO' => 1,
    'WARNING' => 2,
    'ERROR' => 3
];

/**
 * Get current formatted timestamp
 */
function getCurrentTime($instance = 'default') {
    global $logger_instances;
    return date($logger_instances[$instance]['config']['date_format']);
}

/**
 * Calculate elapsed time since script start
 */
function getElapsedTime($instance = 'default') {
    global $logger_instances;
    return round((microtime(true) - $logger_instances[$instance]['start_time']) * 1000, 2);
}

/**
 * Get current memory usage formatted
 */
function getMemoryUsage() {
    $memory = memory_get_usage(true);
    return formatBytes($memory);
}

/**
 * Format bytes to human readable format
 */
function formatBytes($bytes) {
    if ($bytes > 1024 * 1024) {
        return round($bytes / (1024 * 1024), 2) . ' MB';
    } elseif ($bytes > 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

/**
 * Log a message with specified level
 */
function logMessage($message, $level = 'INFO', $context = [], $instance = 'default') {
    global $logger_instances;
    
    // Check if message should be logged based on configured level
    if (LOG_LEVEL[$level] < LOG_LEVEL[$logger_instances[$instance]['config']['log_level']]) {
        return;
    }
    
    $elapsed_time = str_pad(getElapsedTime($instance), 8, ' ', STR_PAD_LEFT);
    $memory = str_pad(getMemoryUsage(), 10, ' ', STR_PAD_LEFT);
    
    // Create log entry with more details
    $log_entry = sprintf(
        "[%s][%s][%sms][%s] %s",
        getCurrentTime($instance),
        str_pad($level, 7, ' ', STR_PAD_RIGHT),
        $elapsed_time,
        $memory,
        $message
    );
    
    // Add context if available
    if (!empty($context)) {
        $log_entry .= "\nContext: " . json_encode($context, JSON_PRETTY_PRINT);
    }
    
    // Add stack trace for errors
    if ($level === 'ERROR') {
        $log_entry .= "\nStack trace:\n" . getBacktrace();
    }
    
    $logger_instances[$instance]['log_entries'][] = $log_entry;
}

/**
 * Get formatted backtrace
 */
function getBacktrace() {
    $trace = debug_backtrace();
    $output = '';
    foreach (array_slice($trace, 2) as $i => $t) {
        $output .= "#$i {$t['file']}({$t['line']}): ";
        $output .= (isset($t['class']) ? $t['class'] . '->' : '');
        $output .= "{$t['function']}()\n";
    }
    return $output;
}

/**
 * Generate log headers with system information
 */
function generateLogHeaders($instance = 'default') {
    $headers = [];
    $headers[] = str_repeat('=', 80);
    $headers[] = "Log File Started: " . getCurrentTime($instance);
    $headers[] = "PHP Version: " . PHP_VERSION;
    $headers[] = "Operating System: " . PHP_OS;
    $headers[] = "Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'CLI');
    $headers[] = "Host: " . (gethostname() ?: 'Unknown');
    $headers[] = "Memory Limit: " . ini_get('memory_limit');
    $headers[] = str_repeat('=', 80);
    $headers[] = "Timestamp               Level    Time(ms)  Memory     Message";
    $headers[] = str_repeat('-', 80);
    return implode(PHP_EOL, $headers) . PHP_EOL;
}

/**
 * Write log entries to file with rotation
 */
function writeLogEntries($instance = 'default') {
    global $logger_instances;
    
    $config = $logger_instances[$instance]['config'];
    $log_entries = $logger_instances[$instance]['log_entries'];
    
    $isNewFile = !file_exists($config['log_file']);
    
    // Check if log file needs rotation
    if (file_exists($config['log_file']) && 
        filesize($config['log_file']) > $config['max_file_size']) {
        $backup_file = $config['log_file'] . '.' . date('Y-m-d-H-i-s');
        rename($config['log_file'], $backup_file);
        $isNewFile = true;
    }
    
    // Add headers if it's a new file
    if ($isNewFile) {
        file_put_contents($config['log_file'], generateLogHeaders($instance));
    }
    
    $log_content = implode(PHP_EOL, $log_entries) . PHP_EOL;
    file_put_contents($config['log_file'], $log_content, FILE_APPEND);
    
    // Clear log entries after writing
    $logger_instances[$instance]['log_entries'] = [];
}
?>