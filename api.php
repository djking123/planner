<?php
session_start();
//error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

// Helper to parse .env file
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            putenv(trim($parts[0]) . '=' . trim($parts[1]));
        }
    }
}
loadEnv(__DIR__ . '/.env');

$dbFile = 'trips.db';

// Initialize and migrate database
function initDB() {
    global $dbFile;
    $isNewDB = !file_exists($dbFile);
    $db = new SQLite3($dbFile);

    if ($isNewDB) {
        $db->exec('CREATE TABLE trips (id INTEGER PRIMARY KEY, name TEXT UNIQUE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_saved_at DATETIME)');
        $db->exec('CREATE TABLE waypoints (id INTEGER PRIMARY KEY, trip_id INTEGER, `order` INTEGER, latitude REAL, longitude REAL, location_name TEXT, location_fullname TEXT, type TEXT, date TEXT, FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE CASCADE)');
    } else {
        // Check if last_saved_at column exists and add it if it doesn't
        $result = $db->query("PRAGMA table_info(trips)");
        $columnExists = false;
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === 'last_saved_at') {
                $columnExists = true;
                break;
            }
        }

        if (!$columnExists) {
            $db->exec('ALTER TABLE trips ADD COLUMN last_saved_at DATETIME');
            // Back-fill last_saved_at with created_at for existing trips
            $db->exec('UPDATE trips SET last_saved_at = created_at');
        }

        // Check if comment column exists in waypoints and add it if it doesn't
        $result = $db->query("PRAGMA table_info(waypoints)");
        $commentExists = false;
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === 'comment') {
                $commentExists = true;
                break;
            }
        }

        if (!$commentExists) {
            $db->exec('ALTER TABLE waypoints ADD COLUMN comment TEXT');
        }

        // Check if gpx_path column exists in waypoints and add it if it doesn't
        $result = $db->query("PRAGMA table_info(waypoints)");
        $gpxExists = false;
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === 'gpx_path') {
                $gpxExists = true;
                break;
            }
        }
        if (!$gpxExists) {
            $db->exec('ALTER TABLE waypoints ADD COLUMN gpx_path TEXT');
        }

        // Check if banner_url column exists in trips and add it if it doesn't
        $result = $db->query("PRAGMA table_info(trips)");
        $bannerExists = false;
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === 'banner_url') {
                $bannerExists = true;
                break;
            }
        }
        if (!$bannerExists) {
            $db->exec('ALTER TABLE trips ADD COLUMN banner_url TEXT');
        }

        // Check if users table exists
        $db->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username TEXT UNIQUE, password TEXT, last_active_trip_id INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');

        // Check if last_active_trip_id column exists
        $result = $db->query("PRAGMA table_info(users)");
        $columnExists = false;
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === 'last_active_trip_id') {
                $columnExists = true;
                break;
            }
        }
        if (!$columnExists) {
            $db->exec('ALTER TABLE users ADD COLUMN last_active_trip_id INTEGER');
        }
    }
    $db->close();
}

initDB();

// Authentication check
$action = $_GET['action'] ?? '';

