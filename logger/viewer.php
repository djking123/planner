<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
// Get all .log files in the current directory
$log_files = glob("*.log");

// If AJAX request to get new log content
if (isset($_GET['action']) && $_GET['action'] === 'fetch') {
    $file = $_GET['file'] ?? '';
    $lastPosition = intval($_GET['lastPosition'] ?? 0);
    
    if (file_exists($file)) {
        $size = filesize($file);
        $response = [
            'size' => $size,
            'content' => '',
            'newContent' => false
        ];
        
        if ($size > $lastPosition) {
            $handle = fopen($file, "r");
            fseek($handle, $lastPosition);
            $content = fread($handle, $size - $lastPosition);
            fclose($handle);
            
            $response['content'] = $content;
            $response['newContent'] = true;
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Log Viewer V0.2</title>
    <style>
        body {
            font-family: 'Monaco', 'Consolas', monospace;
            margin: 0;
            padding: 20px;
            background: #1e1e1e;
            color: #d4d4d4;
        }
        .controls {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            padding: 10px;
            background: #2d2d2d;
            border-bottom: 1px solid #3d3d3d;
            display: flex;
            gap: 10px;
            z-index: 100;
        }
        .log-header {
            background: #2a2a2a;
            padding: 15px;
            margin: 60px 0 20px 0;
            border: 1px solid #3d3d3d;
            border-radius: 4px;
        }
        .log-header pre {
            margin: 0;
            color: #858585;
        }
        .controls select, .controls input {
            padding: 5px;
            background: #3d3d3d;
            color: #d4d4d4;
            border: 1px solid #555;
            border-radius: 3px;
        }
        #logContent {
            margin-top: 20px; /* Adjusted margin */
            white-space: pre-wrap;
            padding: 10px;
            font-size: 14px;
        }
        .log-entry {
            padding: 5px 8px;
            border-bottom: 1px solid #333;
            cursor: pointer;
            transition: background-color 0.2s;
            display: flex;
        }
        .expand-indicator {
            width: 20px;
            color: #666;
            user-select: none;
        }
        .log-content {
            flex: 1;
        }
        .context {
            display: none;
            margin-left: 20px;
            padding: 8px;
            background: #2a2a2a;
            border-left: 3px solid #3d3d3d;
            margin-top: 4px;
            margin-bottom: 4px;
            font-family: 'Monaco', 'Consolas', monospace;
        }
        .context.visible {
            display: block;
        }
        /* JSON syntax highlighting */
        .json-key { color: #9cdcfe; }
        .json-string { color: #ce9178; }
        .json-number { color: #b5cea8; }
        .json-boolean { color: #569cd6; }
        .json-null { color: #569cd6; }
        /* Timestamp and level colors */
        .timestamp { color: #858585; }
        .ms { color: #6a9955; }
        .memory { color: #4fc1ff; }
        .DEBUG { color: #608b4e; }    /* Muted green for debug messages */
        .INFO { color: #3794ff; }     /* Bright blue for info messages */
        .WARNING { color: #ffd700; }   /* Gold yellow for warnings */
        .ERROR { color: #ff4444; }    /* Bright red for errors */
        .highlight { background-color: #614c06; }
    </style>
</head>
<body>
    <div class="controls">
        <select id="logFile">
            <?php foreach($log_files as $file): ?>
                <option value="<?= htmlspecialchars($file) ?>"><?= htmlspecialchars($file) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" id="search" placeholder="Search logs...">
        <label><input type="checkbox" id="autoScroll" checked> Auto-scroll</label>
    </div>
    <div id="logHeader" class="log-header"></div>
    <div id="logContent"></div>

    <script>
        let lastPosition = 0;
        let currentFile = '';
        let searchTimer = null;
        let currentContext = null;

        function highlightSearchText(text, searchTerm) {
            if (!searchTerm) return text;
            const escapedTerm = searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const regex = new RegExp(escapedTerm, 'gi');
            return text.replace(regex, match => `<span class="highlight">${match}</span>`);
        }

        function colorizeLogLevel(text) {
            const patterns = {
                DEBUG: /(\[DEBUG\s*\])/g,
                INFO: /(\[INFO\s*\])/g,
                WARNING: /(\[WARNING\s*\])/g,
                ERROR: /(\[ERROR\s*\])/g
            };
            
            let result = text;
            for (const [level, pattern] of Object.entries(patterns)) {
                if (pattern.test(text)) {
                    result = text.replace(pattern, `<span class="${level}">$1</span>`);
                    break;
                }
            }
            return result;
        }

        function colorizeLogEntry(text) {
            const patterns = {
                timestamp: /^(\[[\d\-: ]+\])/,
                level: /(\[(?:DEBUG|INFO|WARNING|ERROR)\s*\])/g,
                ms: /(\[\s*\d+(?:\.\d+)?ms\])/g,
                memory: /(\[\s*\d+(?:\.\d+)?\sMB\])/g
            };

            let colorized = text;
            // Apply colorization in specific order
            colorized = colorized.replace(patterns.timestamp, '<span class="timestamp">$1</span>');
            colorized = colorized.replace(patterns.level, (match) => colorizeLogLevel(match));
            colorized = colorized.replace(patterns.ms, '<span class="ms">$1</span>');
            colorized = colorized.replace(patterns.memory, '<span class="memory">$1</span>');
            
            return colorized;
        }

        function highlightJson(jsonStr) {
            return jsonStr.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match) {
                if (/^"/.test(match)) {
                    if (/:$/.test(match)) {
                        // JSON key
                        return `<span class="json-key">${match}</span>`;
                    } else {
                        // JSON string
                        return `<span class="json-string">${match}</span>`;
                    }
                } else if (/true|false/.test(match)) {
                    return `<span class="json-boolean">${match}</span>`;
                } else if (/null/.test(match)) {
                    return `<span class="json-null">${match}</span>`;
                } else {
                    // JSON number
                    return `<span class="json-number">${match}</span>`;
                }
            });
        }

        function processLogLines(lines) {
            const searchTerm = document.getElementById('search').value;
            let html = '';
            let contextLines = [];
            let hasContext = false;
            
            lines.forEach(line => {
                if (line.trim() === '') return;

                if (line.match(/^\[[\d\-: ]+\]/)) {
                    // If we have pending context, add it
                    if (contextLines.length > 0) {
                        html += `<div class="context">${highlightJson(contextLines.join('\n'))}</div>`;
                        contextLines = [];
                    }
                    // Check if next line is context
                    hasContext = lines[lines.indexOf(line) + 1]?.startsWith('Context:') || false;
                    // Add new log entry with expand indicator
                    const coloredLine = colorizeLogEntry(line);
                    const highlightedLine = searchTerm ? highlightSearchText(coloredLine, searchTerm) : coloredLine;
                    html += `<div class="log-entry">
                        <span class="expand-indicator">${hasContext ? '▼' : ' '}</span>
                        <span class="log-content">${highlightedLine}</span>
                    </div>`;
                } else if (line.startsWith('Context:')) {
                    contextLines = [line];
                } else if (contextLines.length > 0) {
                    contextLines.push(line);
                }
            });
            
            // Handle any remaining context
            if (contextLines.length > 0) {
                html += `<div class="context">${highlightJson(contextLines.join('\n'))}</div>`;
            }
            
            return html;
        }

        function parseLogHeader(content) {
            const headerMatch = content.match(/={80,}\n([\s\S]*?)={80,}/);
            if (!headerMatch) return { header: '', content };
            
            return {
                header: headerMatch[1].trim(),
                content: content.substring(headerMatch[0].length).trim()
            };
        }

        function fetchLogContent() {
            const file = document.getElementById('logFile').value;
            fetch(`?action=fetch&file=${encodeURIComponent(file)}&lastPosition=${lastPosition}`)
                .then(response => response.json())
                .then(data => {
                    if (data.newContent) {
                        // Parse header if we're starting from the beginning
                        if (lastPosition === 0) {
                            const { header, content } = parseLogHeader(data.content);
                            if (header) {
                                document.getElementById('logHeader').innerHTML = `<pre>${header}</pre>`;
                                data.content = content;
                            }
                        }
                        
                        const content = processLogLines(data.content.split('\n'));
                        document.getElementById('logContent').innerHTML += content;
                        
                        // Add click handlers to new log entries
                        document.querySelectorAll('.log-entry').forEach(entry => {
                            if (!entry.hasListener) {
                                entry.hasListener = true;
                                entry.addEventListener('click', function() {
                                    const context = this.nextElementSibling;
                                    if (context && context.classList.contains('context')) {
                                        context.classList.toggle('visible');
                                    }
                                });
                            }
                        });

                        lastPosition = data.size;

                        if (document.getElementById('autoScroll').checked) {
                            window.scrollTo(0, document.body.scrollHeight);
                        }
                    }
                });
        }

        function loadInitialContent() {
            const file = document.getElementById('logFile').value;
            if (file !== currentFile) {
                currentFile = file;
                lastPosition = 0;
                document.getElementById('logHeader').innerHTML = '';
                document.getElementById('logContent').innerHTML = '';
                fetchLogContent();
            }
        }

        function handleSearch() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                lastPosition = 0;
                loadInitialContent();
            }, 300);
        }

        document.getElementById('logFile').addEventListener('change', loadInitialContent);
        document.getElementById('search').addEventListener('input', handleSearch);

        // Initial load
        loadInitialContent();

        // Poll for updates
        setInterval(fetchLogContent, 1000);
    </script>
</body>
</html>
