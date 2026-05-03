<?php
session_start();
if (isset($_SESSION['user_id'])) {
    $db_check = new SQLite3('trips.db');
    $res = $db_check->querySingle("SELECT id FROM users WHERE id = " . (int)$_SESSION['user_id']);
    $db_check->close();
    if (!$res) {
        session_destroy();
        header('Location: login.php');
        exit;
    }
} else {
    header('Location: login.php');
    exit;
}
$db = new SQLite3('trips.db');

// Global Stats
$totalTrips = $db->querySingle("SELECT COUNT(*) FROM trips");
$totalWaypoints = $db->querySingle("SELECT COUNT(*) FROM waypoints");
$latestTrip = $db->querySingle("SELECT name FROM trips ORDER BY last_saved_at DESC LIMIT 1");

// Type distribution
$type_counts = [];
$type_res = $db->query("SELECT type, COUNT(*) as c FROM waypoints GROUP BY type");
while ($tr = $type_res->fetchArray(SQLITE3_ASSOC)) {
    $type_counts[$tr['type']] = $tr['c'];
}

// Get all tables for the existing explorer
$tables_query = $db->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
$tables = [];
while ($row = $tables_query->fetchArray(SQLITE3_ASSOC)) {
    $tableName = $row['name'];
    $row['count'] = $db->querySingle("SELECT COUNT(*) FROM $tableName");
    $cols = [];
    $cols_query = $db->query("PRAGMA table_info($tableName)");
    while ($col = $cols_query->fetchArray(SQLITE3_ASSOC)) { $cols[] = $col['name']; }
    $row['columns'] = $cols;
    $data = [];
    $data_query = $db->query("SELECT * FROM $tableName LIMIT 100");
    while ($item = $data_query->fetchArray(SQLITE3_ASSOC)) { $data[] = $item; }
    $row['data'] = $data;
    $tables[] = $row;
}

// Trips Overview for Blocks
$trips_overview = [];
$to_res = $db->query("SELECT id, name, banner_url FROM trips ORDER BY last_saved_at DESC");
while ($to_row = $to_res->fetchArray(SQLITE3_ASSOC)) {
    $trips_overview[] = $to_row;
}

// Hotel Itinerary Logic with Validation
$trips_hotels = [];
// Get min/max dates for each trip to determine full duration
$trip_bounds = [];
$b_res = $db->query("SELECT trip_id, MIN(date) as start_date, MAX(date) as end_date FROM waypoints WHERE type != 'poi' GROUP BY trip_id");
while ($b_row = $b_res->fetchArray(SQLITE3_ASSOC)) {
    $trip_bounds[$b_row['trip_id']] = ['start' => $b_row['start_date'], 'end' => $b_row['end_date']];
}

$h_res = $db->query("SELECT w.*, t.name as trip_name FROM waypoints w JOIN trips t ON w.trip_id = t.id WHERE w.type = 'hotel' ORDER BY w.trip_id, w.date, w.`order` ASC");

while ($h_row = $h_res->fetchArray(SQLITE3_ASSOC)) {
    $tid = $h_row['trip_id'];
    if (!isset($trips_hotels[$tid])) {
        $trips_hotels[$tid] = [
            'name' => $h_row['trip_name'], 
            'stays' => [], 
            'warnings' => [], 
            'bounds' => $trip_bounds[$tid] ?? null
        ];
    }
    
    $last_idx = count($trips_hotels[$tid]['stays']) - 1;
    $row_date = new DateTime($h_row['date']);
    
    if ($last_idx >= 0) {
        $last_stay = &$trips_hotels[$tid]['stays'][$last_idx];
        $last_checkout = new DateTime($last_stay['checkout']);
        
        if ($last_stay['name'] === $h_row['location_name'] && $last_checkout == $row_date) {
            $next_day = clone $row_date;
            $next_day->modify('+1 day');
            $last_stay['checkout'] = $next_day->format('Y-m-d');
            $last_stay['nights']++;
            if (!empty($h_row['comment']) && strpos($last_stay['comment'], $h_row['comment']) === false) {
                $last_stay['comment'] .= " | " . $h_row['comment'];
            }
            continue;
        }
    }
    
    $checkout = clone $row_date;
    $checkout->modify('+1 day');
    $trips_hotels[$tid]['stays'][] = [
        'name' => $h_row['location_name'],
        'address' => $h_row['location_fullname'],
        'checkin' => $h_row['date'],
        'checkout' => $checkout->format('Y-m-d'),
        'nights' => 1,
        'comment' => $h_row['comment'] ?? ''
    ];
}

