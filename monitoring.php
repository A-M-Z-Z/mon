<?php
session_start();

// Afficher les erreurs pour le débogage
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Vérifier la connexion
if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
    header("Location: expired");
    exit();
}

$isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
$username = $_SESSION['username'];
$userId = $_SESSION['user_id'];

// Traiter les requêtes AJAX pour les données système
if (isset($_GET['get_data']) && $_GET['get_data'] === 'true') {
    // Connexion à la base de données
    $host = 'localhost';
    $user = 'root';
    $pass = 'root';
    $dbname = 'cloudbox';
    $conn = new mysqli($host, $user, $pass, $dbname);
    
    if ($conn->connect_error) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database connection failed']);
        exit;
    }

    // Structure de données
    $data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'system' => [],
        'personal' => []
    ];

    // Informations système (admin uniquement)
    if ($isAdmin) {
        // CPU Usage
        try {
            // Essayer d'obtenir l'utilisation CPU
            if (function_exists('shell_exec')) {
                $cpu_load = shell_exec("top -bn1 | grep 'Cpu(s)' | awk '{print $2 + $4}'");
                $cpu_usage = floatval($cpu_load);
            } else {
                $load = sys_getloadavg();
                $cpuCores = 4; // Valeur par défaut si nproc n'est pas disponible
                $cpu_usage = round($load[0] * 100 / $cpuCores, 1);
            }
        } catch (Exception $e) {
            $cpu_usage = 25; // Valeur par défaut si erreur
        }
        
        $data['system']['cpu'] = [
            'usage' => $cpu_usage,
            'user' => round($cpu_usage * 0.7), // estimation
            'system' => round($cpu_usage * 0.3), // estimation
            'idle' => max(0, 100 - $cpu_usage)
        ];
        
        // Disk Usage
        try {
            if (function_exists('disk_free_space') && function_exists('disk_total_space')) {
                $disk_free = disk_free_space("/");
                $disk_total = disk_total_space("/");
                $disk_used = $disk_total - $disk_free;
                
                $data['system']['disk'] = [
                    'total' => round($disk_total / (1024 * 1024 * 1024), 2), // GB
                    'used' => round($disk_used / (1024 * 1024 * 1024), 2),   // GB
                    'free' => round($disk_free / (1024 * 1024 * 1024), 2),   // GB
                    'percent' => round(($disk_used / $disk_total) * 100, 1)
                ];
            } else {
                // Valeurs par défaut
                $data['system']['disk'] = [
                    'total' => 50,  // 50 GB
                    'used' => 25,   // 25 GB
                    'free' => 25,   // 25 GB
                    'percent' => 50 // 50%
                ];
            }
        } catch (Exception $e) {
            // Valeurs par défaut en cas d'erreur
            $data['system']['disk'] = [
                'total' => 50,  // 50 GB
                'used' => 25,   // 25 GB
                'free' => 25,   // 25 GB
                'percent' => 50 // 50%
            ];
        }
        
        // CPU Temperature
        try {
            $temperature = null;
            
            // Essayer Raspberry Pi
            if (file_exists('/sys/class/thermal/thermal_zone0/temp')) {
                $temp = intval(file_get_contents('/sys/class/thermal/thermal_zone0/temp'));
                $temperature = round($temp / 1000, 1);
            } 
            // Essayer "sensors" si disponible
            elseif (function_exists('shell_exec')) {
                $sensors = shell_exec("sensors 2>/dev/null | grep -i 'core\s*[0-9]\+' | head -1");
                if ($sensors && preg_match('/\+(\d+\.\d+)°C/', $sensors, $matches)) {
                    $temperature = floatval($matches[1]);
                }
            }
            
            // Si tout échoue, utiliser une valeur par défaut
            if ($temperature === null) {
                $temperature = 45; // 45°C - valeur par défaut
            }
            
            $data['system']['temperature'] = $temperature;
        } catch (Exception $e) {
            $data['system']['temperature'] = 45; // Valeur par défaut en cas d'erreur
        }
        
        // Statistiques fichiers
        $fileCountQuery = "SELECT COUNT(*) as count FROM files";
        $result = $conn->query($fileCountQuery);
        if ($result) {
            $row = $result->fetch_assoc();
            $data['system']['files'] = $row['count'] ?? 0;
        } else {
            $data['system']['files'] = 0;
        }
        
        // Total stockage
        $totalStorageQuery = "SELECT SUM(file_size) as total_size FROM files";
        $result = $conn->query($totalStorageQuery);
        if ($result) {
            $row = $result->fetch_assoc();
            $totalStorageUsed = $row['total_size'] ?? 0;
            $data['system']['storage'] = [
                'used' => round($totalStorageUsed / (1024 * 1024), 2) // MB
            ];
        } else {
            $data['system']['storage'] = [
                'used' => 0
            ];
        }
    }

    // Stockage personnel (disponible pour tous les utilisateurs)
    try {
        $userStorageQuery = $conn->prepare("SELECT SUM(file_size) as total_size FROM files WHERE user_id = ?");
        $userStorageQuery->bind_param("i", $userId);
        $userStorageQuery->execute();
        $result = $userStorageQuery->get_result();
        $row = $result->fetch_assoc();
        $userStorageUsed = $row['total_size'] ?? 0;
    } catch (Exception $e) {
        $userStorageUsed = 0;
    }

    // Quota de l'utilisateur
    try {
        $quotaQuery = $conn->prepare("SELECT storage_quota FROM users WHERE id = ?");
        $quotaQuery->bind_param("i", $userId);
        $quotaQuery->execute();
        $result = $quotaQuery->get_result();
        $row = $result->fetch_assoc();
        $userQuota = $row['storage_quota'] ?? 104857600; // 100MB par défaut
    } catch (Exception $e) {
        $userQuota = 104857600; // 100MB par défaut
    }

    $data['personal']['storage'] = [
        'used' => round($userStorageUsed / (1024 * 1024), 2), // MB
        'quota' => round($userQuota / (1024 * 1024), 2), // MB
        'percent' => $userQuota > 0 ? round(($userStorageUsed / $userQuota) * 100, 2) : 0
    ];

    // Retourner les données en JSON
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
            
            <!-- System Stats Card -->
            <div class="dashboard-card">
                <div class="card-title">
                    <span>System Statistics</span>
                    <span class="icon">📊</span>
                </div>
                <div class="card-content" style="display: flex; flex-direction: column; justify-content: center; align-items: center;">
                    <div style="margin-bottom: 20px;">
                        <h3 style="text-align: center; margin-bottom: 10px;">Total Files</h3>
                        <div id="fileCount" style="font-size: 36px; font-weight: bold; color: #4f46e5; text-align: center;">--</div>
                    </div>
                    <div>
                        <h3 style="text-align: center; margin-bottom: 10px;">Total Storage</h3>
                        <div id="totalStorage" style="font-size: 36px; font-weight: bold; color: #4f46e5; text-align: center;">-- MB</div>
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
                labels: ['Used', 'Idle'],
                datasets: [{
                    data: [0, 100],
                    backgroundColor: [
                        '#4f46e5', // Used - Blue
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
    
    // Update the temperature gauge
    function updateTemperatureGauge(temperature) {
        const needle = document.getElementById('tempNeedle');
        const value = document.getElementById('tempValue');
        
        if (!needle || !value || temperature === null) {
            if (value) value.textContent = 'N/A';
            return;
        }
        
        // Update the value display
        value.textContent = temperature + '°C';
        
        // Calculate rotation (0° at 0°C, 180° at 100°C)
        let rotation = Math.min(180, Math.max(0, temperature * 1.8));
        needle.style.transform = `rotate(${rotation}deg)`;
        
        // Update color based on temperature range
        let color;
        if (temperature <= 60) {
            color = '#22c55e'; // Green (good)
        } else if (temperature <= 80) {
            color = '#f59e0b'; // Yellow (warning)
        } else {
            color = '#ef4444'; // Red (critical)
        }
        value.style.color = color;
    }
    <?php endif; ?>
    
    // Update charts based on data
    function updateCharts(data) {
        // Update personal storage chart
        if (charts.personalStorage && data.personal && data.personal.storage) {
            const used = data.personal.storage.percent;
            const free = 100 - used;
            
            charts.personalStorage.data.datasets[0].data = [used, free];
            charts.personalStorage.data.labels = [`Used (${used.toFixed(1)}%)`, `Free (${free.toFixed(1)}%)`];
            charts.personalStorage.update();
            
            const info = document.getElementById('personalStorageInfo');
            if (info) {
                info.innerHTML = `
                    <p><span>Used:</span> <strong>${data.personal.storage.used.toFixed(2)} MB</strong></p>
                    <p><span>Quota:</span> <strong>${data.personal.storage.quota.toFixed(2)} MB</strong></p>
                    <p><span>Usage:</span> <strong>${data.personal.storage.percent.toFixed(1)}%</strong></p>
                `;
            }
        }
        
        <?php if($isAdmin): ?>
        // Update system disk usage chart
        if (charts.diskUsage && data.system && data.system.disk) {
            const usedPercent = data.system.disk.percent;
            const freePercent = 100 - usedPercent;
            
            charts.diskUsage.data.datasets[0].data = [usedPercent, freePercent];
            charts.diskUsage.data.labels = [`Used (${usedPercent}%)`, `Free (${freePercent}%)`];
            charts.diskUsage.update();
            
            const info = document.getElementById('diskUsageInfo');
            if (info) {
                info.innerHTML = `
                    <p><span>Used:</span> <strong>${data.system.disk.used.toFixed(2)} GB</strong></p>
                    <p><span>Free:</span> <strong>${data.system.disk.free.toFixed(2)} GB</strong></p>
                    <p><span>Total:</span> <strong>${data.system.disk.total.toFixed(2)} GB</strong></p>
                `;
            }
        }
        
        // Update CPU usage chart
        if (charts.cpuUsage && data.system && data.system.cpu) {
            const usage = data.system.cpu.usage;
            const idle = data.system.cpu.idle;
            
            charts.cpuUsage.data.datasets[0].data = [usage, idle];
            charts.cpuUsage.update();
            
            const info = document.getElementById('cpuUsageInfo');
            if (info) {
                info.innerHTML = `
                    <p><span>CPU Usage:</span> <strong>${usage.toFixed(1)}%</strong></p>
                    <p><span>CPU Idle:</span> <strong>${idle.toFixed(1)}%</strong></p>
                `;
            }
        }
        
        // Update CPU temperature
        if (data.system && data.system.temperature !== null) {
            updateTemperatureGauge(data.system.temperature);
        }
        
        // Update system stats
        if (data.system) {
            // Update file count
            if (data.system.files !== undefined) {
                document.getElementById('fileCount').textContent = data.system.files;
            }
            
            // Update total storage
            if (data.system.storage && data.system.storage.used !== undefined) {
                document.getElementById('totalStorage').textContent = data.system.storage.used.toFixed(2) + ' MB';
            }
        }
        <?php endif; ?>
        
        // Update timestamp
        if (data.timestamp) {
            document.getElementById('lastUpdated').textContent = data.timestamp;
        }
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
        const display = document.getElementById('countdownTimer');
        if (display) display.textContent = countdownTimer;
        
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
                const display = document.getElementById('countdownTimer');
                if (display) display.textContent = countdownTimer;
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
    
    // Initialize charts and start data refresh
    window.addEventListener('DOMContentLoaded', () => {
        // Initialize charts
        initPersonalStorageChart();
        
        <?php if($isAdmin): ?>
        initDiskUsageChart();
        initCpuUsageChart();
        <?php endif; ?>
        
        // Initial data fetch
        fetchData();
    });
    </script>
</body>
</html>