if (!isset($_SESSION['user_id']) && $action !== 'login' && $action !== 'check_auth' && $action !== 'setup_admin' && $action !== 'get_user_count') {
    echo json_encode(['error' => 'Unauthorized', 'auth_required' => true]);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'save') {
    $input = json_decode(file_get_contents('php://input'), true);
    $db = new SQLite3($dbFile);
    
    $name = $input['name'];
    $waypoints = $input['waypoints'];
    
    // Get or create trip
    $stmt = $db->prepare('INSERT OR IGNORE INTO trips (name, created_at, last_saved_at) VALUES (:name, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->execute();
    
    $stmt = $db->prepare('SELECT id FROM trips WHERE name = :name');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $result = $stmt->execute();
    $tripRow = $result->fetchArray(SQLITE3_ASSOC);
    $tripId = $tripRow['id'];
    
    // Update last_saved_at timestamp
    $updateStmt = $db->prepare('UPDATE trips SET last_saved_at = CURRENT_TIMESTAMP WHERE id = :trip_id');
    $updateStmt->bindValue(':trip_id', $tripId, SQLITE3_INTEGER);
    $updateStmt->execute();
    
    // Delete old waypoints for this trip
    $db->exec('DELETE FROM waypoints WHERE trip_id = ' . $tripId);
    
    // Insert new waypoints
    foreach ($waypoints as $index => $wp) {
        $stmt = $db->prepare('INSERT INTO waypoints (trip_id, `order`, latitude, longitude, location_name, location_fullname, type, date, comment, gpx_path) VALUES (:trip_id, :order, :lat, :lng, :name, :fullname, :type, :date, :comment, :gpx_path)');
        $stmt->bindValue(':trip_id', $tripId, SQLITE3_INTEGER);
        $stmt->bindValue(':order', $index, SQLITE3_INTEGER);
        $stmt->bindValue(':lat', $wp['lat'], SQLITE3_FLOAT);
        $stmt->bindValue(':lng', $wp['lng'], SQLITE3_FLOAT);
        $stmt->bindValue(':name', $wp['name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':fullname', $wp['fullname'] ?? $wp['name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':type', $wp['type'], SQLITE3_TEXT);
        $stmt->bindValue(':date', $wp['date'], SQLITE3_TEXT);
        $stmt->bindValue(':comment', $wp['comment'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':gpx_path', $wp['gpxPath'] ?? null, SQLITE3_TEXT);
        $stmt->execute();
    }
    
    $db->close();
    echo json_encode(['success' => true]);
}

else if ($action === 'load') {
    $trip = $_GET['trip'] ?? '';
    $db = new SQLite3($dbFile);
    
    $stmt = $db->prepare('SELECT id, banner_url FROM trips WHERE name = :name');
    $stmt->bindValue(':name', $trip, SQLITE3_TEXT);
    $result = $stmt->execute();
    $tripRow = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$tripRow) {
        $db->close();
        echo json_encode(['waypoints' => [], 'banner_url' => null]);
        return;
    }
    
    $tripId = $tripRow['id'];
    $bannerUrl = $tripRow['banner_url'];
    
    $stmt = $db->prepare('SELECT latitude, longitude, location_name, location_fullname, type, date, comment, gpx_path FROM waypoints WHERE trip_id = :trip_id ORDER BY `order`');
    $stmt->bindValue(':trip_id', $tripId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $waypoints = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $waypoints[] = [
            'lat' => floatval($row['latitude']),
            'lng' => floatval($row['longitude']),
            'name' => $row['location_name'],
            'fullname' => $row['location_fullname'],
            'type' => $row['type'],
            'date' => $row['date'],
            'comment' => $row['comment'] ?? '',
            'gpxPath' => $row['gpx_path'] ?? null
        ];
    }
    
    $db->close();
    echo json_encode(['waypoints' => $waypoints, 'banner_url' => $bannerUrl]);
}

else if ($action === 'trips') {
    $db = new SQLite3($dbFile);
    $result = $db->query('SELECT name FROM trips ORDER BY last_saved_at DESC, created_at DESC');
    
    $trips = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $trips[] = $row['name'];
    }

    // Get user's last active trip
    $lastActiveTrip = null;
    if (isset($_SESSION['user_id'])) {
        $stmt = $db->prepare('SELECT t.name FROM users u JOIN trips t ON u.last_active_trip_id = t.id WHERE u.id = :uid');
        $stmt->bindValue(':uid', $_SESSION['user_id'], SQLITE3_INTEGER);
        $res = $stmt->execute();
        $row = $res->fetchArray(SQLITE3_ASSOC);
        if ($row) $lastActiveTrip = $row['name'];
    }
    
    $db->close();
    echo json_encode(['trips' => $trips, 'last_active' => $lastActiveTrip]);
}

else if ($action === 'get_banner') {
    $q = $_GET['q'] ?? 'travel';
    $accessKey = getenv('UNSPLASH_ACCESS_KEY');
    
    function fetchUnsplash($query, $key) {
        $url = "https://api.unsplash.com/photos/random?query=" . urlencode($query) . "&orientation=landscape&client_id=" . $key;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PlanAndGoTripPlanner');
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $httpCode, 'body' => $res];
    }
    
    $result = fetchUnsplash($q, $accessKey);
    
    // If no photo found (e.g. Dutch query yielded nothing), try translating to English
    if ($result['code'] !== 200) {
        $tUrl = "https://ftapi.pythonanywhere.com/translate?sl=nl&dl=en&text=" . urlencode($q);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $tRes = curl_exec($ch);
        curl_close($ch);
        
        $tData = json_decode($tRes, true);
        if (isset($tData['destination-text']) && !empty($tData['destination-text'])) {
            $translatedQ = $tData['destination-text'];
            $result = fetchUnsplash($translatedQ, $accessKey);
        }
    }
    
    echo $result['body'];
    exit;
}

else if ($action === 'save_banner') {
    $input = json_decode(file_get_contents('php://input'), true);
    $db = new SQLite3($dbFile);
    
    $stmt = $db->prepare('UPDATE trips SET banner_url = :banner WHERE name = :name');
    $stmt->bindValue(':banner', $input['banner_url'], SQLITE3_TEXT);
    $stmt->bindValue(':name', $input['name'], SQLITE3_TEXT);
    $stmt->execute();
    
    $db->close();
    echo json_encode(['success' => true]);
}

else if ($action === 'delete') {
    $input = json_decode(file_get_contents('php://input'), true);
    $db = new SQLite3($dbFile);
    
    $stmt = $db->prepare('DELETE FROM trips WHERE name = :name');
    $stmt->bindValue(':name', $input['name'], SQLITE3_TEXT);
    $stmt->execute();
    
    $db->close();
    echo json_encode(['success' => true]);
}