// Perform Validation
foreach ($trips_hotels as $tid => &$trip) {
    if (!$trip['bounds']) continue;
    
    $start = new DateTime($trip['bounds']['start']);
    $end = new DateTime($trip['bounds']['end']);
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end);
    
    foreach ($period as $date) {
        $d_str = $date->format('Y-m-d');
        $hotels_tonight = [];
        foreach ($trip['stays'] as $stay) {
            $s_in = new DateTime($stay['checkin']);
            $s_out = new DateTime($stay['checkout']);
            if ($date >= $s_in && $date < $s_out) {
                $hotels_tonight[] = $stay['name'];
            }
        }
        
        if (count($hotels_tonight) === 0) {
            $trip['warnings'][] = ["type" => "missing", "date" => $d_str, "msg" => "Missing hotel for night of " . $d_str];
        } else if (count($hotels_tonight) > 1) {
            $trip['warnings'][] = ["type" => "overlap", "date" => $d_str, "msg" => "Overlapping bookings on " . $d_str . " (" . implode(", ", $hotels_tonight) . ")"];
        }
    }
}
unset($trip);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plan&Go Labs | DevTools</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        :root { 
            --primary: #1e293b; 
            --accent: #3b82f6; 
            --bg-app: #f8fafc;
            --border: rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
        }
        body { margin: 0; font-family: 'Outfit', 'Inter', sans-serif; background: var(--bg-app); color: #0f172a; padding: 40px 20px; display: flex; flex-direction: column; align-items: center; }
        .container { width: 100%; max-width: 1100px; }
        .header { display: flex; align-items: center; gap: 20px; margin-bottom: 30px; background: var(--primary); color: white; padding: 30px; border-radius: 20px; box-shadow: var(--shadow-lg); }
        .header i { font-size: 40px; color: var(--accent); }
        .header h1 { margin: 0; font-size: 2em; letter-spacing: -1px; }

        /* Weather Map */
        .map-card { background: white; border-radius: 20px; box-shadow: var(--shadow-md); border: 1px solid var(--border); margin-bottom: 40px; overflow: hidden; }
        #weather-map { height: 450px; width: 100%; z-index: 10; }
        .map-info { padding: 15px 20px; background: #f8fafc; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .weather-legend { display: flex; gap: 15px; font-size: 0.8em; font-weight: 600; }
        .legend-item { display: flex; align-items: center; gap: 5px; }
        .legend-dot { width: 10px; height: 10px; border-radius: 50%; }

        /* Dashboard Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 40px; }
        .stat-card { background: white; padding: 25px; border-radius: 20px; box-shadow: var(--shadow-md); border: 1px solid var(--border); display: flex; align-items: center; gap: 20px; }
        .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .stat-val { font-size: 1.8em; font-weight: 700; color: var(--primary); }
        .stat-label { font-size: 0.85em; color: #64748b; font-weight: 600; text-transform: uppercase; }

        /* Chart-like Bars */
        .chart-card { background: white; padding: 25px; border-radius: 20px; box-shadow: var(--shadow-md); border: 1px solid var(--border); margin-bottom: 40px; }
        .chart-row { margin-bottom: 15px; }
        .chart-label { display: flex; justify-content: space-between; font-size: 0.9em; font-weight: 600; margin-bottom: 8px; }
        .chart-bar-bg { background: #f1f5f9; height: 8px; border-radius: 4px; overflow: hidden; }
        .chart-bar-fill { height: 100%; border-radius: 4px; transition: width 1s ease; }

        .card { background: white; border-radius: 16px; padding: 25px; margin-bottom: 40px; box-shadow: var(--shadow-md); border: 1px solid var(--border); overflow: hidden; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 15px; }
        .card-header h2 { margin: 0; font-size: 1.25em; color: var(--primary); }
        .badge { background: #eff6ff; color: var(--accent); padding: 4px 12px; border-radius: 20px; font-size: 0.8em; font-weight: 600; }
        h3 { font-size: 0.9em; text-transform: uppercase; color: #64748b; margin: 20px 0 10px 0; letter-spacing: 0.5px; }
        pre { background: #1e293b; color: #e2e8f0; padding: 20px; border-radius: 12px; overflow-x: auto; font-family: monospace; font-size: 0.85em; margin-bottom: 25px; }
        .table-container { width: 100%; overflow-x: auto; border-radius: 12px; border: 1px solid var(--border); }
        table { width: 100%; border-collapse: collapse; font-size: 0.85em; text-align: left; }
        th { background: #f1f5f9; padding: 12px 15px; color: #475569; border-bottom: 1px solid var(--border); }
        td { padding: 12px 15px; border-bottom: 1px solid #f8fafc; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .trips-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .trip-block {
            position: relative;
            height: 160px;
            border-radius: 16px;
            overflow: hidden;
            background-color: var(--card-bg);
            background-size: cover;
            background-position: center;
            display: flex;
            align-items: flex-end;
            padding: 20px;
            color: white;
            text-decoration: none;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.3s ease;
            box-shadow: var(--shadow-sm);
        }
        .trip-block:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }
        .trip-block::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(to bottom, rgba(0,0,0,0) 20%, rgba(0,0,0,0.85) 100%);
            z-index: 1;
        }
        .trip-block h3 {
            position: relative;
            z-index: 2;
            margin: 0;
            font-size: 1.35em;
            font-weight: 800;
            color: #ffffff;
            text-shadow: 0 2px 10px rgba(0,0,0,0.8);
            letter-spacing: -0.5px;
        }
        .trip-block .badge-count {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(4px);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 600;
            z-index: 2;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <i class="fas fa-flask"></i>
            <div>
                <h1>Labs / DevTools</h1>
                <p>Global Analytics & Database Explorer V 1.3</p>
            </div>
        </div>

        <!-- Trips Gallery -->
        <h2 style="margin: 30px 0 15px 0; font-size: 1.3em; font-weight: 800; color: var(--primary); display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-th-large" style="opacity: 0.3;"></i> Your Trips Gallery
        </h2>
        <div class="trips-grid">
            <?php foreach ($trips_overview as $trip): ?>
                <a href="index.php" onclick="localStorage.setItem('lastTrip', '<?= htmlspecialchars($trip['name']) ?>')" class="trip-block" style="background-image: url('<?= $trip['banner_url'] ?? 'https://images.unsplash.com/photo-1488646953014-85cb44e25828?auto=format&fit=crop&q=80&w=800' ?>');">
                    <div class="badge-count">ID: #<?= $trip['id'] ?></div>
                    <h3><?= htmlspecialchars($trip['name']) ?></h3>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: #eff6ff; color: #3b82f6;"><i class="fas fa-route"></i></div>
                <div><div class="stat-val"><?= $totalTrips ?></div><div class="stat-label">Total Trips</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #f0fdf4; color: #22c55e;"><i class="fas fa-map-marker-alt"></i></div>
                <div><div class="stat-val"><?= $totalWaypoints ?></div><div class="stat-label">Waypoints</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #fff7ed; color: #f97316;"><i class="fas fa-history"></i></div>
                <div><div class="stat-val" style="font-size: 1.1em;"><?= $latestTrip ?: 'None' ?></div><div class="stat-label">Last Active</div></div>
            </div>
        </div>

        <div class="chart-card">
            <h3>Waypoint Category Breakdown</h3>
            <?php 
            $colors = ['sight' => '#22c55e', 'hotel' => '#f1c40f', 'hike' => '#ea580c', 'poi' => '#a855f7'];
            foreach (['sight', 'hotel', 'hike', 'poi'] as $type): 
                $count = $type_counts[$type] ?? 0;
                $perc = $totalWaypoints > 0 ? ($count / $totalWaypoints) * 100 : 0;
            ?>
            <div class="chart-row">
                <div class="chart-label">
                    <span><i class="fas fa-circle" style="color: <?= $colors[$type] ?>; font-size: 8px; vertical-align: middle;"></i> <?= ucfirst($type) ?>s</span>
                    <span><?= $count ?> (<?= round($perc) ?>%)</span>
                </div>
                <div class="chart-bar-bg"><div class="chart-bar-fill" style="width: <?= $perc ?>%; background: <?= $colors[$type] ?>;"></div></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="map-card">
            <div class="card-header" style="margin-bottom: 0; border-bottom: none; padding: 20px 25px;">
                <h2 style="font-size: 1.1em;"><i class="fas fa-temperature-high" style="margin-right: 10px; color: #f97316;"></i> Global Trip Weather Scope</h2>
                <span class="badge" style="background: #fff7ed; color: #f97316;">Live Thermal Data</span>
            </div>
            <div id="weather-map"></div>
            <div class="map-info">
                <div style="font-size: 0.85em; color: #64748b;">Showing temperatures for unique trip locations</div>
                <div class="weather-legend">
                    <div class="legend-item"><div class="legend-dot" style="background: #3b82f6;"></div> < 10°C</div>
                    <div class="legend-item"><div class="legend-dot" style="background: #22c55e;"></div> 10-20°C</div>
                    <div class="legend-item"><div class="legend-dot" style="background: #f59e0b;"></div> 20-28°C</div>
                    <div class="legend-item"><div class="legend-dot" style="background: #ef4444;"></div> > 28°C</div>
                </div>
            </div>
        </div>

        <div class="map-card">
            <div class="card-header" style="margin-bottom: 0; border-bottom: none; padding: 20px 25px;">
                <h2 style="font-size: 1.1em;"><i class="fas fa-bed" style="margin-right: 10px; color: #f1c40f;"></i> Hotel Booking Summary</h2>
                <span class="badge" style="background: #fffbeb; color: #f1c40f;">Itinerary View</span>
            </div>
            <div style="padding: 25px;">
                <?php if (count($trips_hotels) > 0): ?>
                    <?php foreach ($trips_hotels as $tid => $trip): ?>
                        <div style="margin-bottom: 30px;">
                            <h4 style="margin: 0 0 15px 0; color: var(--primary); font-size: 1.1em; display: flex; align-items: center; justify-content: space-between;">
                                <span><i class="fas fa-route" style="opacity: 0.3; margin-right: 10px;"></i> <?= htmlspecialchars($trip['name']) ?></span>
                                <?php if (!empty($trip['warnings'])): ?>
                                    <button class="badge" onclick="document.getElementById('warn-<?= $tid ?>').style.display = document.getElementById('warn-<?= $tid ?>').style.display === 'none' ? 'block' : 'none'" 
                                            style="cursor: pointer; background: #fff7ed; color: #92400e; border: 1px solid #fed7aa; display: flex; align-items: center; gap: 8px;">
                                        <i class="fas fa-exclamation-triangle"></i> <?= count($trip['warnings']) ?> Issues Detected
                                    </button>
                                <?php endif; ?>
                            </h4>
                            
                            <?php if (!empty($trip['warnings'])): ?>
                                <div id="warn-<?= $tid ?>" style="display: none; margin-bottom: 15px; border-radius: 12px; overflow: hidden; border: 1px solid #fee2e2; animation: slideIn 0.3s ease;">
                                    <?php foreach ($trip['warnings'] as $warning): ?>
                                        <div style="background: <?= $warning['type'] == 'overlap' ? '#fef2f2' : '#fffbeb' ?>; color: <?= $warning['type'] == 'overlap' ? '#991b1b' : '#92400e' ?>; padding: 10px 15px; border-bottom: 1px solid rgba(0,0,0,0.05); font-size: 0.85em; display: flex; align-items: center; gap: 10px;">
                                            <i class="fas <?= $warning['type'] == 'overlap' ? 'fa-exclamation-circle' : 'fa-bed' ?>"></i>
                                            <?= htmlspecialchars($warning['msg']) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Hotel Name</th>
                                            <th>Check-in</th>
                                            <th>Check-out</th>
                                            <th>Nights</th>
                                            <th>Comments</th>
                                            <th>Address</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($trip['stays'] as $stay): ?>
                                            <tr>
                                                <td style="font-weight: 600;"><?= htmlspecialchars($stay['name']) ?></td>
                                                <td><?= htmlspecialchars($stay['checkin']) ?></td>
                                                <td style="color: #059669; font-weight: 600;"><?= htmlspecialchars($stay['checkout']) ?></td>
                                                <td><span class="badge" style="background: #f1f5f9; color: #475569;"><?= $stay['nights'] ?> <?= $stay['nights'] > 1 ? 'nights' : 'night' ?></span></td>
                                                <td style="font-size: 0.9em; font-style: italic; color: #475569;"><?= htmlspecialchars($stay['comment']) ?></td>
                                                <td style="font-size: 0.9em; color: #64748b;"><?= htmlspecialchars($stay['address']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">No hotel bookings found across all trips.</div>
                <?php endif; ?>
            </div>
        </div>


        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-users-cog" style="margin-right: 10px; opacity: 0.5;"></i> User Management</h2>
                <span class="badge" id="user-count-badge">Loading...</span>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; padding: 10px 0;">
                <div>
                    <h3>Current Users</h3>
                    <div class="table-container">
                        <table id="users-table">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="users-tbody">
                                <!-- Users will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div>
                    <h3>Add New User</h3>
                    <div style="background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid var(--border);">
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; font-size: 0.8em; font-weight: 600; color: #64748b; margin-bottom: 5px;">Username</label>
                            <input type="text" id="new-username" style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px;">
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 0.8em; font-weight: 600; color: #64748b; margin-bottom: 5px;">Password</label>
                            <input type="password" id="new-password" style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px;">
                        </div>
                        <button onclick="addUser()" style="background: var(--accent); color: white; border: none; padding: 12px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; width: 100%; transition: 0.2s;">
                            <i class="fas fa-user-plus"></i> Create User
                        </button>
                        <div id="user-feedback" style="margin-top: 15px; font-size: 0.85em; display: none; padding: 10px; border-radius: 8px;"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-broom" style="margin-right: 10px; opacity: 0.5;"></i> System Maintenance</h2>
                <span class="badge">Optimization</span>
            </div>
            <p style="font-size: 0.9em; color: #64748b; margin-bottom: 20px;">
                Scan the <code>gpx/</code> directory and remove any files that are no longer referenced in any trip in the database. 
                This helps keep the server storage clean.
            </p>
            <button onclick="cleanupGPX(event)" style="background: var(--accent); color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-trash-alt"></i> Cleanup Unused GPX Files
            </button>
            <div id="cleanup-feedback" style="margin-top: 15px; font-size: 0.9em; font-weight: 600; display: none; padding: 12px; border-radius: 8px; animation: slideIn 0.3s ease;"></div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2><i class="fab fa-github" style="margin-right: 10px; opacity: 0.5;"></i> Software Update</h2>
                <span class="badge">Version Control</span>
            </div>
            <p style="font-size: 0.9em; color: #64748b; margin-bottom: 20px;">
                Pull the latest version of Plan&Go from GitHub. This will update the application code to the most recent release.
            </p>
            <button onclick="pullUpdate(event)" style="background: #24292e; color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 10px;">
                <i class="fab fa-github"></i> Pull Latest from GitHub
            </button>
            <div id="update-feedback" style="margin-top: 15px; font-size: 0.9em; font-weight: 600; display: none; padding: 12px; border-radius: 8px; animation: slideIn 0.3s ease;"></div>
            <pre id="update-output" style="display: none; margin-top: 15px; max-height: 200px; overflow-y: auto;"></pre>
        </div>

        <?php foreach ($tables as $table): ?>
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-table" style="margin-right: 10px; opacity: 0.5;"></i> Table: <?= htmlspecialchars($table['name']) ?></h2>
                <span class="badge"><?= $table['count'] ?> rows</span>
            </div>
            
            <h3>Schema Definition</h3>
            <pre><?= htmlspecialchars($table['sql']) ?></pre>

            <h3>Data Preview</h3>
            <div class="table-container">
                <?php if (count($table['data']) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <?php foreach ($table['columns'] as $col): ?>
                            <th><?= htmlspecialchars($col) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($table['data'] as $row): ?>
                        <tr>
                            <?php foreach ($table['columns'] as $col): ?>
                            <td title="<?= htmlspecialchars($row[$col]) ?>"><?= htmlspecialchars($row[$col]) ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">No records found in this table.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <footer style="text-align: center; margin-top: 40px; opacity: 0.5; font-size: 0.8em; padding-bottom: 40px;">
            Plan&Go Developer Tools &bull; SQLite v<?= SQLite3::version()['versionString'] ?>
        </footer>
    </div>

    <script>
        // Initialize Map
        const weatherMap = L.map('weather-map').setView([20, 0], 2);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; OpenStreetMap'
        }).addTo(weatherMap);

        const markers = [];
        
        // Fetch all waypoints from PHP with Trip Details
        <?php
        $wp_query = $db->query("
            SELECT w.latitude, w.longitude, w.location_name, w.location_fullname, w.type, w.date, t.name as trip_name 
            FROM waypoints w 
            JOIN trips t ON w.trip_id = t.id 
            ORDER BY w.id DESC LIMIT 100
        ");
        $all_wps = [];
        while($w = $wp_query->fetchArray(SQLITE3_ASSOC)) {
            $all_wps[] = $w;
        }
        ?>
        const waypoints = <?= json_encode($all_wps) ?>;

        function getTempColor(t) {
            if (t < 10) return '#3b82f6'; // Blue
            if (t < 20) return '#22c55e'; // Green
            if (t < 28) return '#f59e0b'; // Orange
            return '#ef4444'; // Red
        }

        async function loadWeatherMarkers() {
            console.log("Loading weather markers for", waypoints.length, "locations...");
            const bounds = L.latLngBounds();
            let count = 0;

            for (const wp of waypoints) {
                // Ensure we have a valid date or use today
                const wpDate = wp.date || new Date().toISOString().split('T')[0];
                
                try {
                    const resp = await fetch(`weatherAPI/weather.php?latitude=${wp.latitude}&longitude=${wp.longitude}&date=${wpDate}`);
                    const data = await resp.json();
                    
                    let temp = null;
                    let unit = '°C';
                    let wType = 'Unknown';
                    let color = '#94a3b8'; // Default gray if no weather
                    
                    if (data && data.temperature !== undefined) {
                        temp = parseFloat(data.temperature);
                        unit = data.unit || '°C';
                        wType = data.type || 'Clear';
                        color = getTempColor(temp);
                    }

                    const marker = L.circleMarker([wp.latitude, wp.longitude], {
                        radius: 10,
                        fillColor: color,
                        color: 'white',
                        weight: 2,
                        opacity: 1,
                        fillOpacity: 0.9
                    }).addTo(weatherMap);

                    marker.bindTooltip(`
                        <div style="font-family: 'Outfit', sans-serif; padding: 8px; min-width: 180px;">
                            <div style="display: flex; justify-content: space-between; font-size: 0.75em; text-transform: uppercase; color: #94a3b8; font-weight: 700; margin-bottom: 2px;">
                                <span>${wp.trip_name}</span>
                                <span>${wp.date}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                                <strong style="font-size: 1.2em; color: var(--primary);">${temp !== null ? temp + unit : '--'}</strong>
                                <div style="display: flex; gap: 4px;">
                                    <span style="font-size: 0.75em; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; font-weight: 600;">${wType}</span>
                                    <span style="font-size: 0.75em; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; color: #64748b;">${wp.type}</span>
                                </div>
                            </div>
                            <div style="font-weight: 600; font-size: 0.9em; margin-bottom: 2px;">${wp.location_name}</div>
                            <div style="font-size: 0.8em; color: #64748b; line-height: 1.3;">${wp.location_fullname}</div>
                        </div>
                    `, { permanent: false, direction: 'top' });

                    bounds.extend([wp.latitude, wp.longitude]);
                    count++;
                } catch (e) { 
                    console.error("Failed to fetch weather for", wp.location_name, e); 
                    // Add a basic marker anyway if we have coordinates
                    if (wp.latitude && wp.longitude) {
                        const marker = L.circleMarker([wp.latitude, wp.longitude], {
                            radius: 8,
                            fillColor: '#cbd5e1',
                            color: 'white',
                            weight: 1,
                            fillOpacity: 0.5
                        }).addTo(weatherMap);
                        
                        marker.bindTooltip(`
                                <div style="font-size: 0.75em; text-transform: uppercase; color: #94a3b8; font-weight: 700; margin-bottom: 2px;">${wp.trip_name}</div>
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                                    <strong style="font-size: 1.2em; color: var(--primary);">--</strong>
                                    <span style="font-size: 0.8em; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; color: #64748b;">${wp.type}</span>
                                </div>
                                <div style="font-weight: 600; font-size: 0.9em; margin-bottom: 2px;">${wp.location_name}</div>
                                <div style="font-size: 0.8em; color: #64748b; line-height: 1.3;">${wp.location_fullname}</div>
                            </div>
                        `, { permanent: false, direction: 'top' });
                        
                        bounds.extend([wp.latitude, wp.longitude]);
                        count++;
                    }
                }
            }

            if (count > 0) {
                console.log("Displayed", count, "markers.");
                weatherMap.fitBounds(bounds, { padding: [50, 50] });
            } else {
                console.warn("No markers to display on weather map.");
            }
        }

        loadWeatherMarkers();

        async function cleanupGPX(event) {
            const btn = event.currentTarget;
            const feedback = document.getElementById('cleanup-feedback');
            
            btn.disabled = true;
            btn.style.opacity = '0.7';
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cleaning up...';
            
            try {
                const res = await fetch('api.php?action=cleanup_gpx');
                const data = await res.json();
                
                feedback.style.display = 'block';
                if (data.success) {
                    feedback.style.background = '#f0fdf4';
                    feedback.style.color = '#166534';
                    feedback.style.border = '1px solid #bbf7d0';
                    feedback.innerHTML = `<i class="fas fa-check-circle" style="margin-right: 8px;"></i> Successfully deleted ${data.deleted} unused GPX files.`;
                } else {
                    throw new Error(data.error || 'Unknown error');
                }
            } catch (err) {
                feedback.style.display = 'block';
                feedback.style.background = '#fef2f2';
                feedback.style.color = '#991b1b';
                feedback.style.border = '1px solid #fee2e2';
                feedback.innerHTML = `<i class="fas fa-times-circle" style="margin-right: 8px;"></i> Error: ${err.message}`;
            } finally {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.innerHTML = '<i class="fas fa-trash-alt"></i> Cleanup Unused GPX Files';
            }
        }

        async function loadUsers() {
            try {
                const res = await fetch('api.php?action=list_users');
                const data = await res.json();
                
                if (data.success) {
                    const tbody = document.getElementById('users-tbody');
                    tbody.innerHTML = '';
                    
                    data.users.forEach(user => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td style="font-weight: 600;">${user.username}</td>
                            <td style="color: #64748b;">${new Date(user.created_at).toLocaleDateString()}</td>
                            <td>
                                <button onclick="deleteUser(${user.id}, '${user.username}')" style="background: none; border: none; color: #ef4444; cursor: pointer; padding: 5px;" title="Delete User">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </td>
                        `;
                        tbody.appendChild(row);
                    });
                    
                    document.getElementById('user-count-badge').textContent = `${data.users.length} Users`;
                }
            } catch (err) {
                console.error('Failed to load users', err);
            }
        }

        async function addUser() {
            const usernameInput = document.getElementById('new-username');
            const passwordInput = document.getElementById('new-password');
            const feedback = document.getElementById('user-feedback');
            
            const username = usernameInput.value.trim();
            const password = passwordInput.value.trim();
            
            if (!username || !password) {
                showFeedback('Username and password are required', 'error');
                return;
            }
            
            try {
                const res = await fetch('api.php?action=add_user', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, password })
                });
                const data = await res.json();
                
                if (data.success) {
                    showFeedback('User created successfully!', 'success');
                    usernameInput.value = '';
                    passwordInput.value = '';
                    loadUsers();
                } else {
                    showFeedback(data.error || 'Failed to create user', 'error');
                }
            } catch (err) {
                showFeedback('Connection error', 'error');
            }
        }

        async function deleteUser(id, username) {
            if (!confirm(`Are you sure you want to delete user "${username}"?`)) return;
            
            try {
                const res = await fetch('api.php?action=delete_user', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                
                if (data.success) {
                    loadUsers();
                } else {
                    alert(data.error || 'Failed to delete user');
                }
            } catch (err) {
                alert('Connection error');
            }
        }

        function showFeedback(text, type) {
            const feedback = document.getElementById('user-feedback');
            feedback.style.display = 'block';
            feedback.textContent = text;
            if (type === 'success') {
                feedback.style.background = '#f0fdf4';
                feedback.style.color = '#166534';
                feedback.style.border = '1px solid #bbf7d0';
            } else {
                feedback.style.background = '#fef2f2';
                feedback.style.color = '#991b1b';
                feedback.style.border = '1px solid #fee2e2';
            }
            setTimeout(() => { feedback.style.display = 'none'; }, 3000);
        }

        async function pullUpdate(event) {
            const btn = event.currentTarget;
            const feedback = document.getElementById('update-feedback');
            const output = document.getElementById('update-output');
            
            btn.disabled = true;
            btn.style.opacity = '0.7';
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Pulling update...';
            
            try {
                const res = await fetch('api.php?action=pull_update');
                const data = await res.json();
                
                feedback.style.display = 'block';
                if (data.success) {
                    feedback.style.background = '#f0fdf4';
                    feedback.style.color = '#166534';
                    feedback.style.border = '1px solid #bbf7d0';
                    feedback.innerHTML = `<i class="fas fa-check-circle" style="margin-right: 8px;"></i> Successfully pulled latest changes!`;
                    
                    if (data.output) {
                        output.style.display = 'block';
                        output.textContent = data.output;
                    }
                    
                    // Reload after a short delay to apply changes
                    setTimeout(() => { window.location.reload(); }, 3000);
                } else {
                    feedback.style.background = '#fef2f2';
                    feedback.style.color = '#991b1b';
                    feedback.style.border = '1px solid #fee2e2';
                    feedback.innerHTML = `<i class="fas fa-times-circle" style="margin-right: 8px;"></i> Error: ${data.error || 'Unknown error'}`;
                    
                    if (data.output) {
                        output.style.display = 'block';
                        output.textContent = data.output;
                    }
                }
            } catch (err) {
                feedback.style.display = 'block';
                feedback.style.background = '#fef2f2';
                feedback.style.color = '#991b1b';
                feedback.style.border = '1px solid #fee2e2';
                feedback.innerHTML = `<i class="fas fa-times-circle" style="margin-right: 8px;"></i> Connection Error: ${err.message}`;
            } finally {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.innerHTML = '<i class="fab fa-github"></i> Pull Latest from GitHub';
            }
        }

        loadUsers();

    </script>
</body>
</html>
