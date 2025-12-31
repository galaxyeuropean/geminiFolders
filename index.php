<?php
// 1. GLOBAL CONFIG & ERROR SUPPRESSION
ini_set('memory_limit', '1024M');
error_reporting(0); // Stop warnings from breaking JSON output

// 2. PROTECTED FUNCTIONS (Safe from re-declaration)
if (!function_exists('getHDName')) {
    function getHDName($path) {
        if ($path === '/' || strpos($path, '/Volumes/') === false) return "Macintosh HD";
        $parts = explode('/', trim($path, '/'));
        return ($parts[0] === 'Volumes' && isset($parts[1])) ? $parts[1] : "Macintosh HD";
    }
}

if (!function_exists('getFolderStats')) {
    function getFolderStats($dir, $recursive = true) {
        $size = 0; $files = 0; $folders = 0;
        if (!is_dir($dir) || is_link($dir) || !is_readable($dir)) return ['s'=>0, 'f'=>0, 'd'=>0];
        $items = @scandir($dir);
        if ($items) {
            foreach ($items as $item) {
                if ($item[0] === '.' || $item === '..') continue;
                $full = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $item;
                if (is_link($full)) continue;
                if (is_file($full)) { $files++; $size += (float)@filesize($full); }
                elseif (is_dir($full)) {
                    $folders++;
                    if ($recursive) {
                        $sub = getFolderStats($full, true);
                        $size += $sub['s']; $files += $sub['f']; $folders += $sub['d'];
                    }
                }
            }
        }
        return ['s' => $size, 'f' => $files, 'd' => $folders];
    }
}