else if ($action === 'upload_gpx') {
    if (!isset($_FILES['gpx'])) {
        echo json_encode(['error' => 'No file uploaded']);
        exit;
    }
    
    $uploadDir = 'gpx/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    
    $fileName = time() . '_' . basename($_FILES['gpx']['name']);
    $targetPath = $uploadDir . $fileName;
    
    if (move_uploaded_file($_FILES['gpx']['tmp_name'], $targetPath)) {
        echo json_encode(['success' => true, 'path' => $targetPath]);
    } else {
        echo json_encode(['error' => 'Failed to move uploaded file']);
    }
    exit;
}

else if ($action === 'cleanup_gpx') {
    $db = new SQLite3($dbFile);
    $res = $db->query("SELECT gpx_path FROM waypoints WHERE gpx_path IS NOT NULL");
    $usedFiles = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $usedFiles[] = basename($row['gpx_path']);
    }
    $db->close();
    
    $uploadDir = 'gpx/';
    if (!is_dir($uploadDir)) {
        echo json_encode(['success' => true, 'deleted' => 0, 'msg' => 'No GPX directory found']);
        exit;
    }
    
    $allFiles = scandir($uploadDir);
    $deletedCount = 0;
    foreach ($allFiles as $file) {
        if ($file === '.' || $file === '..') continue;
        if (!in_array($file, $usedFiles)) {
            @unlink($uploadDir . $file);
            $deletedCount++;
        }
    }
    
    echo json_encode(['success' => true, 'deleted' => $deletedCount]);
    exit;
}

else if ($action === 'login') {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';
    
    $db = new SQLite3($dbFile);
    $stmt = $db->prepare('SELECT id, password FROM users WHERE username = :username');
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $username;
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
    }
    $db->close();
    exit;
}

else if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

else if ($action === 'check_auth') {
    echo json_encode(['authenticated' => isset($_SESSION['user_id']), 'username' => $_SESSION['username'] ?? null]);
    exit;
}

else if ($action === 'setup_admin') {
    $db = new SQLite3($dbFile);
    $count = $db->querySingle("SELECT COUNT(*) FROM users");
    
    if ($count > 0) {
        echo json_encode(['success' => false, 'error' => 'Admin already exists']);
        $db->close();
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Username and password required']);
        $db->close();
        exit;
    }
    
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (username, password) VALUES (:username, :password)');
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $stmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to create user']);
    }
    $db->close();
    exit;
}

else if ($action === 'list_users') {
    $db = new SQLite3($dbFile);
    $result = $db->query('SELECT id, username, created_at FROM users ORDER BY created_at DESC');
    $users = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $users[] = $row;
    }
    $db->close();
    echo json_encode(['success' => true, 'users' => $users]);
    exit;
}

else if ($action === 'add_user') {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Username and password required']);
        exit;
    }
    
    $db = new SQLite3($dbFile);
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (username, password) VALUES (:username, :password)');
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $stmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to create user (username might already exist)']);
    }
    $db->close();
    exit;
}

else if ($action === 'delete_user') {
    $input = json_decode(file_get_contents('php://input'), true);
    $userId = $input['id'] ?? null;
    
    if (!$userId) {
        echo json_encode(['success' => false, 'error' => 'User ID required']);
        exit;
    }

    if ($userId == $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'error' => 'You cannot delete yourself']);
        exit;
    }
    
    $db = new SQLite3($dbFile);
    $stmt = $db->prepare('DELETE FROM users WHERE id = :id');
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to delete user']);
    }
    $db->close();
    exit;
}

else if ($action === 'get_user_count') {
    $db = new SQLite3($dbFile);
    $count = $db->querySingle("SELECT COUNT(*) FROM users");
    $db->close();
    echo json_encode(['count' => $count]);
    exit;
}

else if ($action === 'set_active_trip') {
    $input = json_decode(file_get_contents('php://input'), true);
    $tripName = $input['trip'] ?? '';
    
    if (!$tripName) {
        echo json_encode(['success' => false]);
        exit;
    }
    
    $db = new SQLite3($dbFile);
    $stmt = $db->prepare('SELECT id FROM trips WHERE name = :name');
    $stmt->bindValue(':name', $tripName, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($row) {
        $tripId = $row['id'];
        $stmt = $db->prepare('UPDATE users SET last_active_trip_id = :tid WHERE id = :uid');
        $stmt->bindValue(':tid', $tripId, SQLITE3_INTEGER);
        $stmt->bindValue(':uid', $_SESSION['user_id'], SQLITE3_INTEGER);
        $stmt->execute();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    $db->close();
    exit;
}

else {
    echo json_encode(['error' => 'Invalid action']);
}
?>
