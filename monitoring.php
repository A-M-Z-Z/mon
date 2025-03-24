<?php
session_start(); // Start session

// Check if user is logged in
if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
    header("Location: expired");
    exit();
}

$isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
$username = $_SESSION['username'];
$userId = $_SESSION['user_id'];

// Handle AJAX request for system data
if (isset($_GET['get_data']) && $_GET['get_data'] === 'true') {
    // Database Connection
    $host = '91.216.107.164';
    $user = 'amzz2427862';
    $pass = '37qB5xqen4prX8@';
    $dbname = 'amzz2427862';
    $conn = new mysqli($host, $user, $pass, $dbname);
    if ($conn->connect_error) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database connection failed']);
        exit;
    }

    // Data collection
    $data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'system' => [],
        'personal' => []
    ];

    // System information (only available to admins)
    if ($isAdmin) {
        /**
         * CPU Usage Collection
         */
        $cpuData = [];
        
        // Try to get CPU usage from /proc/stat
        if (file_exists('/proc/stat')) {
            $stat1 = file('/proc/stat');
            // Sleep briefly to measure CPU over time
            usleep(100000); // 100ms
            $stat2 = file('/proc/stat');
            
            if ($stat1 && $stat2) {
                // Get CPU line
                $cpu1 = explode(' ', preg_replace('/\s+/', ' ', $stat1[0]));
                $cpu2 = explode(' ', preg_replace('/\s+/', ' ', $stat2[0]));
                
                // Calculate jiffies
                $user1 = $cpu1[1] + $cpu1[2]; // user + nice
                $system1 = $cpu1[3]; // system
                $idle1 = $cpu1[4]; // idle
                $iowait1 = isset($cpu1[5]) ? $cpu1[5] : 0; // iowait
                $total1 = $user1 + $system1 + $idle1 + $iowait1;
                
                $user2 = $cpu2[1] + $cpu2[2]; // user + nice
                $system2 = $cpu2[3]; // system
                $idle2 = $cpu2[4]; // idle
                $iowait2 = isset($cpu2[5]) ? $cpu2[5] : 0; // iowait
                $total2 = $user2 + $system2 + $idle2 + $iowait2;
                
                // Calculate difference
                $totalDiff = $total2 - $total1;
                if ($totalDiff > 0) {
                    $userPercent = round(($user2 - $user1) * 100 / $totalDiff, 1);
                    $systemPercent = round(($system2 - $system1) * 100 / $totalDiff, 1);
                    $ioWaitPercent = round(($iowait2 - $iowait1) * 100 / $totalDiff, 1);
                    $idlePercent = round(($idle2 - $idle1) * 100 / $totalDiff, 1);
                    
                    $cpuData = [
                        'user' => $userPercent,
                        'system' => $systemPercent,
                        'iowait' => $ioWaitPercent,
                        'idle' => $idlePercent,
                        'total' => $userPercent + $systemPercent + $ioWaitPercent
                    ];
                }
            }
        }
        
        // Fallback to load average
        if (empty($cpuData)) {
            $load = sys_getloadavg();
            $cpuCores = intval(shell_exec('nproc')) ?: 1;
            $cpuLoad = round($load[0] * 100 / $cpuCores, 1);
            
            $cpuData = [
                'user' => round($cpuLoad * 0.7, 1),     // Estimate user CPU usage
                'system' => round($cpuLoad * 0.3, 1),   // Estimate system CPU usage
                'iowait' => 0,
                'idle' => max(0, 100 - $cpuLoad),
                'total' => $cpuLoad
            ];
        }
        
        // Add load averages
        $load = sys_getloadavg();
        $cpuCores = intval(shell_exec('nproc')) ?: 1;
        $cpuData['load_1min'] = round($load[0] * 100 / $cpuCores, 1);
        $cpuData['load_5min'] = round($load[1] * 100 / $cpuCores, 1);
        $cpuData['load_15min'] = round($load[2] * 100 / $cpuCores, 1);
        
        $data['system']['cpu'] = $cpuData;
        
        /**
         * Memory Usage Collection
         */
        $memData = [];
        
        // Try to get memory info from /proc/meminfo
        if (file_exists('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            if ($meminfo) {
                preg_match('/MemTotal:\s+(\d+)/', $meminfo, $totalMatches);
                preg_match('/MemFree:\s+(\d+)/', $meminfo, $freeMatches);
                preg_match('/Buffers:\s+(\d+)/', $meminfo, $buffersMatches);
                preg_match('/Cached:\s+(\d+)/', $meminfo, $cachedMatches);
                preg_match('/SReclaimable:\s+(\d+)/', $meminfo, $reclaimableMatches);
                
                if (!empty($totalMatches)) {
                    $totalKb = intval($totalMatches[1]);
                    $freeKb = intval($freeMatches[1] ?? 0);
                    $buffersKb = intval($buffersMatches[1] ?? 0);
                    $cachedKb = intval($cachedMatches[1] ?? 0);
                    $reclaimableKb = intval($reclaimableMatches[1] ?? 0);
                    
                    // Calculate used memory (excluding cache & buffers)
                    $usedKb = $totalKb - $freeKb - $buffersKb - $cachedKb - $reclaimableKb;
                    $cacheKb = $buffersKb + $cachedKb + $reclaimableKb;
                    
                    $memData = [
                        'total' => round($totalKb / 1024, 0),       // MB
                        'used' => round($usedKb / 1024, 0),         // MB
                        'cache' => round($cacheKb / 1024, 0),       // MB
                        'free' => round($freeKb / 1024, 0),         // MB
                        'percent' => round($usedKb * 100 / $totalKb, 1)
                    ];
                }
            }
        }
        
        // Fallback to free command
        if (empty($memData)) {
            $meminfo = shell_exec('free -m');
            if ($meminfo) {
                preg_match('/^Mem:\s+(\d+)\s+(\d+)\s+(\d+)/m', $meminfo, $matches);
                if (!empty($matches)) {
                    $totalMem = intval($matches[1]);
                    $usedMem = intval($matches[2]);
                    $freeMem = intval($matches[3]);
                    
                    // Estimate cache (typically around 30% of used memory on average systems)
                    $cacheMem = round($usedMem * 0.3);
                    $realUsedMem = $usedMem - $cacheMem;
                    
                    $memData = [
                        'total' => $totalMem,
                        'used' => $realUsedMem,
                        'cache' => $cacheMem,
                        'free' => $freeMem,
                        'percent' => round($realUsedMem * 100 / $totalMem, 1)
                    ];
                }
            }
        }
        
        $data['system']['memory'] = $memData;
        
        /**
         * Disk Usage Collection
         */
        $diskData = ['disks' => []];
        
        // Get disk usage with df
        $dfOutput = shell_exec('df -B1');
        if ($dfOutput) {
            preg_match_all('/^(\/dev\/\S+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%\s+(.+)$/m', $dfOutput, $matches, PREG_SET_ORDER);
            
            $totalSize = 0;
            $totalUsed = 0;
            $rootDisk = null;
            
            foreach ($matches as $match) {
                if (strpos($match[6], '/') === 0) { // Only include actual mounted filesystems
                    $mountPoint = $match[6];
                    $size = round($match[2] / (1024 * 1024 * 1024), 2); // GB
                    $used = round($match[3] / (1024 * 1024 * 1024), 2); // GB
                    $available = round($match[4] / (1024 * 1024 * 1024), 2); // GB
                    $percent = $match[5];
                    
                    $disk = [
                        'mount' => $mountPoint,
                        'size' => $size,
                        'used' => $used,
                        'available' => $available,
                        'percent' => $percent
                    ];
                    
                    $diskData['disks'][] = $disk;
                    
                    // Track root filesystem
                    if ($mountPoint === '/') {
                        $rootDisk = $disk;
                    }
                    
                    $totalSize += $size;
                    $totalUsed += $used;
                }
            }
            
            $diskData['total_size'] = $totalSize;
            $diskData['total_used'] = $totalUsed;
            $diskData['total_percent'] = $totalSize > 0 ? round(($totalUsed / $totalSize) * 100, 1) : 0;
            
            // If no root disk found, use the first one
            if ($rootDisk === null && !empty($diskData['disks'])) {
                $rootDisk = $diskData['disks'][0];
            }
            
            if ($rootDisk) {
                $diskData['root'] = $rootDisk;
                $diskData['percent'] = $rootDisk['percent']; // For backwards compatibility
            }
        }
        
        $data['system']['disk'] = $diskData;
        
        /**
         * CPU Temperature Collection
         */
        $temperature = null;
        
        // Try Raspberry Pi temperature sensor
        if (file_exists('/sys/class/thermal/thermal_zone0/temp')) {
            $temp = intval(file_get_contents('/sys/class/thermal/thermal_zone0/temp'));
            $temperature = round($temp / 1000, 1);
        }
        
        // Try sensors command (for general Linux systems)
        if ($temperature === null) {
            $sensorsOutput = shell_exec('sensors 2>/dev/null');
            if ($sensorsOutput) {
                // Look for CPU temperature in output
                if (preg_match('/Core 0.*?\+(\d+\.\d+)°C/', $sensorsOutput, $matches)) {
                    $temperature = floatval($matches[1]);
                } elseif (preg_match('/CPU Temperature.*?\+(\d+\.\d+)°C/', $sensorsOutput, $matches)) {
                    $temperature = floatval($matches[1]);
                } elseif (preg_match('/temp1.*?\+(\d+\.\d+)°C/', $sensorsOutput, $matches)) {
                    $temperature = floatval($matches[1]);
                }
            }
        }
        
        $data['system']['temperature'] = $temperature;
        
        /**
         * System Information
         */
        // Get uptime
        $uptime = shell_exec('uptime -p');
        $data['system']['uptime'] = $uptime ? trim($uptime) : null;
        
        // Get kernel version
        $kernel = shell_exec('uname -r');
        $data['system']['kernel'] = $kernel ? trim($kernel) : null;
        
        // Get hostname
        $hostname = shell_exec('hostname');
        $data['system']['hostname'] = $hostname ? trim($hostname) : null;
        
        // Database stats
        $userCountQuery = "SELECT COUNT(*) as count FROM users";
        $result = $conn->query($userCountQuery);
        $row = $result->fetch_assoc();
        $data['system']['users'] = $row['count'];
        
        $fileCountQuery = "SELECT COUNT(*) as count FROM files";
        $result = $conn->query($fileCountQuery);
        $row = $result->fetch_assoc();
        $data['system']['files'] = $row['count'];
        
        $totalStorageQuery = "SELECT SUM(file_size) as total_size FROM files";
        $result = $conn->query($totalStorageQuery);
        $row = $result->fetch_assoc();
        $totalStorageUsed = $row['total_size'] ?: 0;
        $data['system']['storage'] = [
            'used' => round($totalStorageUsed / (1024 * 1024), 2) // MB
        ];
    }

    /**
     * Personal Storage Usage (available to all users)
     */
    $userStorageQuery = $conn->prepare("SELECT SUM(file_size) as total_size FROM files WHERE user_id = ?");
    $userStorageQuery->bind_param("i", $userId);
    $userStorageQuery->execute();
    $result = $userStorageQuery->get_result();
    $row = $result->fetch_assoc();
    $userStorageUsed = $row['total_size'] ?: 0;

    // Get user's quota
    $quotaQuery = $conn->prepare("SELECT storage_quota FROM users WHERE id = ?");
    $quotaQuery->bind_param("i", $userId);
    $quotaQuery->execute();
    $result = $quotaQuery->get_result();
    $row = $result->fetch_assoc();
    $userQuota = $row['storage_quota'] ?: 104857600; // 100MB default

    $data['personal']['storage'] = [
        'used' => round($userStorageUsed / (1024 * 1024), 2), // MB
        'quota' => round($userQuota / (1024 * 1024), 2), // MB
        'percent' => round(($userStorageUsed / $userQuota) * 100, 2)
    ];

    // Return data as JSON
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CloudBOX - System Monitoring</title>
    <link rel="stylesheet" href="style.css">
    <!-- Chart.js from CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <style>
        /* Dashboard specific styles */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .dashboard-card {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .dashboard-card:hover {
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transform: translateY(-5px);
        }
        
        .card-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #1f2937;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .card-title .icon {
            font-size: 24px;
        }
        
        .card-content {
            height: 200px;
            position: relative;
        }
        
        .card-info {
            margin-top: 15px;
            font-size: 14px;
            color: #6b7280;
        }
        
        .card-info p {
            margin: 5px 0;
            display: flex;
            justify-content: space-between;
        }
        
        .full-width {
            grid-column: 1 / -1;
        }
        
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
        }
        
        .metric-card {
            background-color: #f9fafb;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            transition: transform 0.3s ease;
        }
        
        .metric-card:hover {
            transform: translateY(-5px);
        }
        
        .metric-value {
            font-size: 24px;
            font-weight: bold;
            margin: 10px 0;
            color: #4f46e5;
        }
        
        .metric-label {
            font-size: 14px;
            color: #6b7280;
        }
        
        .temp-gauge {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .gauge {
            width: 200px;
            height: 100px;
            position: relative;
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .gauge-background {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: linear-gradient(0deg, #22c55e 0%, #22c55e 60%, #f59e0b 60%, #f59e0b 80%, #ef4444 80%, #ef4444 100%);
            position: absolute;
            bottom: 0;
        }
        
        .gauge-mask {
            width: 160px;
            height: 160px;
            background: #ffffff;
            border-radius: 50%;
            position: absolute;
            bottom: 0;
            left: 20px;
        }
        
        .gauge-needle {
            width: 4px;
            height: 100px;
            background-color: #1f2937;
            position: absolute;
            bottom: 0;
            left: 98px;
            transform-origin: bottom center;
            transform: rotate(0deg);
            transition: transform 0.5s ease;
        }
        
        .gauge-value {
            font-size: 28px;
            font-weight: bold;
            color: #1f2937;
        }
        
        .gauge-label {
            font-size: 16px;
            color: #6b7280;
        }
        
        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        
        .status-good {
            background-color: #22c55e;
        }
        
        .status-warning {
            background-color: #f59e0b;
        }
        
        .status-critical {
            background-color: #ef4444;
        }
        
        .last-updated {
            text-align: center;
            margin-top: 20px;
            color: #6b7280;
            font-size: 14px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .countdown-container {
            display: flex;
            align-items: center;
            margin-top: 10px;
        }
        
        .countdown-bar {
            width: 100px;
            height: 6px;
            background-color: #e5e7eb;
            border-radius: 3px;
            margin-left: 10px;
            overflow: hidden;
        }
        
        .countdown-progress {
            height: 100%;
            background-color: #4f46e5;
            width: 100%;
            transition: width linear 1s;
        }
        
        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .card-content {
                height: 180px;
            }
            
            .metrics-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <div class="logo">
            <img src="logo.png" alt="CloudBOX Logo" height="40">
        </div>
        <h1>CloudBOX</h1>
        <div class="search-bar">
            <input type="text" placeholder="Search here...">
        </div>
    </div>
    
    <nav class="dashboard-nav">
        <a href="home">📊 Dashboard</a>
        <a href="drive">📁 My Drive</a>
        <?php if($isAdmin): ?>
        <a href="admin">👑 Admin Panel</a>
        <?php endif; ?>
        <a href="shared">🔄 Shared Files</a>
        <a href="monitoring">📈 Monitoring</a>
        <a href="#">🗑️ Trash</a>
        <a href="logout">🚪 Logout</a>
    </nav>

    <main>
        <h1>System Monitoring</h1>
        
        <div class="dashboard-grid">
            <!-- Personal Storage Usage Card -->
            <div class="dashboard-card">
                <div class="card-title">
                    <span>Your Storage Usage</span>
                    <span class="icon">💾</span>
                </div>
                <div class="card-content">
                    <canvas id="personalStorageChart"></canvas>
                </div>
                <div id="personalStorageInfo" class="card-info"></div>
            </div>
            
            <?php if($isAdmin): ?>
            <!-- System Disk Usage Card -->
            <div class="dashboard-card">
                <div class="card-title">
                    <span>System Disk Usage</span>
                    <span class="icon">💽</span>
                </div>
                <div class="card-content">
                    <canvas id="diskUsageChart"></canvas>
                </div>
                <div id="diskUsageInfo" class="card-info"></div>
            </div>
            
            <!-- CPU Temperature Card -->
            <div class="dashboard-card">
                <div class="card-title">
                    <span>CPU Temperature</span>
                    <span class="icon">🌡️</span>
                </div>
                <div class="card-content">
                    <div class="temp-gauge">
                        <div class="gauge">
                            <div class="gauge-background"></div>
                            <div class="gauge-mask"></div>
                            <div class="gauge-needle" id="tempNeedle"></div>
                        </div>
                        <div class="gauge-value" id="tempValue">--°C</div>
                        <div class="gauge-label">CPU Temperature</div>
                    </div>
                </div>
            </div>
            
            <!-- CPU Usage Card -->
            <div class="dashboard-card">
                <div class="card-title">
                    <span>CPU Usage</span>
                    <span class="icon">⚙️</span>
                </div>
                <div class="card-content">
                    <canvas id="cpuUsageChart"></canvas>
                </div>
                <div id="cpuUsageInfo" class="card-info"></div>
            </div>
            
            <!-- Memory Usage Card -->
            <div class="dashboard-card">
                <div class="card-title">
                    <span>Memory Usage</span>
                    <span class="icon">🧠</span>
                </div>
                <div class="card-content">
                    <canvas id="memoryUsageChart"></canvas>
                </div>
                <div id="memoryUsageInfo" class="card-info"></div>
            </div>
            
            <!-- System Overview Card -->
            <div class="dashboard-card full-width">
                <div class="card-title">
                    <span>System Overview</span>
                    <span class="icon">📊</span>
                </div>
                <div class="metrics-grid">
                    <div class="metric-card">
                        <div class="metric-value" id="userCount">--</div>
                        <div class="metric-label">Total Users</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value" id="fileCount">--</div>
                        <div class="metric-label">Total Files</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value" id="totalStorage">--</div>
                        <div class="metric-label">Total Storage Used</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value" id="systemUptime">--</div>
                        <div class="metric-label">System Uptime</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value" id="systemStatus">--</div>
                        <div class="metric-label">System Status</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value" id="kernelVersion">--</div>
                        <div class="metric-label">Kernel Version</div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- Non-admin message -->
            <div class="dashboard-card full-width">
                <div class="card-title">
                    <span>System Information</span>
                    <span class="icon">ℹ️</span>
                </div>
                <p>System-wide monitoring data is only available to administrators.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="last-updated">
            Last updated: <span id="lastUpdated">--</span>
            <div class="countdown-container">
                Auto-refresh: <span id="countdownTimer">10</span>s
                <div class="countdown-bar">
                    <div class="countdown-progress" id="countdownBar"></div>
                </div>
            </div>
        </div>
    </main>

    <script>
    // Charts and data management
    let charts = {};
    let refreshInterval = 10000; // 10 seconds
    let countdownTimer = 10;
    let countdownInterval;
    let isDataLoading = false;
    
    // Initialize the personal storage chart
    function initPersonalStorageChart() {
        const ctx = document.getElementById('personalStorageChart').getContext('2d');
        charts.personalStorage = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Used', 'Free'],
                datasets: [{
                    data: [0, 100],
                    backgroundColor: [
                        function(context) {
                            const value = context.dataset.data[context.dataIndex];
                            // First segment (Used)
                            if (context.dataIndex === 0) {
                                if (value < 70) return '#22c55e'; // Green - OK
                                if (value < 85) return '#f59e0b'; // Yellow - Warning
                                return '#ef4444'; // Red - Critical
                            }
                            // Second segment (Free)
                            return '#e5e7eb'; // Gray
                        },
                    ],
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                cutout: '70%',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                size: 12
                            },
                            padding: 10
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + context.parsed + '%';
                            }
                        }
                    }
                },
                animation: {
                    duration: 1000
                }
            }
        });
    }
    
    // Initialize the CPU usage chart
    function initCpuUsageChart() {
        const ctx = document.getElementById('cpuUsageChart').getContext('2d');
        charts.cpuUsage = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['User', 'System', 'Idle'],
                datasets: [{
                    data: [0, 0, 100],
                    backgroundColor: [
                        '#4f46e5', // User - Blue
                        '#f59e0b', // System - Orange
                        '#e5e7eb'  // Idle - Gray
                    ],
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                cutout: '70%',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                size: 12
                            },
                            padding: 10
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + context.raw.toFixed(1) + '%';
                            }
                        }
                    }
                },
                animation: {
                    duration: 1000
                }
            }
        });
    }
    
    // Initialize the memory usage chart
    function initMemoryUsageChart() {
        const ctx = document.getElementById('memoryUsageChart').getContext('2d');
        charts.memoryUsage = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Used', 'Cache', 'Free'],
                datasets: [{
                    data: [0, 0, 100],
                    backgroundColor: [
                        '#4f46e5', // Used - Blue
                        '#f59e0b', // Cache - Orange
                        '#e5e7eb'  // Free - Gray
                    ],
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                cutout: '70%',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                size: 12
                            },
                            padding: 10
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + context.raw.toFixed(0) + ' MB';
                            }
                        }
                    }
                },
                animation: {
                    duration: 1000
                }
            }
        });
    }
    
    // Fetch data from the server
    function fetchData() {
        if (isDataLoading) return; // Prevent multiple simultaneous requests
        
        isDataLoading = true;
        fetch('monitoring.php?get_data=true')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                isDataLoading = false;
                updateCharts(data);
                startCountdown();
            })
            .catch(error => {
                isDataLoading = false;
                console.error('Error fetching data:', error);
            });
    }
    
    // Start countdown timer
    function startCountdown() {
        countdownTimer = 10;
        const bar = document.getElementById('countdownBar');
        if (bar) bar.style.width = '100%';
        
        // Update countdown display
        updateCountdownDisplay();
        
        // Use requestAnimationFrame for smoother countdown
        let lastTime = Date.now();
        let elapsed = 0;
        
        function animate() {
            const now = Date.now();
            const delta = now - lastTime;
            lastTime = now;
            
            elapsed += delta;
            const progress = Math.min(1, elapsed / refreshInterval);
            
            // Update the countdown bar
            const bar = document.getElementById('countdownBar');
            if (bar) bar.style.width = (100 - progress * 100) + '%';
            
            // Update the countdown timer every second
            const remainingSeconds = Math.ceil((refreshInterval - elapsed) / 1000);
            if (remainingSeconds !== countdownTimer) {
                countdownTimer = remainingSeconds;
                updateCountdownDisplay();
            }
            
            // Continue the animation or fetch new data
            if (progress < 1) {
                requestAnimationFrame(animate);
            } else {
                fetchData();
            }
        }
        
        requestAnimationFrame(animate);
    }
    
    // Update countdown display
    function updateCountdownDisplay() {
        const display = document.getElementById('countdownTimer');
        if (display) display.textContent = countdownTimer;
    }
    
    // Initialize charts and start data refresh
    window.addEventListener('DOMContentLoaded', () => {
        // Initialize charts
        initPersonalStorageChart();
        
        <?php if($isAdmin): ?>
        initDiskUsageChart();
        initCpuUsageChart();
        initMemoryUsageChart();
        <?php endif; ?>
        
        // Initial data fetch
        fetchData();
    });
    <?php endif; ?>
    </script>
</body>
</html>
    }
    
    <?php if($isAdmin): ?>
    // Initialize the disk usage chart
    function initDiskUsageChart() {
        const ctx = document.getElementById('diskUsageChart').getContext('2d');
        charts.diskUsage = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Used', 'Free'],
                datasets: [{
                    data: [0, 100],
                    backgroundColor: [
                        function(context) {
                            const value = context.dataset.data[context.dataIndex];
                            // First segment (Used)
                            if (context.dataIndex === 0) {
                                if (value < 70) return '#22c55e'; // Green - OK
                                if (value < 85) return '#f59e0b'; // Yellow - Warning
                                return '#ef4444'; // Red - Critical
                            }
                            // Second segment (Free)
                            return '#e5e7eb'; // Gray
                        },
                    ],
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                