// 3. ACTION ROUTER
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'get_drives') {
        $drives = [['name' => 'Macintosh HD', 'path' => '/']];
        $volPath = '/Volumes';
        if (is_dir($volPath)) {
            $list = @scandir($volPath);
            if ($list) {
                foreach ($list as $v) {
                    if ($v[0] === '.' || $v === '..' || $v === 'Macintosh HD') continue;
                    $fullPath = $volPath . '/' . $v;
                    if (is_dir($fullPath)) $drives[] = ['name' => $v, 'path' => $fullPath];
                }
            }
        }
        echo json_encode($drives); exit;
    }

    if ($action === 'browse') {
        $p = $_GET['path']; $res = [];
        if (is_dir($p) && is_readable($p)) {
            $items = @scandir($p);
            foreach ($items ?: [] as $i) {
                if ($i[0] === '.' || $i === '..') continue;
                $f = rtrim($p, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $i;
                $res[] = ['name' => $i, 'path' => $f, 'is_dir' => is_dir($f)];
            }
        }
        echo json_encode($res); exit;
    }

    if ($action === 'scan') {
        $startTime = microtime(true);
        $path = $_GET['path'];
        $depthLimit = (int)$_GET['depthLimit'];
        $targets = [$path];
        try {
            $dir = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
            $filter = new RecursiveCallbackFilterIterator($dir, function ($current) {
                return $current->getFilename()[0] !== '.'; 
            });
            $it = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);
            $it->setMaxDepth($depthLimit - 1);
            foreach ($it as $fileinfo) {
                if ($fileinfo->isDir() && !$fileinfo->isLink()) $targets[] = $fileinfo->getPathname();
            }
        } catch (Exception $e) {}

        $results = [];
        foreach ($targets as $t) {
            $deep = getFolderStats($t, true);
            $local = getFolderStats($t, false);
            $rawParts = array_values(array_filter(explode('/', $t)));
            $levels = [];
            if (strpos($t, '/Volumes/') === 0) {
                $levels[0] = "/Volumes/";
                foreach ($rawParts as $idx => $p) { if ($idx > 0) $levels[$idx] = "/$p/"; }
            } else {
                $levels[0] = "/";
                foreach ($rawParts as $idx => $p) { $levels[$idx + 1] = "/$p/"; }
            }
            $results[] = [
                'hd' => getHDName($t), 'path' => $t, 'path_level' => count($levels) - 1, 'name' => basename($t),
                'total_size' => $deep['s'], 'total_files' => $deep['f'], 'total_subs' => $deep['d'],
                'here_size' => $local['s'], 'here_files' => $local['f'], 'here_folders' => $local['d'], 'levels' => $levels
            ];
        }
        echo json_encode(['data' => $results, 'time' => round(microtime(true) - $startTime, 2)]); exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Auditor Pro v8.5</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .folder { color: #2563eb; cursor: pointer; font-weight: 800; font-size: 11px; }
        th { font-size: 9px; background: #f8fafc; border: 1px solid #e2e8f0; padding: 6px; text-transform: uppercase; white-space: nowrap; position: sticky; top: 0; z-index: 10; }
        td { font-size: 10px; border: 1px solid #f1f5f9; padding: 4px; white-space: nowrap; }
        .lvl-cell { color: #64748b; font-family: monospace; font-size: 9px; border-left: 1px solid #e2e8f0; }
        .btn-tool { padding: 6px 12px; border-radius: 6px; font-weight: 900; font-size: 10px; text-transform: uppercase; transition: all 0.2s; }
        .branch { margin-left: 15px; border-left: 1px dashed #cbd5e1; }
        #treemapContainer { height: 300px; display: none; margin-bottom: 1rem; }
    </style>
</head>
<body class="bg-slate-50 p-4 h-screen flex flex-col">

    <div class="bg-white p-4 rounded-2xl shadow-sm border mb-4 flex flex-wrap justify-between items-center gap-4">
        <div class="flex items-center gap-4">
            <h1 class="text-xl font-black italic">AUDITOR<span class="text-blue-600">PRO v8.5</span></h1>
            <input type="text" id="matrixFilter" placeholder="Filter Results..." onkeyup="applyFilter()" class="bg-slate-100 border px-3 py-1 rounded-lg text-xs font-bold w-48 outline-none focus:ring-2 focus:ring-blue-500">
        </div>

        <div class="flex items-center gap-4">
            <div class="flex items-center gap-2 bg-slate-100 px-3 py-1 rounded-lg border">
                <span class="text-[9px] font-bold text-slate-500">ScanDepth:</span>
                <input type="number" id="depthInput" value="3" min="0" class="w-8 bg-transparent font-bold text-blue-600 outline-none">
            </div>
            <div id="statusBox" class="px-3 py-1 bg-slate-200 rounded-full text-[10px] font-black uppercase text-slate-500">Ready</div>
            <div class="flex gap-2">
                <button onclick="toggleView()" id="viewBtn" class="btn-tool bg-purple-600 text-white shadow-lg">View Pie Chart</button>
                <button onclick="loadDrives()" class="btn-tool bg-slate-200 text-slate-600 border">RefreshDrives</button>
                <button onclick="runAnalysis()" class="btn-tool bg-blue-600 text-white shadow-lg shadow-blue-200">StartScan</button>
                <button onclick="exportCSV()" class="btn-tool bg-slate-800 text-white">ExportCSV</button>
            </div>
        </div>
    </div>

<div id="treemapContainer" class="bg-white rounded-2xl border p-4 shadow-inner flex justify-center">
    <div style="width: 100px; height: 100px;">
        <canvas id="pieChartCanvas"></canvas>
    </div>
</div>

    <div class="grid grid-cols-12 gap-4 flex-grow overflow-hidden">
        <div class="col-span-12 lg:col-span-3 bg-white rounded-2xl border p-4 overflow-auto shadow-inner" id="treeRoot">
            <p class="text-[10px] text-slate-400 font-bold uppercase mb-2">Drive Selection</p>
        </div>
        <div class="col-span-12 lg:col-span-9 bg-white rounded-2xl border overflow-auto shadow-inner">
            <table class="w-full border-collapse" id="mainTable">
                <thead id="tableHead"><tr id="headerRow">
                    <th>SEQ</th><th>HDName</th><th>FolderName</th><th>PathLevel</th><th>FullPath</th>
                    <th class="bg-blue-50">TotalSizeGB</th><th class="bg-blue-50">FilesRecursive</th><th class="bg-blue-50">FoldersRecursive</th>
                    <th class="bg-emerald-50">SizeHere</th><th class="bg-emerald-50">FilesHere</th><th class="bg-emerald-50">FoldersHere</th>
                </tr></thead>
                <tbody id="tableBody"></tbody>
            </table>
        </div>
    </div>

    <script>
        let fullDataCache = [];
        let chartInstance = null;
        let isChartView = false;

        async function loadDrives() {
            const root = document.getElementById('treeRoot');
            root.innerHTML = '<p class="text-[10px] text-slate-400 font-bold uppercase mb-2">Loading...</p>';
            try {
                const res = await fetch('?action=get_drives');
                const drives = await res.json();
                root.innerHTML = '<p class="text-[10px] text-slate-400 font-bold uppercase mb-2">Drive Selection</p>';
                drives.forEach(d => buildNode(d, root, true));
            } catch (e) { root.innerHTML = '<p class="text-red-500 text-[10px]">Error loading drives.</p>'; }
        }

        function buildNode(item, parent, isDrive=false) {
            const div = document.createElement('div');
            div.className = "my-1";
            const safeId = btoa(item.path).replace(/[^a-zA-Z0-9]/g, '');
            div.innerHTML = `<div class="flex items-center gap-2">
                <input type="checkbox" class="audit-check" data-path="${item.path}">
                <span class="folder" onclick="expand('${item.path}', this)">${isDrive?'💽':'📁'} ${item.name}</span>
            </div><div id="branch-${safeId}" class="branch hidden"></div>`;
            parent.appendChild(div);
        }

        async function expand(path, el) {
            const safeId = btoa(path).replace(/[^a-zA-Z0-9]/g, '');
            const branch = document.getElementById(`branch-${safeId}`);
            if (!branch.classList.contains('hidden')) return branch.classList.add('hidden');
            if (branch.innerHTML === "") {
                const res = await fetch(`?action=browse&path=${encodeURIComponent(path)}`);
                const items = await res.json();
                items.forEach(i => buildNode(i, branch));
            }
            branch.classList.remove('hidden');
        }

        async function runAnalysis() {
            const checks = Array.from(document.querySelectorAll('.audit-check:checked'));
            if (!checks.length) return alert("Select folder!");
            const status = document.getElementById('statusBox');
            status.innerText = "Scanning..."; 
            status.className = "px-3 py-1 bg-amber-400 text-white rounded-full text-[10px] font-black uppercase animate-pulse";
            
            fullDataCache = [];
            for (const cb of checks) {
                try {
                    const res = await fetch(`?action=scan&path=${encodeURIComponent(cb.dataset.path)}&depthLimit=${document.getElementById('depthInput').value}`);
                    const json = await res.json();
                    fullDataCache = [...fullDataCache, ...json.data];
                } catch(e) {}
            }
            renderTable(fullDataCache);
            if (isChartView) updateChart();
            status.innerText = `Complete`;
            status.className = "px-3 py-1 bg-green-500 text-white rounded-full text-[10px] font-black uppercase";
        }

        function renderTable(data) {
            const body = document.getElementById('tableBody');
            const head = document.getElementById('headerRow');
            body.innerHTML = '';
            let maxLvlIndex = 0;
            data.forEach(r => { Object.keys(r.levels).forEach(k => { if(parseInt(k) > maxLvlIndex) maxLvlIndex = parseInt(k); })});
            while(head.cells.length > 11) head.deleteCell(11);
            for(let i=0; i <= maxLvlIndex; i++) {
                let th = document.createElement('th'); th.innerText = `Level${i}`; head.appendChild(th);
            }
            data.forEach((r, idx) => {
                let row = `<tr class="hover:bg-blue-50">
                    <td class="text-slate-400 font-mono text-center">${idx + 1}</td>
                    <td class="font-bold text-slate-700">${r.hd}</td>
                    <td class="font-bold text-blue-600">${r.name}</td>
                    <td class="text-center font-bold text-slate-500">${r.path_level}</td>
                    <td class="text-[9px] font-mono text-slate-400 max-w-[100px] truncate" title="${r.path}">${r.path}</td>
                    <td class="text-right font-black bg-blue-50/30">${(r.total_size / 1073741824).toFixed(3)}</td>
                    <td class="text-right">${r.total_files.toLocaleString()}</td>
                    <td class="text-right">${r.total_subs.toLocaleString()}</td>
                    <td class="text-right text-emerald-700 bg-emerald-50/30 font-bold">${formatSize(r.here_size)}</td>
                    <td class="text-right">${r.here_files.toLocaleString()}</td>
                    <td class="text-right">${r.here_folders.toLocaleString()}</td>`;
                for(let i=0; i <= maxLvlIndex; i++) row += `<td class="lvl-cell">${r.levels[i] || ''}</td>`;
                body.insertAdjacentHTML('beforeend', row + '</tr>');
            });
        }

        function toggleView() {
            isChartView = !isChartView;
            document.getElementById('treemapContainer').style.display = isChartView ? 'block' : 'none';
            document.getElementById('viewBtn').innerText = isChartView ? 'ViewMatrix' : 'ViewChart';
            if (isChartView) updateChart();
        }

function updateChart() {
    const ctx = document.getElementById('pieChartCanvas').getContext('2d');
    if (chartInstance) chartInstance.destroy();

    // 1. Aggregate data by path_level
    const levelStats = fullDataCache.reduce((acc, curr) => {
        const lvl = `Level ${curr.path_level}`;
        if (!acc[lvl]) acc[lvl] = 0;
        acc[lvl] += curr.total_size;
        return acc;
    }, {});

    const labels = Object.keys(levelStats).sort();
    const dataValues = labels.map(l => (levelStats[l] / 1073741824).toFixed(3)); // Convert to GB

    // 2. Create the Pie Chart
    chartInstance = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: labels,
            datasets: [{
                data: dataValues,
                backgroundColor: [
                    '#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', 
                    '#10b981', '#64748b', '#ef4444'
                ],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: function(item) {
                            return ` ${item.label}: ${item.raw} GB`;
                        }
                    }
                }
            }
        }
    });
}

        function formatSize(b) { if (!b || b === 0) return '0 B'; const i = Math.floor(Math.log(b) / Math.log(1024)); return (b / Math.pow(1024, i)).toFixed(2) * 1 + ' ' + ['B', 'KB', 'MB', 'GB', 'TB'][i]; }
        function applyFilter() { renderTable(fullDataCache.filter(r => r.name.toLowerCase().includes(document.getElementById('matrixFilter').value.toLowerCase()))); }
        function exportCSV() {
            let csv = [];
            document.querySelectorAll('#mainTable tr').forEach(row => {
                let rowText = []; row.querySelectorAll('th, td').forEach(col => rowText.push('"' + col.innerText.replace(/"/g, '""') + '"'));
                csv.push(rowText.join(","));
            });
            const blob = new Blob([csv.join("\n")], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a'); a.href = url; a.download = `audit_${Date.now()}.csv`; a.click();
        }

        window.onload = loadDrives;
    </script>
</body>
</html>