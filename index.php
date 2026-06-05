<?php
require_once 'session_config.php';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="manifest" href="manifest.json" crossorigin="use-credentials">
    <meta name="theme-color" content="#2c3e50">
    <title>Plan&Go</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { 
            --primary: #1e293b; 
            --accent: #3b82f6; 
            --accent-hover: #2563eb;
            --bg-sidebar: #ffffff;
            --bg-app: #f8fafc;
            --glass: rgba(255, 255, 255, 0.9);
            --border: rgba(0, 0, 0, 0.08);
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; }
        body { display: flex; height: 100vh; font-family: 'Outfit', 'Inter', -apple-system, sans-serif; background: var(--bg-app); color: #0f172a; }
        
        /* Responsive Sidebar */
        #sidebar { 
            width: 432px; 
            background: var(--bg-sidebar); 
            box-shadow: 4px 0 24px rgba(0,0,0,0.03); 
            display: flex; 
            flex-direction: column; 
            z-index: 1000; 
            overflow-y: auto; 
            overflow-x: hidden;
            border-right: 1px solid var(--border);
            position: relative;
        }
        #map { flex-grow: 1; }
        
        .sidebar-top { 
            position: relative;
            background: var(--primary); 
            height: 180px; 
            color: white; 
            padding: 24px 20px; 
            display: flex; 
            flex-direction: column; 
            justify-content: flex-end; 
            align-items: flex-start;
            gap: 4px; 
            flex-shrink: 0;
            overflow: hidden;
            background-size: cover;
            background-position: center;
            transition: background 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .sidebar-top::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(to bottom, rgba(0,0,0,0.1), rgba(0,0,0,0.7));
            z-index: 1;
        }
        .sidebar-top > * { position: relative; z-index: 2; }
        .sidebar-top h3 { margin: 0; font-size: 1.4em; font-weight: 700; flex: none; letter-spacing: -0.5px; text-shadow: 0 2px 4px rgba(0,0,0,0.3); }
        .sidebar-top .icon-btn { color: white; font-size: 16px; vertical-align: middle; }
        
        .trip-controls { 
            display: none; 
            position: absolute;
            top: 70px;
            right: 10px;
            width: 280px;
            background: white; 
            border-radius: 16px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            padding: 20px; 
            flex-shrink: 0; 
            z-index: 1100;
            color: #333;
        }
        .trip-controls.show { display: block; animation: slideDown 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .trip-controls > * { margin-bottom: 15px; }
        .trip-controls > *:last-child { margin-bottom: 0; }
        .trip-controls label { display: block; font-size: 0.8em; font-weight: 600; color: #64748b; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }

        .p-3 { padding: 15px; }
        .controls { background: #f8f9fa; border-bottom: 1px solid #ddd; padding: 15px; flex-shrink: 0; }
        input, select, button { width: 100%; padding: 10px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { background: var(--accent); color: white; border: none; cursor: pointer; font-weight: bold; transition: 0.2s; }
        button:hover { background: #2980b9; }

        #waypoint-list { flex-grow: 1; padding: 10px; }
        .wp-card { 
            background: #fff; 
            border: 1px solid var(--border); 
            padding: 8px; 
            margin-bottom: 6px; 
            border-radius: 10px; 
            font-size: 0.85em; 
            cursor: pointer; 
            transition: all 0.12s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: var(--shadow-sm);
            position: relative;
            line-height: 1.15;
        }
        .wp-card:hover { 
            border-color: var(--accent); 
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
            background: #fdfdfd;
            z-index: 5;
        }
        .wp-card.dragging { opacity: 0.3; background: #eff6ff; z-index: 100; border: 1px dashed var(--accent); }
        .wp-card.highlight { 
            background: #f0f7ff; 
            border-color: var(--accent); 
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2), var(--shadow-md);
            z-index: 5;
        }

        /* Drag Indicators */
        .wp-card.drag-over-top { position: relative; }
        .wp-card.drag-over-top::before {
            content: '';
            position: absolute;
            top: -8px;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--accent);
            border-radius: 4px;
            z-index: 30;
            box-shadow: 0 0 8px rgba(59, 130, 246, 0.5);
        }
        .wp-card.drag-over-bottom { position: relative; }
        .wp-card.drag-over-bottom::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--accent);
            border-radius: 4px;
            z-index: 30;
            box-shadow: 0 0 8px rgba(59, 130, 246, 0.5);
        }
        
        .day-header { 
            background: rgba(30, 41, 59, 0.9); 
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            color: white; 
            padding: 8px 12px; 
            margin: 0; 
            border-radius: 0; 
            font-weight: 700; 
            font-size: 0.88em; 
            position: sticky; 
            top: 0;
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            gap: 8px;
            z-index: 150;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            transition: all 0.18s ease;
        }
        .day-header.poi-header { background: rgba(155, 89, 182, 0.9); }
        .day-header .icon-btn { color: rgba(255,255,255,0.7); margin-left: 5px; vertical-align: middle; }
        .day-header .icon-btn:hover { color: white; }
        .day-header input[type="date"] { background: white; border: 1px solid #ccc; border-radius: 3px; padding: 2px 5px; font-size: 0.85em; color: #333; margin-left: 5px; }
        .day-header-info { flex: 1; }
        .day-temp { font-size: 0.85em; font-weight: normal; margin-left: auto; margin-right: 5px; }
        .collapse-btn { cursor: pointer; padding: 5px; opacity: 0.8; transition: transform 0.3s; }
        .collapse-btn:hover { opacity: 1; }
        .collapsed .collapse-btn { transform: rotate(-90deg); }
        .day-content { 
            overflow: hidden; 
            transition: max-height 0.25s ease-out; 
            padding: 8px 6px; 
            background: var(--bg-app);
        }
        .day-content.collapsed { max-height: 0 !important; padding: 0 10px; }
        .day-drop-zone { transition: background 0.2s; }
        .day-drop-zone.compact { min-height: 6px; margin: 2px 0; border-radius: 4px; }
        .day-drop-zone.empty-large { min-height: 32px; margin: 4px 0; padding: 6px; border-radius: 8px; background: rgba(250,250,250,0.55); }
        .day-block { border-radius: 10px; overflow: hidden; margin-bottom: 8px; }
        .day-block.drag-over { box-shadow: 0 0 0 2px rgba(59,130,246,0.18); }
        .day-block.drag-over .day-header { background: rgba(30, 41, 59, 1); }
        .day-block.drag-over .day-drop-zone { background: rgba(59,130,246,0.16); }
        .day-header.drag-over { box-shadow: inset 0 -3px 0 rgba(59,130,246,0.18); }
        .day-content.drag-over { outline: 2px dashed rgba(59,130,246,0.12); border-radius: 6px; }

        .insert-day-zone {
            position: relative;
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 4px 0;
            cursor: pointer;
        }
        .insert-day-zone:hover .insert-day-line {
            background: #cbd5e1;
        }
        .insert-day-line {
            position: absolute;
            left: 0;
            right: 0;
            height: 1px;
            background: #d1d5db;
            opacity: 0.85;
        }
        .insert-day-btn {
            position: relative;
            z-index: 1;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 1px solid #d1d5db;
            background: #f8fafc;
            color: #6b7280;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: none;
            font-size: 0.85rem;
            transition: background 0.2s, border-color 0.2s, color 0.2s;
        }
        .insert-day-btn:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: #374151;
        }
        
        .category-buttons { display: flex; gap: 10px; margin-bottom: 10px; }
        .category-btn { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 5px; padding: 10px; border: 2px solid #ddd; border-radius: 8px; cursor: pointer; transition: 0.2s; background: white; opacity: 0.6; }
        .category-btn:hover { opacity: 0.8; }
        .category-btn.active { opacity: 1; border-color: var(--accent); background: #f0f7ff; }
        .category-btn i { font-size: 24px; }
        .category-btn span { font-size: 0.8em; text-align: center; color: #333; }
        
        .badge { font-size: 1em; padding: 0; border: none; color: inherit; background: none; }
        .hotel { color: #f1c40f; }
        .sight { color: #2ecc71; }
        .hike { color: #ea580c; }
        .poi { color: #9b59b6; }
        
        .button-group { display: flex; gap: 5px; }
        .button-group button { flex: 1; padding: 8px; font-size: 0.85em; }
        .btn-danger { background: #e74c3c; }
        .btn-danger:hover { background: #c0392b; }
        
        .day-stats { font-size: 0.75em; color: #999; padding: 2px 4px; margin-top: 2px; }
        
        .wp-actions { display: flex; gap: 5px; }
        .icon-btn { background: none; border: none; color: #999; cursor: pointer; font-size: 14px; padding: 0; width: 20px; height: 20px; transition: color 0.1s; }
        .icon-btn:hover { color: #333; }
        
        .add-day-container { padding: 30px 20px; display: flex; justify-content: center; }
        .btn-add-day { font-family: 'Outfit', sans-serif; }
        .btn-add-day.small {
            width: 36px; height: 36px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
            background: transparent; border: 1px solid transparent; color: #9ca3af; cursor: pointer; box-shadow: none; font-size: 18px;
        }
        .btn-add-day.small:hover { color: #6b7280; background: rgba(0,0,0,0.03); }
        .btn-add-day.large {
            display: inline-flex; align-items: center; gap: 10px; background: white; color: var(--primary); border: 2px dashed var(--border);
            padding: 10px 18px; border-radius: 12px; cursor: pointer; font-weight: 600; transition: all 0.12s; box-shadow: var(--shadow-sm);
        }
        .btn-add-day.large:hover { border-color: var(--accent); background: rgba(59,130,246,0.05); color: var(--accent); transform: translateY(-1px); box-shadow: var(--shadow-md); }
        
        #autocomplete-list { position: absolute; background: white; border: 1px solid #ccc; border-top: none; max-height: 200px; overflow-y: auto; width: calc(100% - 20px); margin: 0 10px; z-index: 100; display: none; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .autocomplete-item { padding: 8px 10px; cursor: pointer; border-bottom: 1px solid #eee; font-size: 0.85em; transition: 0.2s; }
        .autocomplete-item:hover { background: #f0f0f0; }
        
        .leaflet-routing-container { display: none !important; }
        
        /* Custom Map Style Switcher */
        .map-style-control { background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.15); display: flex; flex-direction: column; overflow: hidden; border: 2px solid rgba(0,0,0,0.2); }
        .map-style-btn { background: white; border: none; padding: 8px; cursor: pointer; transition: 0.2s; color: #666; font-size: 15px; display: flex; align-items: center; justify-content: center; }
        .map-style-btn:hover { background: #f8f9fa; color: var(--accent); }
        .map-style-btn.active { background: var(--accent); color: white; }
        .map-style-btn:not(:last-child) { border-bottom: 1px solid #eee; }
        
        .day-drop-zone { transition: background 0.2s; }
        .day-drop-zone.compact.drag-over { background: rgba(52, 152, 219, 0.08); }
        .day-drop-zone.empty-large.drag-over { background: rgba(52, 152, 219, 0.14); }

        .day-header.drag-over { box-shadow: inset 0 -3px 0 rgba(59,130,246,0.12); }
        .day-content.drag-over { outline: 2px dashed rgba(59,130,246,0.08); border-radius: 6px; }
        
        .sidebar-footer { background: #eee; padding: 15px; flex-shrink: 0; border-top: 1px solid #ddd; }
        
        #gpx-upload-container {
            display: none;
            background: #fff7ed;
            border: 2px dashed #ea580c;
            border-radius: 8px;
            padding: 0;
            margin-bottom: 15px;
            font-size: 0.85em;
            color: #9a3412;
            text-align: center;
            overflow: hidden;
            transition: all 0.2s;
        }
        #gpx-upload-container:hover {
            border-color: #c2410c;
            background: #fffaf5;
        }
        #gpx-upload-container label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px 10px;
            cursor: pointer;
            color: #ea580c;
            font-weight: 600;
            width: 100%;
        }
        #gpx-upload-container input { display: none; }
        #gpx-status { margin-top: 8px; font-size: 0.9em; color: #c2410c; font-style: italic; font-weight: normal; }
        
        .resize-divider { width: 10px; background: #eee; cursor: col-resize; transition: background 0.2s; display: flex; align-items: center; justify-content: center; flex-shrink: 0; z-index: 1001; border-left: 1px solid #ddd; border-right: 1px solid #ddd; touch-action: none; }
        .resize-divider:hover { background: #e0e0e0; }
        .resize-divider-icon { background: #bbb; color: white; padding: 15px 1px; border-radius: 10px; font-size: 8px; transition: 0.2s; display: flex; align-items: center; justify-content: center; }
        .resize-divider:hover .resize-divider-icon { background: var(--accent); }
        
        .marker-highlight { z-index: 1000 !important; }
        .marker-highlight > div { transform: scale(1.4); border-color: #2c3e50 !important; transition: transform 0.1s cubic-bezier(0.175, 0.885, 0.32, 1.275); box-shadow: 0 4px 12px rgba(0,0,0,0.4) !important; }
        .numbered-marker > div { transition: transform 0.1s; }
        
        @keyframes markerPulse { 0%, 100% { transform: scale(1.4); } 50% { transform: scale(1.6); } }
        
        @media (max-width: 768px) {
            body { flex-direction: column; overflow: hidden; }
            #sidebar { width: 100% !important; height: 65vh; max-width: 100vw; order: 3; }
            #map { width: 100% !important; height: 35vh; max-width: 100vw; order: 1; flex-grow: 1; z-index: 1; }
            .resize-divider { 
                height: 24px; 
                width: 100%; 
                cursor: row-resize; 
                border: none; 
                background: var(--bg-sidebar); 
                order: 2; 
                z-index: 1001; 
                border-top-left-radius: 20px; 
                border-top-right-radius: 20px; 
                box-shadow: 0 -4px 15px rgba(0,0,0,0.1);
                margin-top: -15px; 
            }
            .resize-divider-icon { 
                background: #cbd5e1; 
                width: 48px; 
                height: 5px; 
                border-radius: 10px; 
                padding: 0; 
                color: transparent; 
                transform: none; 
            }
            .resize-divider-icon::before { content: ''; }
            .resize-divider:hover .resize-divider-icon { background: #94a3b8; }
        }
        .wp-comment { font-size: 0.85em; color: #555; margin-top: 5px; font-style: italic; border-left: 2px solid #ddd; padding-left: 8px; }
        .comment-input { width: 100%; margin-top: 5px; padding: 6px; font-size: 0.85em; border: 1px solid #3498db; border-radius: 3px; resize: vertical; display: none; }
        
        /* Fullscreen Map Toggle - Premium Style */
        #fullscreen-map-toggle {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 2000;
            background: var(--glass);
            backdrop-filter: blur(8px);
            color: var(--primary);
            width: 52px;
            height: 52px;
            border-radius: 16px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        #fullscreen-map-toggle:hover { background: white; color: var(--accent); transform: scale(1.1) translateY(-2px); }
        #fullscreen-map-toggle.active { background: var(--accent); color: white; border-color: var(--accent); }
        #fullscreen-map-toggle i { transition: transform 0.3s; }
        #fullscreen-map-toggle:active { transform: scale(0.95); }

        body.map-fullscreen #sidebar { display: none !important; }
        body.map-fullscreen .resize-divider { display: none !important; }
        body.map-fullscreen #map { width: 100vw !important; height: 100vh !important; }
        
        /* Custom Popup Styling */
        .leaflet-popup-content-wrapper {
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            padding: 5px;
        }
        .leaflet-popup-content {
            margin: 12px;
            line-height: 1.4;
        }
        .leaflet-popup-tip {
            box-shadow: var(--shadow-md);
        }
        
        @media (max-width: 768px) {
            #fullscreen-map-toggle { display: none !important; }
        }

        /* User Location Blue Dot */
        .user-location-marker {
            width: 14px;
            height: 14px;
            background-color: #3b82f6;
            border-radius: 50%;
            border: 2px solid white;
            box-shadow: 0 0 5px rgba(0,0,0,0.3);
            position: relative;
        }
        .user-location-marker::after {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            width: 14px;
            height: 14px;
            border: 2px solid #3b82f6;
            border-radius: 50%;
            animation: user-pulse 2s infinite;
            pointer-events: none;
        }
        @keyframes user-pulse {
            0% { transform: scale(1); opacity: 0.8; }
            100% { transform: scale(3); opacity: 0; }
        }

        /* Startup Overlay */
        #startup-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: var(--primary);
            z-index: 9999;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
            padding: 40px;
            text-align: center;
        }
        #startup-overlay h1 { font-size: 2.5em; margin-bottom: 10px; font-weight: 700; }
        #startup-overlay p { font-size: 1.1em; opacity: 0.8; margin-bottom: 30px; max-width: 400px; }
        .startup-card {
            background: white;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 400px;
            color: #333;
        }
        .startup-card input {
            font-size: 1.1em;
            padding: 15px;
            margin-bottom: 20px;
            border: 2px solid #eee;
            border-radius: 12px;
        }
        .startup-card input:focus { border-color: var(--accent); outline: none; }
        .startup-card button {
            font-size: 1.1em;
            padding: 15px;
            border-radius: 12px;
            background: var(--accent);
            color: white;
            font-weight: 700;
        }
    </style>
</head>
<body>

<div id="sidebar" ondragover="sidebarDragOver(event)">
    <div class="sidebar-top" id="sidebar-banner">
        <div style="display: flex; justify-content: space-between; align-items: center; width: 100%; position: absolute; top: 20px; left: 0; padding: 0 20px;">
            <img src="logo.png" alt="Logo" style="height: 32px; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.2);">
            <button class="icon-btn" id="toggle-trip-btn" onclick="toggleTripControls()" title="Trip Menu" style="font-size: 20px; color: white; background: rgba(0,0,0,0.2); width: 36px; height: 36px; border-radius: 50%;"><i class="fas fa-bars"></i></button>
        </div>
        <div style="width: 100%;">
            <h3 id="trip-display">Unsaved (!) Trip</h3>
            <div id="trip-meta" style="font-size: 0.8em; opacity: 0.9; font-weight: 500; line-height: 1.4; text-shadow: 0 1px 2px rgba(0,0,0,0.3);"></div>
        </div>
        <button class="icon-btn" onclick="event.stopPropagation(); fetchBanner(currentTrip || 'travel', true)" title="Refresh Image" style="position: absolute; bottom: 15px; right: 15px; background: rgba(0,0,0,0.3); color: white; width: 28px; height: 28px; border-radius: 50%; font-size: 12px; z-index: 10;"><i class="fas fa-sync-alt"></i></button>
    </div>
    
    <div class="trip-controls" id="trip-controls">
        <div style="margin-bottom: 15px;">
            <label>Current Trip</label>
            <button id="menu-rename-btn" onclick="renameTrip()" class="btn-primary" style="display: none; padding: 8px; font-size: 0.85em; margin-bottom: 8px; width: 100%;"><i class="fas fa-edit"></i> Rename Trip</button>
            <button id="menu-delete-btn" onclick="deleteTrip()" class="btn-danger" style="display: none; padding: 8px; font-size: 0.85em; margin-bottom: 0;"><i class="fas fa-trash-alt"></i> Delete Trip</button>
        </div>

        <div style="border-top: 1px solid var(--border); padding-top: 15px;">
            <label>Load Trip</label>
            <select id="trip-select" onchange="loadSelectedTrip()" style="width: 100%; margin-bottom: 0;">
                <option value="">-- Load Saved Trip --</option>
            </select>
        </div>

        <div style="border-top: 1px solid var(--border); padding-top: 15px;">
            <label>Create New Trip</label>
            <input type="text" id="trip-name-input" placeholder="Trip name..." style="width: 100%; box-sizing: border-box; padding: 10px; margin-bottom: 8px;">
            <button onclick="saveNewTrip()" style="background: #22c55e; width: 100%; padding: 10px; border: none; border-radius: 6px; color: white; cursor: pointer; font-weight: 600;">+ Create Trip</button>
        </div>

        <div style="border-top: 1px solid var(--border); padding-top: 15px;">
            <a href="labs.php" target="_blank" style="display: flex; align-items: center; gap: 10px; color: var(--accent); text-decoration: none; font-size: 0.9em; font-weight: 600; padding: 5px 0;">
                <i class="fas fa-flask"></i> Labs / DevTools
            </a>
            <a href="#" onclick="logout()" style="display: flex; align-items: center; gap: 10px; color: #ef4444; text-decoration: none; font-size: 0.9em; font-weight: 600; padding: 5px 0; margin-top: 5px;">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
    
    <div class="controls" id="add-waypoint-controls">
        <input type="text" id="search-input" placeholder="Enter location..." autocomplete="off">
        <div id="autocomplete-list"></div>
        <div class="category-buttons">
            <button class="category-btn active" onclick="selectCategory('sight')" data-category="sight"><i class="fas fa-camera" style="color: #2ecc71;"></i><span>Sight</span></button>
            <button class="category-btn" onclick="selectCategory('hotel')" data-category="hotel"><i class="fas fa-bed" style="color: #f1c40f;"></i><span>Hotel</span></button>
            <button class="category-btn" onclick="selectCategory('hike')" data-category="hike"><i class="fas fa-mountain" style="color: #ea580c;"></i><span>Hike</span></button>
            <button class="category-btn" onclick="selectCategory('poi')" data-category="poi"><i class="fas fa-star" style="color: #9b59b6;"></i><span>POI</span></button>
        </div>
        
        <div id="gpx-upload-container">
            <label for="gpx-input">
                <i class="fas fa-file-upload" style="font-size: 1.8em; margin-bottom: 5px;"></i>
                <span>Click to Upload GPX File</span>
                <div id="gpx-status">No file chosen</div>
            </label>
            <input type="file" id="gpx-input" accept=".gpx" onchange="document.getElementById('gpx-status').innerText = this.files[0].name">
        </div>

        <input type="date" id="date-input" style="margin-bottom: 10px;">
        <button onclick="searchLocation()">Add Waypoint</button>
    </div>

    <div id="waypoint-list">
        </div>

    <div class="sidebar-footer">
        <strong>Total:</strong> <span id="dist">0</span> km | <span id="time">0</span>
    </div>
</div>

<div class="resize-divider" id="resize-divider">
    <i class="fas fa-grip-vertical resize-divider-icon"></i>
</div>

<div id="map"></div>

<button id="fullscreen-map-toggle" onclick="toggleFullscreenMap()" title="Toggle Fullscreen Map">
    <i class="fas fa-expand"></i>
</button>

<div id="startup-overlay">
    <div style="margin-bottom: 40px;">
        <img src="logo.png" alt="Plan&Go" style="height: 60px; filter: drop-shadow(0 4px 12px rgba(0,0,0,0.2));">
    </div>
    <h1>Welcome to Plan&Go</h1>
    <p>Let's start by creating your first trip. Give it a name to begin your adventure!</p>
    
    <div class="startup-card">
        <label style="display: block; text-align: left; font-size: 0.85em; font-weight: 700; color: #64748b; margin-bottom: 8px; text-transform: uppercase;">Trip Name</label>
        <input type="text" id="first-trip-name" placeholder="e.g. Summer in Italy 🇮🇹">
        <button onclick="createFirstTrip()">Create Trip & Start Planning</button>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js"></script>

<script>
    // Global State
    let routeWaypoints = [];
    let markerLayers = {};
    let gpxLayers = [];
    let searchMarker = null;
    let userLocationMarker = null;
    let currentTrip = null;
    let savedTrips = [];
    let draggedIndex = null;
    let draggedFromDate = null;
    let isAddingWaypoint = false;
    let routingVisible = false;
    let autocompleteTimeout = null;
    let collapsedSections = new Set();
    const weatherApiUrl = 'weatherAPI/weather.php';
    
    let weatherCache = {};


    // Map Styles
    const streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    });

    const satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        attribution: 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EBP, and the GIS User Community'
    });

    const terrainLayer = L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
        attribution: 'Map data: &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, <a href="http://viewfinderpanoramas.org">SRTM</a> | Map style: &copy; <a href="https://opentopomap.org">OpenTopoMap</a> (<a href="https://creativecommons.org/licenses/by-sa/3.0/">CC-BY-SA</a>)'
    });

    const map = L.map('map', {
        center: [52.49, 4.67],
        zoom: 8,
        layers: [streetLayer]
    });

    const baseMaps = {
        "Streets": streetLayer,
        "Satellite": satelliteLayer,
        "Terrain": terrainLayer
    };

    // Custom Layer Control
    const StyleControl = L.Control.extend({
        options: { position: 'topright' },
        onAdd: function() {
            const container = L.DomUtil.create('div', 'map-style-control');
            
            const styles = [
                { id: 'Streets', icon: 'fa-map', layer: streetLayer, title: 'Street Map' },
                { id: 'Satellite', icon: 'fa-satellite', layer: satelliteLayer, title: 'Satellite View' },
                { id: 'Terrain', icon: 'fa-mountain-sun', layer: terrainLayer, title: 'Terrain Map' }
            ];

            styles.forEach(style => {
                const btn = L.DomUtil.create('button', 'map-style-btn', container);
                btn.innerHTML = `<i class="fas ${style.icon}"></i>`;
                btn.title = style.title;
                if (style.id === 'Streets') btn.classList.add('active');

                btn.onclick = () => {
                    // Remove all layers
                    Object.values(baseMaps).forEach(l => map.removeLayer(l));
                    // Add new layer
                    style.layer.addTo(map);
                    // Update active state
                    container.querySelectorAll('.map-style-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                };
            });

            return container;
        }
    });

    map.addControl(new StyleControl());

    // Map Click Listener to capture coordinates
    map.on('click', async function(e) {
        const lat = e.latlng.lat;
        const lng = e.latlng.lng;
        const coordsStr = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
        
        const searchInput = document.getElementById('search-input');
        searchInput.value = coordsStr;
        searchInput.dataset.fullname = coordsStr; // Fallback fullname
        searchInput.dataset.lat = lat;
        searchInput.dataset.lon = lng;

        // Scroll sidebar to top to show search bar
        document.getElementById('sidebar').scrollTo({ top: 0, behavior: 'smooth' });
        
        // Show temporary search marker at click location
        if (searchMarker) map.removeLayer(searchMarker);
        
        searchMarker = L.marker([lat, lng], {
            icon: L.divIcon({
                html: '<div style="background: #3498db; color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3);"><i class="fas fa-search"></i></div>',
                iconSize: [30, 30],
                className: 'search-marker'
            })
        }).addTo(map);

        // Optional: Perform reverse geocoding to show a better name
        try {
            const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`);
            const data = await res.json();
            if (data && data.display_name) {
                searchInput.value = data.display_name;
                searchInput.dataset.fullname = data.display_name;
            }
        } catch (err) {
            console.warn('Reverse geocoding failed, using coordinates instead.');
        }
    });


    async function fetchWeatherData(lat, lng, date) {
        const cacheKey = `${lat},${lng},${date}`;
        if (weatherCache[cacheKey]) {
            return weatherCache[cacheKey];
        }
        
        try {
            
            const response = await fetch(`${weatherApiUrl}?latitude=${lat}&longitude=${lng}&date=${date}`);
            
            if (response.ok) {
                const data = await response.json();
                weatherCache[cacheKey] = data;
                return data;
            }
        } catch (err) {
            console.error('Weather API error:', err);
        }
        return null;
    }

    const routingControl = L.Routing.control({
        waypoints: [],
        routeWhileDragging: true,
        show: false, // Hide the itineray container
        addWaypoints: false,
        createMarker: function() { return null; }, // Custom markers handled manually
        lineOptions: {
            styles: [{ color: '#3498db', opacity: 0.8, weight: 6 }]
        }
    });
    routingControl.addTo(map);
    routingVisible = false;

    // Create category-specific icons with numbers
    function createNumberedIcon(number, category) {
        const colors = {
            hotel: '#f1c40f',
            sight: '#2ecc71',
            hike: '#ea580c',
            poi: '#9b59b6'
        };
        const icons = {
            hotel: 'fa-bed',
            sight: 'fa-camera',
            hike: 'fa-mountain',
            poi: 'fa-star'
        };
        
        const color = colors[category] || '#3498db';
        const icon = icons[category] || 'fa-map-pin';
        
        return L.divIcon({
            html: `<div style="background: ${color}; color: white; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; border: 2px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3); font-size: 14px;">${number}</div>`,
            iconSize: [32, 32],
            className: 'numbered-marker'
        });
    }

    // Update Totals
    routingControl.on('routesfound', (e) => {
        const s = e.routes[0].summary;
        document.getElementById('dist').innerText = (s.totalDistance / 1000).toFixed(1);
        const minutes = Math.round(s.totalTime / 60);
        document.getElementById('time').innerText = formatTime(minutes);
    });

    let selectedCategory = 'sight';
    
    function selectCategory(category) {
        selectedCategory = category;
        document.querySelectorAll('.category-btn').forEach(btn => {
            btn.classList.remove('active');
            if (btn.dataset.category === category) {
                btn.classList.add('active');
            }
        });
        
        // Hide/show date input based on category
        const dateInput = document.getElementById('date-input');
        const gpxContainer = document.getElementById('gpx-upload-container');
        const searchInput = document.getElementById('search-input');

        if (category === 'poi') {
            dateInput.style.display = 'none';
        } else {
            dateInput.style.display = 'block';
        }

        if (category === 'hike') {
            gpxContainer.style.display = 'block';
            searchInput.style.display = 'none'; // Hide search input for hikes
        } else {
            gpxContainer.style.display = 'none';
            searchInput.style.display = 'block';
            searchInput.placeholder = "Enter location...";
        }
    }
    
    async function searchLocation() {
        if (isAddingWaypoint) return;
        
        const query = document.getElementById('search-input').value;
        const type = selectedCategory;
        const date = document.getElementById('date-input').value;

        if (type === 'hike') {
            const gpxInput = document.getElementById('gpx-input');
            if (gpxInput.files.length === 0) {
                alert('Please upload a GPX file for the hike');
                return;
            }
            handleGpxUpload(gpxInput.files[0], query, date);
            return;
        }

        if(!query) return;
        
        isAddingWaypoint = true;
        
        // Clear temporary search marker if it exists
        if (searchMarker) {
            map.removeLayer(searchMarker);
            searchMarker = null;
        }

        const btn = document.querySelector('button[onclick="searchLocation()"]');
        btn.disabled = true;
        btn.style.opacity = '0.6';
        document.getElementById('autocomplete-list').style.display = 'none';

        try {
            const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}`);
            const data = await res.json();
            
            if (data.length > 0) {
                addWaypointToApp(data[0].display_name, data[0].lat, data[0].lon, type, date);
            }
            else {
                const searchInput = document.getElementById('search-input');
                if (searchInput.dataset.lat && searchInput.dataset.lon) {
                    addWaypointToApp(searchInput.value, searchInput.dataset.lat, searchInput.dataset.lon, type, date);
                } else {
                    alert('Locatie niet gevonden');
                }
            }
        } finally {
            isAddingWaypoint = false;
            btn.disabled = false;
            btn.style.opacity = '1';
        }
    }
    
    // Autocomplete for location search
    document.getElementById('search-input').addEventListener('input', function(e) {
        if (selectedCategory === 'hike') return;
        clearTimeout(autocompleteTimeout);
        const query = e.target.value;
        const list = document.getElementById('autocomplete-list');
        
        delete e.target.dataset.lat;
        delete e.target.dataset.lon;

        if (query.length < 2) {
            list.style.display = 'none';
            return;
        }
        
        autocompleteTimeout = setTimeout(async () => {
            try {
                // Get browser language
                const lang = navigator.language || navigator.userLanguage || 'en';
                const langCode = lang.split('-')[0]; // Get just the language code (e.g., 'de' from 'de-CH')
                
                const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=5&accept-language=${langCode}`);
                const data = await res.json();
                
                list.innerHTML = '';
                if (data.length > 0) {
                    data.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'autocomplete-item';
                        // Show full display name
                        div.textContent = item.display_name;
                        div.dataset.fullname = item.display_name;
                        div.dataset.lat = item.lat;
                        div.dataset.lon = item.lon;
                        div.onclick = () => {
                            const searchInput = document.getElementById('search-input');
                            searchInput.value = item.display_name;
                            searchInput.dataset.fullname = item.display_name;
                            searchInput.dataset.lat = item.lat;
                            searchInput.dataset.lon = item.lon;
                            list.style.display = 'none';
                            
                            // Highlight on map
                            const lat = parseFloat(item.lat);
                            const lon = parseFloat(item.lon);
                            
                            if (searchMarker) map.removeLayer(searchMarker);
                            
                            searchMarker = L.marker([lat, lon], {
                                icon: L.divIcon({
                                    html: '<div style="background: #3498db; color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3);"><i class="fas fa-search"></i></div>',
                                    iconSize: [30, 30],
                                    className: 'search-marker'
                                })
                            }).addTo(map);
                            
                            map.setView([lat, lon], 14);
                        };
                        list.appendChild(div);
                    });
                    list.style.display = 'block';
                } else {
                    list.style.display = 'none';
                }
            } catch (err) {
                list.style.display = 'none';
            }
        }, 300);
    });
    
    // Hide autocomplete when clicking elsewhere
    document.addEventListener('click', function(e) {
        if (e.target.id !== 'search-input') {
            document.getElementById('autocomplete-list').style.display = 'none';
        }
    });

    function parseGpxTrack(xml) {
        const parser = new DOMParser();
        const gpx = parser.parseFromString(xml, "text/xml");
        const trkpts = gpx.querySelectorAll('trkpt');
        
        if (trkpts.length === 0) return null;

        return Array.from(trkpts).map(pt => [
            parseFloat(pt.getAttribute('lat')),
            parseFloat(pt.getAttribute('lon'))
        ]);
    }

    async function handleGpxUpload(file, name, date) {
        isAddingWaypoint = true;
        
        const formData = new FormData();
        formData.append('gpx', file);
        
        try {
            const uploadRes = await fetch('api.php?action=upload_gpx', {
                method: 'POST',
                body: formData
            });
            const uploadData = await uploadRes.json();
            
            if (!uploadData.success) {
                alert('Upload failed: ' + uploadData.error);
                isAddingWaypoint = false;
                return;
            }
            
            const gpxPath = uploadData.path;
            const reader = new FileReader();
            
            reader.onload = async (e) => {
                try {
                    const xml = e.target.result;
                    const track = parseGpxTrack(xml);
                    
                    if (!track) {
                        alert('Invalid GPX file: No trackpoints found');
                        return;
                    }

                    const startPoint = track[0];
                    const hikeName = file.name.replace('.gpx', '').replace(/[_-]/g, ' ');
                    
                    // Get approximate address for start point
                    let fullname = hikeName;
                    try {
                        const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${startPoint[0]}&lon=${startPoint[1]}`);
                        const data = await res.json();
                        if (data && data.display_name) fullname = data.display_name;
                    } catch (err) {}

                    const wp = { 
                        name: hikeName, 
                        fullname: fullname, 
                        lat: startPoint[0], 
                        lng: startPoint[1], 
                        type: 'hike', 
                        date: date || new Date().toISOString().split('T')[0], 
                        comment: '',
                        gpxTrack: track,
                        gpxPath: gpxPath
                    };

                    routeWaypoints.push(wp);
                    
                    // Clear inputs
                    document.getElementById('search-input').value = '';
                    document.getElementById('gpx-input').value = '';
                    document.getElementById('gpx-status').innerText = 'No file chosen';
                    
                    renderUI();
                    updateMap();
                    autoSaveTrip();
                    
                    const newIndex = routeWaypoints.length - 1;
                    setTimeout(() => {
                        highlightWaypoint(newIndex);
                        const card = document.querySelector(`.wp-card[data-index="${newIndex}"]`);
                        if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 100);

                    // Zoom to the hike track
                    const bounds = L.polyline(track).getBounds();
                    map.fitBounds(bounds, { padding: [50, 50] });

                } catch (err) {
                    console.error('GPX parsing failed:', err);
                    alert('Error parsing GPX file');
                } finally {
                    isAddingWaypoint = false;
                }
            };
            
            reader.readAsText(file);
        } catch (err) {
            console.error('Upload failed:', err);
            alert('Upload failed');
            isAddingWaypoint = false;
        }
    }

    function addWaypointToApp(name, lat, lng, type, date = '') {
        const fullname = document.getElementById('search-input').dataset.fullname || name;
        
        // Clear temporary search marker
        if (searchMarker) {
            map.removeLayer(searchMarker);
            searchMarker = null;
        }

        // Extract city name (first part before comma)
        const cityName = fullname.split(',')[0].trim();
        const wp = { name: cityName, fullname, lat: parseFloat(lat), lng: parseFloat(lng), type, date: date || new Date().toISOString().split('T')[0], comment: '' };
        routeWaypoints.push(wp);
        const searchInput = document.getElementById('search-input');
        searchInput.value = '';
        delete searchInput.dataset.fullname;
        delete searchInput.dataset.lat;
        delete searchInput.dataset.lon;
        renderUI();
        updateMap();
        autoSaveTrip();
    }

    async function renderUI() {
        const container = document.getElementById('waypoint-list');
        let finalHtml = '';
        
        // Handle Ghost Day Logic (deactivated as empty days are real now)
        pendingNewDay = null;
        
        // Separate POIs from regular waypoints
        const poiWaypoints = [];
        const regularWaypoints = [];
        routeWaypoints.forEach((wp, i) => {
            if (wp.type === 'poi') {
                poiWaypoints.push({ wp, index: i });
            } else {
                regularWaypoints.push({ wp, index: i });
            }
        });
        
        // Render POI section at the top
        {
            const isCollapsed = collapsedSections.has('poi');
            const isPoiEmpty = poiWaypoints.length === 0;
            finalHtml += `
                <div class="day-block" data-date="poi" ondragover="dayDragOver(event, 'poi')" ondrop="dayDrop(event, 'poi')" ondragleave="dayDragLeave(event)">
                    <div class="day-header poi-header ${isCollapsed ? 'collapsed' : ''}" data-date="poi" onclick="zoomToPOIs()" style="cursor: pointer;">
                        <div class="day-header-info">Points of Interest</div>
                        <div class="collapse-btn" onclick="event.stopPropagation(); toggleSection('poi')"><i class="fas fa-chevron-down"></i></div>
                    </div>
                    <div class="day-drop-zone ${isPoiEmpty ? 'empty-large' : 'compact'}" data-date="poi"></div>
                    <div class="day-content ${isCollapsed ? 'collapsed' : ''}" id="content-poi" data-date="poi">`;
            
            poiWaypoints.forEach(({ wp, index }) => {
                const iconMap = {
                    hotel: '<i class="fas fa-bed" style="color: #f1c40f;"></i>',
                    sight: '<i class="fas fa-camera" style="color: #2ecc71;"></i>',
                    hike: '<i class="fas fa-mountain" style="color: #ea580c;"></i>',
                    poi: '<i class="fas fa-star" style="color: #9b59b6;"></i>'
                };
                const icon = iconMap[wp.type] || '<i class="fas fa-map-pin"></i>';
                finalHtml += `
                    <div class="wp-card" draggable="true" data-index="${index}" data-date="poi" 
                        onmouseenter="highlightWaypoint(${index})" onmouseleave="unhighlightWaypoint(${index})"
                        onclick="if(!event.target.closest('button, input, textarea')) zoomToWaypoint(${index})"
                        oncontextmenu="event.preventDefault(); showMoveCalendar(event, ${index})"
                        ondragstart="wpDragStart(${index}, 'poi')" ondragover="wpDragOver(event)" 
                        ondrop="wpDrop(event, ${index}, 'poi')" ondragend="dragEnd()" ondragleave="wpDragLeave(event)"
                        ontouchstart="wpTouchStart(event, ${index}, 'poi')" ontouchend="wpTouchEnd(event)">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div style="flex: 1;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                                    <span style="font-size: 0.85em; color: #888;">#${index+1}</span> 
                                    <strong id="wp-name-${index}">${wp.name}</strong>
                                    <input type="text" id="wp-input-${index}" value="${wp.name}" style="display: none; flex: 1; padding: 4px; border: 1px solid #3498db; border-radius: 3px;">
                                </div>
                                <small style="color: #666;">${wp.fullname}</small>
                                ${wp.comment ? `<div class="wp-comment" id="wp-comment-display-${index}">${formatComment(wp.comment)}</div>` : `<div class="wp-comment" id="wp-comment-display-${index}" style="display:none;"></div>`}
                                <textarea id="wp-comment-input-${index}" class="comment-input" placeholder="Add a comment...">${wp.comment || ''}</textarea>
                            </div>
                            <div class="wp-actions">
                                <div style="margin-right: 5px;">${icon}</div>
                                <button class="icon-btn" onclick="event.stopPropagation(); openGoogleMaps(${wp.lat}, ${wp.lng})" title="Open in Google Maps"><i class="fas fa-map"></i></button>
                                <button class="icon-btn" onclick="event.stopPropagation(); duplicateWaypoint(${index})" title="Duplicate"><i class="fas fa-copy"></i></button>
                                <button class="icon-btn" onclick="event.stopPropagation(); openEditWaypoint(${index})" title="Edit"><i class="fas fa-pencil-alt"></i></button>
                                <button class="icon-btn" onclick="event.stopPropagation(); deleteWaypoint(${index})" title="Delete"><i class="fas fa-trash-alt"></i></button>
                            </div>
                        </div>
                    </div>`;
            });
            finalHtml += `</div></div>`;
        }
        
        // Group regular waypoints by date
        const grouped = {};
        regularWaypoints.forEach(({ wp, index }) => {
            if (!grouped[wp.date]) grouped[wp.date] = [];
            grouped[wp.date].push({ wp, index });
        });
        
        // Render grouped waypoints with per-day stats
        const dates = Object.keys(grouped).sort();
 
        // Reset totals and render grouped waypoints (keep current values until updated)
        let totalDistance = 0;
        let totalDuration = 0;
        let daysPending = dates.length;
 
        const updateTotals = (d, t) => {
            totalDistance += d;
            totalDuration += t;
            daysPending--;
            if (daysPending <= 0) {
                document.getElementById('dist').innerText = totalDistance.toFixed(1);
                document.getElementById('time').innerText = formatTime(Math.round(totalDuration));
            }
        };
        
        const asyncUpdateTasks = [];
 
        for (let dayNum = 0; dayNum < dates.length; dayNum++) {
            const date = dates[dayNum];
            const isCollapsed = collapsedSections.has(date);
            const dayWaypoints = grouped[date].map(x => x.index).sort((a, b) => a - b);
            
            // Render insert-day zone before day (starting from dayNum 1)
            if (dayNum > 0) {
                finalHtml += `
                    <div class="insert-day-zone" onclick="event.stopPropagation(); insertDayBetween('${dates[dayNum - 1]}', '${date}')">
                        <div class="insert-day-line"></div>
                        <button class="insert-day-btn" title="Add a day"><i class="fas fa-plus"></i></button>
                    </div>
                `;
            }

            // Render basic structure immediately
            finalHtml += `
                <div class="day-block" data-date="${date}" ondragover="dayDragOver(event, '${date}')" ondrop="dayDrop(event, '${date}')" ondragleave="dayDragLeave(event)">
                    <div class="day-header ${isCollapsed ? 'collapsed' : ''}" id="header-${dayNum}" data-date="${date}" onclick="zoomToDay('${date}')" style="cursor: pointer;">
                        <div class="day-header-info">
                        <div id="day-title-${dayNum}">
                            Day ${dayNum + 1}: ${new Date(date).toLocaleDateString()}
                            <button class="icon-btn" onclick="event.stopPropagation(); openEditDayDate(${dayNum}, '${date}')" title="Edit Date"><i class="fas fa-calendar-alt"></i></button>
                        </div>
                        <div id="day-edit-${dayNum}" style="display: none;" onclick="event.stopPropagation();">
                            Day ${dayNum + 1}: <input type="date" id="day-date-input-${dayNum}" value="${date}" onkeydown="if(event.key==='Enter') saveDayDate(${dayNum}, '${date}')" onblur="saveDayDate(${dayNum}, '${date}')">
                        </div>
                        <div class="day-stats" id="stats-display-${dayNum}"><i class="fas fa-spinner fa-spin"></i> Loading stats</div>
                    </div>
                    <div class="day-temp" id="temp-display-${dayNum}"><span style="opacity: 0.5;"></span></div>
                    <div class="delete-day-btn" onclick="event.stopPropagation(); deleteDay('${date}')" title="Remove day" style="color: #ef4444; opacity: 0.6; padding: 4px 8px; margin-right: 5px;"><i class="fas fa-trash-alt"></i></div>
                    <div class="collapse-btn" onclick="event.stopPropagation(); toggleSection('${date}', ${dayNum})"><i class="fas fa-chevron-down"></i></div>
                </div>
                `;

            const isEmptySlot = grouped[date].every(item => item.wp.type === 'empty_day');

            finalHtml += `
                <div class="day-drop-zone ${isEmptySlot ? 'empty-large' : 'compact'}" data-date="${date}"></div>
                <div class="day-content ${isCollapsed ? 'collapsed' : ''}" id="content-${date}" data-date="${date}" ondragover="dayDragOver(event, '${date}')" ondrop="dayDrop(event, '${date}')" ondragleave="dayDragLeave(event)">`;
            
            // Save task for later execution
            asyncUpdateTasks.push({ dayNum, dayWaypoints, date });
 
            grouped[date].forEach(({ wp, index }) => {
                if (wp.type === 'empty_day') return; // Skip rendering card for empty day

                const iconMap = {
                    hotel: '<i class="fas fa-bed" style="color: #f1c40f;"></i>',
                    sight: '<i class="fas fa-camera" style="color: #2ecc71;"></i>',
                    hike: '<i class="fas fa-mountain" style="color: #ea580c;"></i>',
                    poi: '<i class="fas fa-star" style="color: #9b59b6;"></i>'
                };
                const icon = iconMap[wp.type] || '<i class="fas fa-map-pin"></i>';
                finalHtml += `
                    <div class="wp-card" draggable="true" data-index="${index}" data-date="${date}" 
                        onmouseenter="highlightWaypoint(${index})" onmouseleave="unhighlightWaypoint(${index})"
                        onclick="if(!event.target.closest('button, input, textarea')) zoomToWaypoint(${index})"
                        oncontextmenu="event.preventDefault(); showMoveCalendar(event, ${index})"
                        ondragstart="wpDragStart(${index}, '${date}')" ondragover="wpDragOver(event)" 
                        ondrop="wpDrop(event, ${index}, '${date}')" ondragend="dragEnd()" ondragleave="wpDragLeave(event)">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div style="flex: 1;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                                    <span style="font-size: 0.85em; color: #888;">#${index+1}</span> 
                                    <strong id="wp-name-${index}">${wp.name}</strong>
                                    <input type="text" id="wp-input-${index}" value="${wp.name}" style="display: none; flex: 1; padding: 4px; border: 1px solid #3498db; border-radius: 3px;">
                                </div>
                                <small style="color: #666;">${wp.fullname}</small>
                                ${wp.comment ? `<div class="wp-comment" id="wp-comment-display-${index}">${formatComment(wp.comment)}</div>` : `<div class="wp-comment" id="wp-comment-display-${index}" style="display:none;"></div>`}
                                <textarea id="wp-comment-input-${index}" class="comment-input" placeholder="Add a comment...">${wp.comment || ''}</textarea>
                            </div>
                            <div class="wp-actions">
                                <div style="margin-right: 5px;">${icon}</div>
                                <button class="icon-btn" onclick="event.stopPropagation(); openGoogleMaps(${wp.lat}, ${wp.lng})" title="Open in Google Maps"><i class="fas fa-map"></i></button>
                                <button class="icon-btn" onclick="event.stopPropagation(); duplicateWaypoint(${index})" title="Duplicate"><i class="fas fa-copy"></i></button>
                                <button class="icon-btn" onclick="event.stopPropagation(); openEditWaypoint(${index})" title="Edit"><i class="fas fa-pencil-alt"></i></button>
                                <button class="icon-btn" onclick="event.stopPropagation(); deleteWaypoint(${index})" title="Delete"><i class="fas fa-trash-alt"></i></button>
                            </div>
                        </div>
                    </div>`;
            });
            finalHtml += `</div></div>`;
        }
 
        // Add Button and Autofill button at bottom
        finalHtml += `
            <div class="add-day-container" style="display: flex; gap: 10px; width: 100%; box-sizing: border-box; padding: 20px; align-items: center;">
                <button class="btn-add-day small" title="Add day" onclick="event.stopPropagation(); addNewDay()" style="flex: none;">
                    <i class="fas fa-plus"></i>
                </button>
                <button class="btn-add-day large" onclick="event.stopPropagation(); autofillIntermediateDays()" style="flex: 1; justify-content: center;">
                    <i class="fas fa-calendar-days"></i> Fill
                </button>
            </div>
        `;
        
        container.innerHTML = finalHtml;
 
        // Run async tasks after setting innerHTML
        asyncUpdateTasks.forEach(({ dayNum, dayWaypoints, date }) => {
            calculateDayStats(dayNum, dayWaypoints).then(dayStats => {
                const statsElement = document.getElementById('stats-display-' + dayNum);
                if (statsElement) statsElement.innerHTML = dayStats.html;
                updateTotals(dayStats.distance, dayStats.duration);
            });
 
            const lastWaypointIdx = dayWaypoints[dayWaypoints.length - 1];
            const lastWaypoint = routeWaypoints[lastWaypointIdx];
            if (lastWaypoint && lastWaypoint.type !== 'empty_day') {
                fetchWeatherData(lastWaypoint.lat, lastWaypoint.lng, date).then(weatherData => {
                    if (weatherData) {
                        const tempElement = document.getElementById('temp-display-' + dayNum);
                        if (tempElement) {
                            const unit = weatherData.unit || '°C';
                            tempElement.innerHTML = `<span title="Type: ${weatherData.type}" style="cursor: help;">${weatherData.temperature}${unit}</span>`;
                        }
                    }
                });
            } else {
                const tempElement = document.getElementById('temp-display-' + dayNum);
                if (tempElement) tempElement.innerHTML = '';
            }
        });
        
        // Handle case with no days
        if (dates.length === 0) {
            document.getElementById('dist').innerText = '0.0';
            document.getElementById('time').innerText = '0 min';
        }
        
        updateDateInput();
    }
    
    function openEditDayDate(dayNum, oldDate) {
        document.getElementById(`day-title-${dayNum}`).style.display = 'none';
        document.getElementById(`day-edit-${dayNum}`).style.display = 'block';
        const input = document.getElementById(`day-date-input-${dayNum}`);
        input.focus();
    }

    function saveDayDate(dayNum, oldDate) {
        const input = document.getElementById(`day-date-input-${dayNum}`);
        const newDate = input.value;
        
        if (newDate && newDate !== oldDate) {
            // Update all waypoints with this date
            routeWaypoints.forEach(wp => {
                if (wp.date === oldDate) {
                    wp.date = newDate;
                }
            });
            renumberWaypoints();
            renderUI();
            updateMap();
            autoSaveTrip();
        } else {
            document.getElementById(`day-title-${dayNum}`).style.display = 'block';
            document.getElementById(`day-edit-${dayNum}`).style.display = 'none';
        }
    }

    function toggleSection(sectionId, dayNum = null) {
        if (collapsedSections.has(sectionId)) {
            collapsedSections.delete(sectionId);
        } else {
            collapsedSections.add(sectionId);
        }
        
        // Toggle DOM directly instead of full renderUI
        const contentId = `content-${sectionId}`;
        const contentEl = document.getElementById(contentId);
        
        // Find the header - POI has a specific class, others use index-based ID I just added
        let headerEl;
        if (sectionId === 'poi') {
            headerEl = document.querySelector('.poi-header');
        } else {
            headerEl = document.getElementById(`header-${dayNum}`);
        }
        
        if (contentEl) contentEl.classList.toggle('collapsed');
        if (headerEl) headerEl.classList.toggle('collapsed');
    }

    function formatTime(minutes) {
        if (minutes < 60) return Math.round(minutes) + ' min';
        if (minutes < 1440) return (minutes / 60).toFixed(1) + 'h';
        if (minutes < 10080) return (minutes / 1440).toFixed(1) + 'd';
        return (minutes / 10080).toFixed(1) + 'w';
    }

    function formatComment(text) {
        if (!text) return '';
        // Escape HTML to prevent XSS
        const escaped = text.replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/"/g, '&quot;')
                            .replace(/'/g, '&#039;');
                            
        const urlRegex = /(https?:\/\/[^\s]+)/g;
        return escaped.replace(urlRegex, (url) => {
            // Remove trailing punctuation from the clickable URL part
            const punctuationMatch = url.match(/[.,!?;:]+$/);
            const punctuation = punctuationMatch ? punctuationMatch[0] : '';
            const cleanUrl = url.substring(0, url.length - punctuation.length);
            
            let displayUrl = cleanUrl;
            try {
                const urlObj = new URL(cleanUrl);
                displayUrl = urlObj.hostname + (urlObj.pathname !== '/' ? (urlObj.pathname.length > 15 ? urlObj.pathname.substring(0, 15) + '...' : urlObj.pathname) : '');
            } catch (e) {
                if (displayUrl.length > 30) displayUrl = displayUrl.substring(0, 30) + '...';
            }
            return `<a href="${cleanUrl}" target="_blank" rel="noopener noreferrer" style="color: var(--accent); text-decoration: underline;" onclick="event.stopPropagation();">${displayUrl}</a>${punctuation}`;
        });
    }
    
    async function calculateRouteDistance(waypoints) {
        if (waypoints.length <= 1) return { distance: 0, duration: 0 };
        
        try {
            // Format coordinates for OSRM: lng,lat;lng,lat...
            const coords = waypoints.map(wp => `${wp.lng},${wp.lat}`).join(';');
            const response = await fetch(`https://router.project-osrm.org/route/v1/driving/${coords}?overview=false`);
            const data = await response.json();
            
            if (data.routes && data.routes.length > 0) {
                const route = data.routes[0];
                return {
                    distance: route.distance / 1000, // meters to km
                    duration: route.duration / 60 // seconds to minutes
                };
            }
        } catch (err) {
            console.warn('OSRM routing failed, falling back to straight-line distance');
        }
        
        // Fallback to straight-line distance if routing fails
        let distance = 0;
        for (let i = 0; i < waypoints.length - 1; i++) {
            const wp1 = L.latLng(waypoints[i].lat, waypoints[i].lng);
            const wp2 = L.latLng(waypoints[i + 1].lat, waypoints[i + 1].lng);
            distance += wp1.distanceTo(wp2);
        }
        const minutes = (distance / 1000) / 60 * 60; // km to hours to minutes
        return { distance: distance / 1000, duration: minutes };
    }
    
    async function calculateDayStats(dayNum, waypoints) {
        if (waypoints.length === 0) return { html: '', distance: 0, duration: 0 };
        
        // Build list of dates for proper grouping (excluding POIs and empty_days)
        const dateMap = {};
        routeWaypoints.forEach((wp, i) => {
            if (wp.type !== 'poi' && wp.type !== 'empty_day') {
                if (!dateMap[wp.date]) dateMap[wp.date] = [];
                dateMap[wp.date].push(i);
            }
        });
        const sortedDates = Object.keys(dateMap).sort();
        
        // Get waypoints for this day (excluding POIs and empty_day)
        let dayWaypoints = waypoints.map(idx => routeWaypoints[idx]).filter(wp => wp.type !== 'poi' && wp.type !== 'empty_day');
        
        if (dayWaypoints.length === 0) {
            return { html: 'Empty day ', distance: 0, duration: 0 };
        }
        
        // If not first day, add starting point from previous day's last waypoint
        if (dayNum > 0 && sortedDates[dayNum - 1]) {
            const prevDayIndices = dateMap[sortedDates[dayNum - 1]];
            const lastIdx = prevDayIndices[prevDayIndices.length - 1];
            const startWaypoint = routeWaypoints[lastIdx];
            dayWaypoints = [startWaypoint, ...dayWaypoints];
        }
        
        // Calculate using real routing
        const routeData = await calculateRouteDistance(dayWaypoints);
        const distance = routeData.distance;
        const travelTime = routeData.duration;
        
        const stops = dayWaypoints.length === 1 ? '1 stop' : `${dayWaypoints.length} stops`;
        const travelText = travelTime > 0 ? ` • Travel: ${formatTime(Math.round(travelTime))}` : '';
        const html = `${distance.toFixed(1)} km • ${stops}${travelText}`;
        
        return { html, distance, duration: travelTime };
    }
    
    function renumberWaypoints() {
        // Sort waypoints: POIs first, then by date
        const sorted = routeWaypoints
            .map((wp, idx) => ({ wp, oldIdx: idx }))
            .sort((a, b) => {
                // POIs always come first
                if (a.wp.type === 'poi' && b.wp.type !== 'poi') return -1;
                if (a.wp.type !== 'poi' && b.wp.type === 'poi') return 1;
                // Otherwise sort by date
                return new Date(a.wp.date) - new Date(b.wp.date);
            });
        
        const cleaned = [];
        const datesWithRealWps = new Set();
        sorted.forEach(item => {
            if (item.wp.type !== 'poi' && item.wp.type !== 'empty_day') {
                datesWithRealWps.add(item.wp.date);
            }
        });
        
        sorted.forEach(item => {
            if (item.wp.type === 'empty_day') {
                // Keep empty_day waypoint only if there are no real waypoints on that date
                if (!datesWithRealWps.has(item.wp.date)) {
                    cleaned.push(item.wp);
                    // Add to datesWithRealWps to avoid duplicates for the same date
                    datesWithRealWps.add(item.wp.date);
                }
            } else {
                cleaned.push(item.wp);
            }
        });
        
        routeWaypoints = cleaned;
    }
    
    function wpDragStart(index, date) {
        draggedIndex = index;
        draggedFromDate = date;
    }
    
    function dayDragOver(e, date) {
        e.preventDefault();
        const blocks = document.querySelectorAll(`.day-block[data-date="${date}"]`);
        blocks.forEach(block => block.classList.add('drag-over'));
        const zones = document.querySelectorAll(`.day-drop-zone[data-date="${date}"]`);
        zones.forEach(zone => zone.classList.add('drag-over'));
    }
    
    function dayDragLeave(e) {
        const date = e.currentTarget.getAttribute('data-date');
        if (!date) {
            e.currentTarget.classList.remove('drag-over');
            return;
        }
        const related = e.relatedTarget;
        if (related && related.closest(`[data-date="${date}"]`)) {
            return;
        }
        const blocks = document.querySelectorAll(`.day-block[data-date="${date}"]`);
        blocks.forEach(block => block.classList.remove('drag-over'));
        const zones = document.querySelectorAll(`.day-drop-zone[data-date="${date}"]`);
        zones.forEach(zone => zone.classList.remove('drag-over'));
    }
    
    function dayDrop(e, targetDate) {
        e.preventDefault();
        e.stopPropagation();
        e.currentTarget.classList.remove('drag-over');
        try {
            let dz = null;
            if (e.currentTarget.classList.contains('day-header') || e.currentTarget.classList.contains('day-content')) {
                dz = e.currentTarget.parentElement.querySelector('.day-drop-zone');
            }
            if (dz) dz.classList.remove('drag-over');
        } catch (err) {}
        
        if (draggedIndex !== null && draggedFromDate !== targetDate) {
            const originalDate = draggedFromDate;
            // Move waypoint to different day/section and update route
            if (targetDate === 'poi') {
                if (routeWaypoints[draggedIndex].type === 'hike') {
                    alert('Hikes cannot be moved to the POI section because they are tied to specific days.');
                    draggedIndex = null;
                    draggedFromDate = null;
                    return;
                }
                routeWaypoints[draggedIndex].type = 'poi';
            } else {
                // If moving from POI to a day, change type to sight
                if (draggedFromDate === 'poi') {
                    routeWaypoints[draggedIndex].type = 'sight';
                }
                routeWaypoints[draggedIndex].date = targetDate;
            }

            if (originalDate !== 'poi') {
                const remainingReal = routeWaypoints.filter((wp, idx) => idx !== draggedIndex && wp.date === originalDate && wp.type !== 'poi' && wp.type !== 'empty_day');
                const hasEmptyDay = routeWaypoints.some((wp, idx) => idx !== draggedIndex && wp.date === originalDate && wp.type === 'empty_day');
                if (remainingReal.length === 0 && !hasEmptyDay) {
                    routeWaypoints.push({
                        name: 'Empty day',
                        fullname: 'Empty day',
                        lat: 0,
                        lng: 0,
                        type: 'empty_day',
                        date: originalDate,
                        comment: ''
                    });
                }
            }

            renumberWaypoints();
            renderUI();
            updateMap();
            autoSaveTrip();
            
            // Clear drag state after drop
            draggedIndex = null;
            draggedFromDate = null;
        }
    }
    
    function sidebarDragOver(e) {
        if (draggedIndex === null) return;
        const sidebar = document.getElementById('sidebar');
        const rect = sidebar.getBoundingClientRect();
        const threshold = 80;
        const scrollSpeed = 20;

        if (e.clientY < rect.top + threshold) {
            sidebar.scrollTop -= scrollSpeed;
        } else if (e.clientY > rect.bottom - threshold) {
            sidebar.scrollTop += scrollSpeed;
        }
    }

    function wpDragOver(e) {
        e.preventDefault();
        const card = e.currentTarget;
        const rect = card.getBoundingClientRect();
        const midPoint = rect.top + rect.height / 2;
        
        if (e.clientY < midPoint) {
            card.classList.add('drag-over-top');
            card.classList.remove('drag-over-bottom');
        } else {
            card.classList.add('drag-over-bottom');
            card.classList.remove('drag-over-top');
        }
    }
    
    function wpDragLeave(e) {
        e.currentTarget.classList.remove('drag-over-top', 'drag-over-bottom');
    }
    
    function wpDrop(e, targetIndex, targetDate) {
        e.preventDefault();
        e.stopPropagation();
        const card = e.currentTarget;
        const isAfter = card.classList.contains('drag-over-bottom');
        card.classList.remove('drag-over-top', 'drag-over-bottom');
        
        if (draggedIndex === null || draggedIndex === targetIndex) {
            draggedIndex = null;
            draggedFromDate = null;
            return;
        }
        
        const draggedWp = routeWaypoints[draggedIndex];
        
        // Update type and date based on target
        if (targetDate === 'poi') {
            if (draggedWp.type === 'hike') {
                alert('Hikes cannot be moved to the POI section.');
                draggedIndex = null;
                draggedFromDate = null;
                return;
            }
            draggedWp.type = 'poi';
        } else {
            if (draggedFromDate === 'poi') {
                draggedWp.type = 'sight';
            }
            draggedWp.date = targetDate;
        }
        
        // Remove from old position
        routeWaypoints.splice(draggedIndex, 1);
        
        // Calculate new insertion index
        let insertIndex = targetIndex;
        // If we removed an item from before the target, the target's index effectively shifted
        if (draggedIndex < targetIndex) {
            insertIndex = isAfter ? targetIndex : targetIndex - 1;
        } else {
            insertIndex = isAfter ? targetIndex + 1 : targetIndex;
        }
        
        // Insert at new position
        routeWaypoints.splice(insertIndex, 0, draggedWp);
        
        renumberWaypoints();
        renderUI();
        updateMap();
        autoSaveTrip();
        
        // Clear drag state after drop
        draggedIndex = null;
        draggedFromDate = null;
    }
    
    function dragStart(index) {
        draggedIndex = index;
    }
    
    function dragOver(e) {
        e.preventDefault();
        e.currentTarget.classList.add('dragging');
    }
    
    function dragDrop(targetIndex) {
        if (draggedIndex !== null && draggedIndex !== targetIndex) {
            const temp = routeWaypoints[draggedIndex];
            routeWaypoints[draggedIndex] = routeWaypoints[targetIndex];
            routeWaypoints[targetIndex] = temp;
            renderUI();
            updateMap();
        }
    }
    
    function dragEnd() {
        document.querySelectorAll('.wp-card').forEach(card => card.classList.remove('dragging'));
        draggedIndex = null;
    }
    
    function deleteWaypoint(index) {
        routeWaypoints.splice(index, 1);
        renderUI();
        updateMap();
        autoSaveTrip();
    }
    
    function openEditWaypoint(index) {
        const nameDisplay = document.getElementById(`wp-name-${index}`);
        const input = document.getElementById(`wp-input-${index}`);
        const commentDisplay = document.getElementById(`wp-comment-display-${index}`);
        const commentInput = document.getElementById(`wp-comment-input-${index}`);
        
        if (input.style.display === 'none') {
            // Enter edit mode
            nameDisplay.style.display = 'none';
            input.style.display = 'block';
            
            if (commentDisplay) commentDisplay.style.display = 'none';
            commentInput.style.display = 'block';
            
            input.focus();
            input.select();
            
            // Handle Enter key for name input
            input.onkeydown = (e) => {
                if (e.key === 'Enter') {
                    saveWaypointEdit(index);
                } else if (e.key === 'Escape') {
                    cancelWaypointEdit(index);
                }
            };
            
            // Handle Escape for comment input
            commentInput.onkeydown = (e) => {
                if (e.key === 'Escape') {
                    cancelWaypointEdit(index);
                }
                // Don't save on Enter for textarea as it's multiline
            };

            // Create a temporary "Save" button or just use a shared blur logic
            // Since we have two fields, blur is tricky. Maybe add a "Save" button next to them?
            // Or just save when both lose focus?
            // Actually, let's just add a small "Done" button or rely on clicking outside.
            // For now, let's stick to the current logic but apply it to both.
            
            // Let's add a "Save" button when editing
            const actionsDiv = input.closest('.wp-card').querySelector('.wp-actions');
            const originalActions = actionsDiv.innerHTML;
            actionsDiv.innerHTML = `<button class="icon-btn" onclick="saveWaypointEdit(${index})" title="Save" style="color: #27ae60;"><i class="fas fa-check"></i></button>
                                   <button class="icon-btn" onclick="cancelWaypointEdit(${index})" title="Cancel" style="color: #e74c3c;"><i class="fas fa-times"></i></button>`;
            actionsDiv.dataset.original = originalActions;
        }
    }
    
    function saveWaypointEdit(index) {
        const input = document.getElementById(`wp-input-${index}`);
        const commentInput = document.getElementById(`wp-comment-input-${index}`);
        
        const newName = input.value.trim();
        const newComment = commentInput.value.trim();
        
        let changed = false;
        if (newName && newName !== routeWaypoints[index].name) {
            routeWaypoints[index].name = newName;
            changed = true;
        }
        
        if (newComment !== (routeWaypoints[index].comment || '')) {
            routeWaypoints[index].comment = newComment;
            changed = true;
        }
        
        if (changed) {
            renderUI();
            updateMap();
            autoSaveTrip();
        } else {
            cancelWaypointEdit(index);
        }
    }
    
    function cancelWaypointEdit(index) {
        const nameDisplay = document.getElementById(`wp-name-${index}`);
        const input = document.getElementById(`wp-input-${index}`);
        const commentDisplay = document.getElementById(`wp-comment-display-${index}`);
        const commentInput = document.getElementById(`wp-comment-input-${index}`);
        
        nameDisplay.style.display = 'inline';
        input.style.display = 'none';
        
        if (commentInput) {
            commentInput.style.display = 'none';
            if (commentDisplay && routeWaypoints[index].comment) {
                commentDisplay.style.display = 'block';
            }
        }
        
        // Restore buttons
        const card = input.closest('.wp-card');
        const actionsDiv = card.querySelector('.wp-actions');
        if (actionsDiv.dataset.original) {
            actionsDiv.innerHTML = actionsDiv.dataset.original;
            delete actionsDiv.dataset.original;
        }
    }
    
    function openGoogleMaps(lat, lng) {
        const url = `https://www.google.com/maps?q=${lat},${lng}`;
        window.open(url, '_blank');
    }

    function updateMap() {
        // Remove old markers and GPX tracks
        Object.values(markerLayers).forEach(marker => map.removeLayer(marker));
        markerLayers = {};
        gpxLayers.forEach(layer => map.removeLayer(layer));
        gpxLayers = [];

        // Add new markers with numbers based on array order
        routeWaypoints.forEach((wp, i) => {
            if (wp.type === 'empty_day') return; // Skip drawing markers for empty days

            const commentHtml = wp.comment ? `<div class="wp-comment" style="margin-top: 8px; border-left-color: var(--accent); max-height: 80px; overflow-y: auto;">${formatComment(wp.comment)}</div>` : '';
            
            // If it's a hike with GPX data, draw the track
            if (wp.type === 'hike' && wp.gpxTrack) {
                const polyline = L.polyline(wp.gpxTrack, {
                    color: '#ea580c',
                    weight: 5,
                    opacity: 0.8,
                    dashArray: '1, 10'
                }).addTo(map);
                gpxLayers.push(polyline);
                
                // Solid line underneath for better visibility
                const basePoly = L.polyline(wp.gpxTrack, {
                    color: '#ea580c',
                    weight: 8,
                    opacity: 0.3
                }).addTo(map);
                gpxLayers.push(basePoly);
            }

            const marker = L.marker(
                [wp.lat, wp.lng],
                { icon: createNumberedIcon(i + 1, wp.type) }
            ).addTo(map).bindPopup(`
                <div style="max-width: 160px;">
                    <strong style="font-size: 1.1em;">${i + 1}. ${wp.name}</strong><br>
                    <small style="color: #666; display: block; margin-top: 2px;">${wp.fullname || wp.name}</small>
                    <div style="margin-top: 5px;"><span class="badge ${wp.type}" style="font-size: 0.8em; opacity: 0.8;">${wp.type}</span></div>
                    ${commentHtml}
                </div>
            `, { maxWidth: 180, className: 'custom-popup' });
            
            // Clicking marker on map ONLY opens popup, does not zoom!
            marker.on('click', () => {
                marker.openPopup();
            });
            
            // Highlight sidebar when hovering map marker
            marker.on('mouseover', () => {
                const card = document.querySelector(`.wp-card[data-index="${i}"]`);
                if (card) {
                    card.classList.add('highlight');
                    // Only scroll if card is not clearly visible
                    const rect = card.getBoundingClientRect();
                    const parentRect = document.getElementById('sidebar').getBoundingClientRect();
                    if (rect.top < parentRect.top || rect.bottom > parentRect.bottom) {
                        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
                const iconElement = marker.getElement();
                if (iconElement) iconElement.classList.add('marker-highlight');
                marker.setZIndexOffset(1000);
            });
            marker.on('mouseout', () => {
                const card = document.querySelector(`.wp-card[data-index="${i}"]`);
                if (card) card.classList.remove('highlight');
                const iconElement = marker.getElement();
                if (iconElement) iconElement.classList.remove('marker-highlight');
                marker.setZIndexOffset(0);
            });
            
            markerLayers[i] = marker;
        });

        // Update route with waypoints in correct order (excluding POIs and empty days)
        const routePoints = routeWaypoints.filter(wp => wp.type !== 'poi' && wp.type !== 'empty_day');
        const pts = routePoints.map(wp => L.latLng(wp.lat, wp.lng));
        routingControl.setWaypoints(pts);
        
        // Fit map to show all waypoints (excluding empty days, including POIs)
        const validWaypoints = routeWaypoints.filter(wp => wp.type !== 'empty_day');
        if (validWaypoints.length > 0) {
            const allPts = validWaypoints.map(wp => L.latLng(wp.lat, wp.lng));
            const bounds = L.latLngBounds(allPts);
            map.fitBounds(bounds, { padding: [50, 50] });
        }
    }
    
    function zoomToPOIs() {
        // Get all POI waypoints
        const poiWaypoints = routeWaypoints.filter(wp => wp.type === 'poi');
        
        if (poiWaypoints.length > 0) {
            const poiBounds = L.latLngBounds(poiWaypoints.map(wp => L.latLng(wp.lat, wp.lng)));
            map.fitBounds(poiBounds, { padding: [50, 50] });
        }
    }
    
    function zoomToDay(date) {
        // Get all waypoints for the selected day
        const dayWaypoints = routeWaypoints.filter(wp => wp.date === date);
        
        if (dayWaypoints.length > 0) {
            const dayBounds = L.latLngBounds(dayWaypoints.map(wp => L.latLng(wp.lat, wp.lng)));
            map.fitBounds(dayBounds, { padding: [50, 50] });
        }
    }
    
    function zoomToWaypoint(index) {
        // Zoom to a specific waypoint and open its popup
        const wp = routeWaypoints[index];
        if (wp) {
            map.setView([wp.lat, wp.lng], 15);
            const marker = markerLayers[index];
            if (marker) {
                marker.openPopup();
            }
        }
    }

    function highlightWaypoint(index) {
        const marker = markerLayers[index];
        if (marker) {
            const iconElement = marker.getElement();
            if (iconElement) iconElement.classList.add('marker-highlight');
            marker.setZIndexOffset(1000);
        }
    }

    function unhighlightWaypoint(index) {
        const marker = markerLayers[index];
        if (marker) {
            const iconElement = marker.getElement();
            if (iconElement) iconElement.classList.remove('marker-highlight');
            marker.setZIndexOffset(0);
        }
    }

    async function autoSaveTrip() {
        if (!currentTrip) return;
        
        const tripData = {
            name: currentTrip,
            waypoints: routeWaypoints
        };
        
        await fetch('api.php?action=save', {
            method: 'POST',
            body: JSON.stringify(tripData)
        });
    }
    
    async function saveTrip() {
        const tripName = currentTrip || 'Unnamed Trip';
        if (!tripName) {
            alert('Please enter a trip name');
            return;
        }
        
        const tripData = {
            name: tripName,
            waypoints: routeWaypoints
        };
        
        await fetch('api.php?action=save', {
            method: 'POST',
            body: JSON.stringify(tripData)
        });
        currentTrip = tripName;
        document.getElementById('trip-display').textContent = tripName;
        document.getElementById('menu-rename-btn').style.display = tripName ? 'block' : 'none';
        document.getElementById('menu-delete-btn').style.display = tripName ? 'block' : 'none';
        await loadTrips();
    }
    
    let pendingNewDay = null;

    function addNewDay() {
        const dates = [...new Set(routeWaypoints.filter(wp => wp.type !== 'poi').map(wp => wp.date))].sort();
        let nextDate;
        
        if (dates.length > 0) {
            const last = new Date(dates[dates.length - 1]);
            last.setDate(last.getDate() + 1);
            nextDate = last.toISOString().split('T')[0];
        } else {
            nextDate = new Date().toISOString().split('T')[0];
        }
        
        routeWaypoints.push({
            name: 'Empty day',
            fullname: 'Empty day',
            lat: 0,
            lng: 0,
            type: 'empty_day',
            date: nextDate,
            comment: ''
        });
        
        renumberWaypoints();
        renderUI();
        updateMap();
        autoSaveTrip();
        
        // Scroll to the new day
        setTimeout(() => {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) sidebar.scrollTo({ top: sidebar.scrollHeight, behavior: 'smooth' });
        }, 100);
    }

    function duplicateWaypoint(index) {
        const original = routeWaypoints[index];
        const clone = JSON.parse(JSON.stringify(original));
        
        // Insert after current index
        routeWaypoints.splice(index + 1, 0, clone);
        
        renumberWaypoints();
        renderUI();
        updateMap();
        autoSaveTrip();
    }

    async function renameTrip() {
        if (!currentTrip) {
            alert('Please select a trip to rename');
            return;
        }
        
        const newName = prompt(`Rename trip from "${currentTrip}" to:`);
        if (!newName || newName.trim() === '') {
            return; // User cancelled or entered empty string
        }
        
        if (newName.trim() === currentTrip) {
            alert('The new name is the same as the current name.');
            return;
        }
        
        if (savedTrips.includes(newName.trim())) {
            alert('A trip with this name already exists!');
            return;
        }
        
        try {
            const res = await fetch('api.php?action=rename_trip', {
                method: 'POST',
                body: JSON.stringify({ old_name: currentTrip, new_name: newName.trim() })
            });
            const data = await res.json();
            
            if (data.success) {
                currentTrip = newName.trim();
                document.getElementById('trip-display').textContent = currentTrip;
                await loadTrips();
                alert(`Trip renamed to "${currentTrip}"!`);
            } else {
                alert('Error renaming trip: ' + (data.error || 'Unknown error'));
            }
        } catch (err) {
            console.error('Rename failed:', err);
            alert('Error renaming trip');
        }
    }
    
    async function deleteTrip() {
        if (!currentTrip) {
            alert('Please select a trip to delete');
            return;
        }
        if (!confirm(`Delete trip "${currentTrip}"?`)) return;
        
        await fetch('api.php?action=delete', {
            method: 'POST',
            body: JSON.stringify({ name: currentTrip })
        });
        alert('Trip deleted!');
        startup(); // Refresh and handle empty state
    }
    
    function createNewTrip() {
        // Instead of starting an unsaved "New Trip", force the user to provide a name
        document.getElementById('startup-overlay').style.display = 'flex';
        document.getElementById('first-trip-name').value = '';
        document.getElementById('add-waypoint-controls').style.display = 'none';
        document.getElementById('trip-controls').classList.remove('show');
    }
    
    function saveNewTrip() {
        const tripName = document.getElementById('trip-name-input').value.trim();
        if (!tripName) {
            alert('Please enter a trip name');
            return;
        }
        currentTrip = tripName;
        routeWaypoints = [];
        document.getElementById('trip-display').textContent = tripName;
        document.getElementById('menu-rename-btn').style.display = 'block';
        document.getElementById('menu-delete-btn').style.display = 'block';
        document.getElementById('trip-name-input').value = '';
        document.getElementById('trip-controls').classList.remove('show');
        fetchBanner(tripName);
        autoSaveTrip();
        loadTrips();
        renderUI();
        updateMap();
    }
    
    function toggleTripControls() {
        document.getElementById('trip-controls').classList.toggle('show');
    }
    
    // Close trip menu when clicking outside
    document.addEventListener('click', function(e) {
        const menu = document.getElementById('trip-controls');
        const btn = document.getElementById('toggle-trip-btn');
        if (menu.classList.contains('show') && !menu.contains(e.target) && !btn.contains(e.target)) {
            menu.classList.remove('show');
        }
    });
    
    async function loadTrips() {
        const res = await fetch('api.php?action=trips');
        const data = await res.json();
        savedTrips = data.trips.filter(trip => trip && trip.trim());
        const lastActive = data.last_active;
        
        const select = document.getElementById('trip-select');
        select.innerHTML = '';
        savedTrips.forEach(trip => {
            const option = document.createElement('option');
            option.value = trip;
            option.textContent = trip;
            if (trip === lastActive) option.selected = true;
            select.appendChild(option);
        });

        return lastActive;
    }
    
    async function loadSelectedTrip() {
        const tripName = document.getElementById('trip-select').value;
        if (!tripName) return;
        
        const res = await fetch(`api.php?action=load&trip=${encodeURIComponent(tripName)}`);
        const data = await res.json();
        routeWaypoints = data.waypoints;

        // Load GPX tracks for hikes
        for (let wp of routeWaypoints) {
            if (wp.type === 'hike' && wp.gpxPath) {
                try {
                    const gpxRes = await fetch(wp.gpxPath);
                    if (gpxRes.ok) {
                        const xml = await gpxRes.text();
                        wp.gpxTrack = parseGpxTrack(xml);
                    }
                } catch (err) {
                    console.error('Failed to load GPX for hike:', err);
                }
            }
        }

        currentTrip = tripName;
        document.getElementById('trip-display').textContent = tripName;
        document.getElementById('menu-rename-btn').style.display = 'block';
        document.getElementById('menu-delete-btn').style.display = 'block';
        fetchBanner(tripName, false, data.banner_url);
        renderUI();
        updateMap();

        // Save last active trip for user
        fetch('api.php?action=set_active_trip', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ trip: tripName })
        });
    }
    
    let lastBannerQuery = '';
    async function fetchBanner(query, force = false, storedUrl = null) {
        const banner = document.getElementById('sidebar-banner');
        
        // Use stored URL if available and not forcing a refresh
        if (!force && storedUrl) {
            banner.style.backgroundImage = `url(${storedUrl})`;
            lastBannerQuery = query;
            return;
        }

        // Clean query: remove ALL numbering (#1, 2, 3), 2024, etc.) and common words
        let cleanQuery = query.replace(/[#\d\.\)\-]+/g, ' '); 
        cleanQuery = cleanQuery.replace(/\b(trip|to|the|my|our|vacation|holiday|vakantie|planned)\b/gi, ' ');
        cleanQuery = cleanQuery.replace(/\s+/g, ' ').trim() || 'travel';
        
        if (!force && cleanQuery === lastBannerQuery) return;
        lastBannerQuery = cleanQuery;
        
        try {
            const res = await fetch(`api.php?action=get_banner&q=${encodeURIComponent(cleanQuery)}`);
            const data = await res.json();
            if (data && data.urls && data.urls.regular) {
                const imgUrl = data.urls.regular;
                banner.style.backgroundImage = `url(${imgUrl})`;
                
                // Save this URL to the trip permanently
                if (currentTrip && currentTrip !== 'New Trip') {
                    fetch('api.php?action=save_banner', {
                        method: 'POST',
                        body: JSON.stringify({ name: currentTrip, banner_url: imgUrl })
                    });
                }
            }
        } catch (e) {
            console.warn('Failed to fetch Unsplash banner', e);
        }
    }

    async function startup() {
        const lastActive = await loadTrips();
        
        if (savedTrips.length === 0) {
            // Force user to create a trip first
            document.getElementById('startup-overlay').style.display = 'flex';
            document.getElementById('add-waypoint-controls').style.display = 'none';
            return;
        }

        // Load the trip: either last active, or the most recent one
        let tripToLoad = lastActive;
        if (!tripToLoad && savedTrips.length > 0) {
            tripToLoad = savedTrips[0];
        }

        if (tripToLoad) {
            const select = document.getElementById('trip-select');
            select.value = tripToLoad;
            
            const res = await fetch(`api.php?action=load&trip=${encodeURIComponent(tripToLoad)}`);
            const data = await res.json();
            routeWaypoints = data.waypoints;

            // Load GPX tracks for hikes
            for (let wp of routeWaypoints) {
                if (wp.type === 'hike' && wp.gpxPath) {
                    try {
                        const gpxRes = await fetch(wp.gpxPath);
                        if (gpxRes.ok) {
                            const xml = await gpxRes.text();
                            wp.gpxTrack = parseGpxTrack(xml);
                        }
                    } catch (err) {
                        console.error('Failed to load GPX for hike:', err);
                    }
                }
            }

            currentTrip = tripToLoad;
            document.getElementById('trip-display').textContent = tripToLoad;
            document.getElementById('menu-rename-btn').style.display = 'block';
            document.getElementById('menu-delete-btn').style.display = 'block';
            fetchBanner(tripToLoad, false, data.banner_url);
            renderUI();
            updateMap();
        } else {
            // This case shouldn't be reached now due to the check above
            createNewTrip();
        }
    }

    async function createFirstTrip() {
        const tripName = document.getElementById('first-trip-name').value.trim();
        if (!tripName) {
            alert('Please enter a trip name');
            return;
        }
        
        currentTrip = tripName;
        routeWaypoints = [];
        document.getElementById('trip-display').textContent = tripName;
        document.getElementById('menu-rename-btn').style.display = 'block';
        document.getElementById('menu-delete-btn').style.display = 'block';
        
        // Hide overlay
        document.getElementById('startup-overlay').style.display = 'none';
        document.getElementById('add-waypoint-controls').style.display = 'block';
        
        fetchBanner(tripName);
        renderUI();
        updateMap();
        
        // Save immediately to database
        await saveTrip();
        await loadTrips();
    }

    function toggleFullscreenMap() {
        const body = document.body;
        const btn = document.getElementById('fullscreen-map-toggle');
        const icon = btn.querySelector('i');
        
        body.classList.toggle('map-fullscreen');
        btn.classList.toggle('active');
        
        if (body.classList.contains('map-fullscreen')) {
            icon.classList.remove('fa-expand');
            icon.classList.add('fa-compress');
        } else {
            icon.classList.remove('fa-compress');
            icon.classList.add('fa-expand');
        }
        
        // Ensure map resizes correctly
        setTimeout(() => map.invalidateSize(), 300);
    }

    // Load data on startup
    window.onload = () => {
        startup();
        setupResizeListener();
        trackUserLocation();
    };

    function trackUserLocation() {
        if ('geolocation' in navigator) {
            navigator.geolocation.watchPosition(
                (position) => {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    
                    if (userLocationMarker) {
                        userLocationMarker.setLatLng([lat, lng]);
                    } else {
                        userLocationMarker = L.marker([lat, lng], {
                            icon: L.divIcon({
                                className: 'user-location-container',
                                html: '<div class="user-location-marker"></div>',
                                iconSize: [14, 14],
                                iconAnchor: [7, 7]
                            }),
                            zIndexOffset: 1000,
                            interactive: false
                        }).addTo(map);
                    }
                },
                (err) => console.warn('Geolocation error:', err),
                { enableHighAccuracy: true }
            );
        }
    }
    
    function updateDateInput() {
        const dateInput = document.getElementById('date-input');
        if (routeWaypoints.length === 0) {
            // No waypoints, use today's date
            dateInput.value = new Date().toISOString().split('T')[0];
        } else {
            // Find the latest date in the trip
            const latestDate = routeWaypoints.reduce((max, wp) => wp.date > max ? wp.date : max, routeWaypoints[0].date);
            dateInput.value = latestDate;
        }
    }
    
    function setupResizeListener() {
        const divider = document.getElementById('resize-divider');
        const sidebar = document.getElementById('sidebar');
        const mapEl = document.getElementById('map');
        let isResizing = false;
        
        const startResize = () => {
            isResizing = true;
            divider.style.background = '#999';
        };
        
        const handleMove = (clientX, clientY) => {
            if (!isResizing) return;
            
            const container = document.body;
            const containerRect = container.getBoundingClientRect();
            const isVerticalLayout = window.innerWidth <= 768;
            
            // Prevent text selection during resize
            container.style.userSelect = 'none';
            container.style.cursor = isVerticalLayout ? 'row-resize' : 'col-resize';
            
            if (isVerticalLayout) {
                const newHeight = containerRect.bottom - clientY;
                const minHeight = 50;
                const maxHeight = containerRect.height - 50;
                
                if (newHeight > minHeight && newHeight < maxHeight) {
                    sidebar.style.height = newHeight + 'px';
                    sidebar.style.width = '100%';
                    sidebar.style.flexBasis = 'auto';
                    mapEl.style.width = '100%';
                    mapEl.style.height = 'auto'; 
                    map.invalidateSize();
                }
            } else {
                const newWidth = clientX - containerRect.left;
                const minWidth = 100;
                const maxWidth = containerRect.width - 100;
                
                if (newWidth > minWidth && newWidth < maxWidth) {
                    sidebar.style.width = newWidth + 'px';
                    sidebar.style.height = '100%';
                    sidebar.style.flexBasis = 'auto';
                    mapEl.style.height = '100%';
                    mapEl.style.width = 'auto';
                    map.invalidateSize();
                }
            }
        };
        
        const stopResize = () => {
            isResizing = false;
            divider.style.background = '#eee';
            document.body.style.userSelect = '';
            document.body.style.cursor = '';
            map.invalidateSize();
        };
        
        // Mouse events
        divider.addEventListener('mousedown', startResize);
        document.addEventListener('mouseup', stopResize);
        document.addEventListener('mousemove', (e) => handleMove(e.clientX, e.clientY));
        
        // Touch events for mobile
        divider.addEventListener('touchstart', (e) => { e.preventDefault(); startResize(); }, { passive: false });
        document.addEventListener('touchend', stopResize);
        document.addEventListener('touchmove', (e) => {
            if (isResizing) {
                e.preventDefault(); // prevent scroll when actively dragging
                if (e.touches.length > 0) {
                    handleMove(e.touches[0].clientX, e.touches[0].clientY);
                }
            }
        }, { passive: false });
    }
    
    async function logout() {
        if (!confirm('Are you sure you want to logout?')) return;
        const res = await fetch('api.php?action=logout');
        const data = await res.json();
        if (data.success) {
            window.location.href = 'login.php';
        }
    }

    // Helper functions for Day insertion, deletion, and relocation
    window.deleteDay = function(date) {
        if (!confirm(`Are you sure you want to delete this day (${new Date(date).toLocaleDateString()})? All activities on this day will be removed.`)) return;
        routeWaypoints = routeWaypoints.filter(wp => wp.date !== date);
        renumberWaypoints();
        renderUI();
        updateMap();
        autoSaveTrip();
    };

    window.insertDayBetween = function(prevDateStr, nextDateStr) {
        const prevDate = new Date(prevDateStr);
        const nextDate = new Date(nextDateStr);
        const diffTime = Math.abs(nextDate - prevDate);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        
        let insertDateStr;
        if (diffDays > 1) {
            // Fill the gap
            const insertDate = new Date(prevDate);
            insertDate.setDate(insertDate.getDate() + 1);
            insertDateStr = insertDate.toISOString().split('T')[0];
        } else {
            // Consecutive days - shift next day and all subsequent days by 1
            routeWaypoints.forEach(wp => {
                if (wp.type !== 'poi' && wp.date >= nextDateStr) {
                    const d = new Date(wp.date);
                    d.setDate(d.getDate() + 1);
                    wp.date = d.toISOString().split('T')[0];
                }
            });
            insertDateStr = nextDateStr;
        }
        
        // Add an empty day at insertDateStr
        routeWaypoints.push({
            name: 'Empty day',
            fullname: 'Empty day',
            lat: 0,
            lng: 0,
            type: 'empty_day',
            date: insertDateStr,
            comment: ''
        });
        
        renumberWaypoints();
        renderUI();
        updateMap();
        autoSaveTrip();
    };

    window.autofillIntermediateDays = function() {
        const dates = [...new Set(routeWaypoints.filter(wp => wp.type !== 'poi').map(wp => wp.date))].sort();
        if (dates.length < 2) {
            alert('At least 2 days are required to fill intermediate days.');
            return;
        }
        
        const firstDate = new Date(dates[0]);
        const lastDate = new Date(dates[dates.length - 1]);
        let current = new Date(firstDate);
        let addedCount = 0;
        
        while (current < lastDate) {
            current.setDate(current.getDate() + 1);
            const currentStr = current.toISOString().split('T')[0];
            if (current < lastDate && !dates.includes(currentStr)) {
                routeWaypoints.push({
                    name: 'Empty day',
                    fullname: 'Empty day',
                    lat: 0,
                    lng: 0,
                    type: 'empty_day',
                    date: currentStr,
                    comment: ''
                });
                addedCount++;
            }
        }
        
        if (addedCount > 0) {
            renumberWaypoints();
            renderUI();
            updateMap();
            autoSaveTrip();
            alert(`${addedCount} intermediate day(s) added.`);
        } else {
            alert('No missing intermediate days to fill.');
        }
    };

    let calendarCurrentDate = new Date();
    let moveWaypointIndex = null;

    window.showMoveCalendar = function(e, index) {
        moveWaypointIndex = index;
        const wp = routeWaypoints[index];
        document.getElementById('move-wp-name').innerText = `Move "${wp.name}" to a day:`;
        
        if (wp.date && wp.type !== 'poi') {
            calendarCurrentDate = new Date(wp.date);
        } else {
            const dates = [...new Set(routeWaypoints.filter(x => x.type !== 'poi').map(x => x.date))].sort();
            if (dates.length > 0) {
                calendarCurrentDate = new Date(dates[0]);
            } else {
                calendarCurrentDate = new Date();
            }
        }
        
        renderCalendar();
        document.getElementById('calendar-modal').classList.add('show');
    };

    window.closeCalendarModal = function() {
        document.getElementById('calendar-modal').classList.remove('show');
    };

    window.changeCalendarMonth = function(offset) {
        calendarCurrentDate.setMonth(calendarCurrentDate.getMonth() + offset);
        renderCalendar();
    };

    window.renderCalendar = function() {
        const year = calendarCurrentDate.getFullYear();
        const month = calendarCurrentDate.getMonth();
        
        const monthNames = [
            "Januari", "Februari", "Maart", "April", "Mei", "Juni", 
            "Juli", "Augustus", "September", "Oktober", "November", "December"
        ];
        
        document.getElementById('calendar-month-year').innerText = `${monthNames[month]} ${year}`;
        
        const firstDayIndex = (new Date(year, month, 1).getDay() + 6) % 7; // Monday = 0
        const totalDays = new Date(year, month + 1, 0).getDate();
        const prevTotalDays = new Date(year, month, 0).getDate();
        
        const daysContainer = document.getElementById('calendar-days');
        daysContainer.innerHTML = '';
        
        const tripDates = [...new Set(routeWaypoints.filter(wp => wp.type !== 'poi').map(wp => wp.date))].sort();
        const wp = routeWaypoints[moveWaypointIndex];
        
        for (let i = firstDayIndex - 1; i >= 0; i--) {
            const dayNum = prevTotalDays - i;
            const div = document.createElement('div');
            div.className = 'calendar-day';
            div.innerText = dayNum;
            daysContainer.appendChild(div);
        }
        
        for (let i = 1; i <= totalDays; i++) {
            const div = document.createElement('div');
            div.className = 'calendar-day current-month';
            div.innerText = i;
            
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(i).padStart(2, '0')}`;
            
            if (tripDates.includes(dateStr)) {
                div.classList.add('trip-day');
                if (wp && wp.date === dateStr) {
                    div.classList.add('active-wp-day');
                }
                div.onclick = () => {
                    moveWaypointToDate(moveWaypointIndex, dateStr);
                    closeCalendarModal();
                };
            }
            
            daysContainer.appendChild(div);
        }
        
        const currentSlots = firstDayIndex + totalDays;
        const nextMonthDays = 42 - currentSlots;
        for (let i = 1; i <= nextMonthDays; i++) {
            const div = document.createElement('div');
            div.className = 'calendar-day';
            div.innerText = i;
            daysContainer.appendChild(div);
        }
    };

    window.moveWaypointToDate = function(index, newDate) {
        routeWaypoints[index].date = newDate;
        if (routeWaypoints[index].type === 'poi') {
            routeWaypoints[index].type = 'sight';
        }
        renumberWaypoints();
        renderUI();
        updateMap();
        autoSaveTrip();
    };

    // Register Service Worker for PWA
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('service-worker.js')
                .then(reg => console.log('Service Worker registered'))
                .catch(err => console.log('Service Worker registration failed', err));
        });
    }
</script>

<style>
/* CSS styles for visual insert zones and calendar modal */
.insert-day-zone {
    position: relative;
    height: 28px;
    margin: 4px 0;
    z-index: 50;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 1;
    transition: background 0.2s ease, height 0.2s ease, margin 0.2s ease;
}
.insert-day-zone::before {
    content: '';
    position: absolute;
    top: -10px;
    bottom: -10px;
    left: 0;
    right: 0;
    z-index: 1;
}
.insert-day-zone:hover {
    background: rgba(203, 213, 225, 0.12);
}
.insert-day-line {
    position: absolute;
    left: 0;
    right: 0;
    top: 50%;
    transform: translateY(-50%);
    height: 1px;
    background: #d1d5db;
    opacity: 1;
    pointer-events: none;
}
.insert-day-btn {
    position: relative;
        top: 50%;
        transform: translateY(-50%);
        z-index: 2;
        background: #f8fafc;
        color: #6b7280;
        border: 1px solid #d1d5db;
        border-radius: 50%;
        width: 12px;
        height: 12px;
        padding: 0;
        font-size: 0.65rem;
        line-height: 1;
        cursor: pointer;
        box-shadow: none;
        transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .insert-day-btn i {
        font-size: 0.7rem;
        line-height: 1;
    background: #f5f7fa;
    border-color: #d1d5db;
    color: #4b5563;
}

.delete-day-btn:hover {
    opacity: 1 !important;
}

/* Modal Container */
.modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    align-items: center;
    justify-content: center;
}
.modal.show {
    display: flex;
}
.modal-content {
    background-color: #ffffff;
    padding: 24px;
    border-radius: 16px;
    width: 90%;
    max-width: 360px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    border: 1px solid var(--border);
    animation: modalFadeIn 0.2s ease-out;
}
@keyframes modalFadeIn {
    from { transform: scale(0.95); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 10px;
}
.modal-header h3 {
    margin: 0;
    font-size: 1.15em;
    font-family: 'Outfit', sans-serif;
    color: var(--primary);
}
.close-modal {
    font-size: 20px;
    font-weight: bold;
    color: #94a3b8;
    cursor: pointer;
    transition: color 0.1s;
}
.close-modal:hover {
    color: #475569;
}
.calendar-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}
.calendar-controls button {
    background: none;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #64748b;
    transition: all 0.2s;
}
.calendar-controls button:hover {
    background: #f1f5f9;
    color: var(--primary);
}
#calendar-month-year {
    font-weight: 700;
    color: var(--primary);
    font-size: 0.95em;
}
.calendar-grid-header {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    text-align: center;
    font-weight: 600;
    font-size: 0.75em;
    color: #64748b;
    margin-bottom: 8px;
}
.calendar-days-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 4px;
}
.calendar-day {
    aspect-ratio: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85em;
    border-radius: 8px;
    cursor: default;
    color: #cbd5e1;
    font-weight: 500;
    transition: all 0.2s;
}
.calendar-day.current-month {
    color: #334155;
}
.calendar-day.trip-day {
    background-color: #dbeafe;
    color: #1e40af;
    font-weight: 700;
    cursor: pointer;
    border: 1px dashed #3b82f6;
}
.calendar-day.trip-day:hover {
    background-color: #3b82f6;
    color: white;
}
.calendar-day.active-wp-day {
    box-shadow: inset 0 0 0 2px #3b82f6;
}
</style>

<div id="calendar-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Activiteit verplaatsen</h3>
            <span class="close-modal" onclick="closeCalendarModal()">&times;</span>
        </div>
        <div class="modal-body">
            <p id="move-wp-name" style="font-weight: 600; margin-bottom: 15px; font-size: 0.9em; color: #475569;"></p>
            <div class="calendar-controls">
                <button onclick="changeCalendarMonth(-1)"><i class="fas fa-chevron-left"></i></button>
                <span id="calendar-month-year"></span>
                <button onclick="changeCalendarMonth(1)"><i class="fas fa-chevron-right"></i></button>
            </div>
            <div class="calendar-grid-header">
                <div>Ma</div><div>Di</div><div>Wo</div><div>Do</div><div>Vr</div><div>Za</div><div>Zo</div>
            </div>
            <div id="calendar-days" class="calendar-days-grid"></div>
        </div>
    </div>
</div>
</body>
</html>