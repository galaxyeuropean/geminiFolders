<?php
// 1. GLOBAL CONFIG
ini_set('memory_limit', '1024M');
error_reporting(0); 

// 2. CORE FUNCTIONS
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
            foreach ($list ?: [] as $v) {
                if ($v[0] === '.' || $v === '..' || $v === 'Macintosh HD') continue;
                $fullPath = $volPath . '/' . $v;
                if (is_dir($fullPath)) $drives[] = ['name' => $v, 'path' => $fullPath];
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
            $dir = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::UNIX_PATHS);
            $filter = new RecursiveCallbackFilterIterator($dir, function ($current) {
                $fn = $current->getFilename();
                // Block system folders that cause permission-based hangs
                $blocked = ['Library', 'System', 'Volumes', 'dev', 'proc'];
                return $fn[0] !== '.' && !in_array($fn, $blocked); 
            });

            $it = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);
            $it->setMaxDepth($depthLimit - 1);

            foreach ($it as $fileinfo) {
                if ($fileinfo->isDir() && !$fileinfo->isLink() && is_readable($fileinfo->getPathname())) {
                    $targets[] = $fileinfo->getPathname();
                }
                if (count($targets) > 1000) break; 
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
        body { height: auto; min-height: 100vh; overflow-y: scroll; background: #f8fafc; padding: 20px; }
        .folder { color: #2563eb; cursor: pointer; font-weight: 800; font-size: 11px; }
        th { font-size: 9px; background: #f8fafc; border: 1px solid #e2e8f0; padding: 6px; position: sticky; top: 0; z-index: 50; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        td { font-size: 10px; border: 1px solid #f1f5f9; padding: 4px; white-space: nowrap; }
        .lvl-cell { color: #64748b; font-family: monospace; font-size: 9px; border-left: 1px solid #e2e8f0; cursor: pointer; }
        .lvl-cell:hover { background: #eff6ff; color: #2563eb; }
        .btn-tool { padding: 6px 12px; border-radius: 6px; font-weight: 900; font-size: 10px; text-transform: uppercase; transition: all 0.2s; }
        .branch { margin-left: 15px; border-left: 1px dashed #cbd5e1; }
        #treemapContainer { height: 500px; display: none; margin-bottom: 2rem; background: white; border-radius: 1rem; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
    </style>
</head>
<body> 
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

    <div id="treemapContainer" class="bg-white rounded-2xl border p-4 shadow-sm mb-8" style="display: none;">
    <div class="flex justify-between items-center mb-2">
        <div id="breadcrumb" class="text-[10px] font-bold text-slate-500 uppercase"></div>
        <div class="flex gap-2">
            <button onclick="goBackChart()" class="btn-tool bg-slate-100 text-slate-600 border px-2 py-1">← Back</button>
            <button onclick="updateChart()" class="btn-tool bg-slate-100 text-slate-600 border px-2 py-1">⟲ Top</button>
        </div>
    </div>
    
    <div class="flex flex-row gap-4 h-[400px]">
        <div style="width: 70%; position: relative;">
            <canvas id="pieChartCanvas"></canvas>
        </div>
        
        <div style="width: 30%; position: relative;" class="border-l pl-4">
            <canvas id="stackedBarCanvas"></canvas>
        </div>
    </div>
</div>

    <div class="grid grid-cols-12 gap-4">
        <div class="col-span-12 lg:col-span-3 bg-white rounded-2xl border p-4 shadow-sm h-fit">
            <div id="treeRoot"></div>
        </div>
        <div class="col-span-12 lg:col-span-9 bg-white rounded-2xl border shadow-sm overflow-x-auto">
            <table class="w-full border-collapse" id="mainTable">
                <thead id="tableHead">
                    <tr id="headerRow">
                        <th>SEQ</th><th>HDName</th><th>FolderName</th><th>PathLevel</th><th>FullPath</th>
                        <th class="bg-blue-50">TotalSizeGB</th><th class="bg-blue-50">FilesRecursive</th><th class="bg-blue-50">FoldersRecursive</th>
                        <th class="bg-emerald-50">SizeHere</th><th class="bg-emerald-50">FilesHere</th><th class="bg-emerald-50">FoldersHere</th>
                    </tr>
                </thead>
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
            root.innerHTML = 'Loading...';
            const res = await fetch('?action=get_drives');
            const drives = await res.json();
            root.innerHTML = '<p class="text-[10px] text-slate-400 font-bold uppercase mb-2">Drive Selection</p>';
            drives.forEach(d => buildNode(d, root, true));
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
            status.innerText = "Scanning..."; status.classList.add('animate-pulse');
            
            fullDataCache = [];
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 45000); 

            for (const cb of checks) {
                try {
                    const res = await fetch(`?action=scan&path=${encodeURIComponent(cb.dataset.path)}&depthLimit=${document.getElementById('depthInput').value}`, { signal: controller.signal });
                    const json = await res.json();
                    fullDataCache = [...fullDataCache, ...json.data];
                } catch(e) { status.innerText = "Timeout/Error"; }
            }
            clearTimeout(timeoutId);
            renderTable(fullDataCache);
            if (isChartView) updateChart();
            status.innerText = `Complete`; status.classList.remove('animate-pulse');
        }

        function renderTable(data) {
            const body = document.getElementById('tableBody');
            const head = document.getElementById('headerRow');
            body.innerHTML = '';
            let maxLvlIndex = 0;
            data.forEach(r => { Object.keys(r.levels).forEach(k => { if(parseInt(k) > maxLvlIndex) maxLvlIndex = parseInt(k); })});
            while(head.cells.length > 11) head.deleteCell(11);
            for(let i=0; i <= maxLvlIndex; i++) { let th = document.createElement('th'); th.innerText = `Level${i}`; head.appendChild(th); }

            data.forEach((r, idx) => {
                let row = `<tr class="hover:bg-blue-50">
                    <td class="text-slate-400 font-mono text-center">${idx + 1}</td>
                    <td>${r.hd}</td>
                    <td class="font-bold text-blue-600 cursor-pointer" onclick="updateChart('${r.path}')">${r.name}</td>
                    <td class="text-center">${r.path_level}</td>
                    <td class="text-[9px] font-mono truncate max-w-[100px]" title="${r.path}">${r.path}</td>
                    <td class="text-right font-black">${(r.total_size / 1073741824).toFixed(3)}</td>
                    <td class="text-right">${r.total_files.toLocaleString()}</td>
                    <td class="text-right">${r.total_subs.toLocaleString()}</td>
                    <td class="text-right text-emerald-700">${formatSize(r.here_size)}</td>
                    <td class="text-right">${r.here_files.toLocaleString()}</td>
                    <td class="text-right">${r.here_folders.toLocaleString()}</td>`;
                for(let i=0; i <= maxLvlIndex; i++) {
                    row += `<td class="lvl-cell" onclick="updateChart('${r.path}')">${r.levels[i] || ''}</td>`;
                }
                body.insertAdjacentHTML('beforeend', row + '</tr>');
            });
        }

        function toggleView() {
            isChartView = !isChartView;
            document.getElementById('treemapContainer').style.display = isChartView ? 'block' : 'none';
            document.getElementById('viewBtn').innerText = isChartView ? 'View Table' : 'View Pie Chart';
            if (isChartView) updateChart();
        }

        let chartHistory = [];
let barChartInstance = null;

function updateChart(focusPath = null) {
    const ctxPie = document.getElementById('pieChartCanvas').getContext('2d');
    const ctxBar = document.getElementById('stackedBarCanvas').getContext('2d');
    
    if (chartInstance) chartInstance.destroy();
    if (barChartInstance) barChartInstance.destroy();
    if (!fullDataCache.length) return;

    // 1. Resolve the Current Parent Row
    const parentRow = focusPath ? fullDataCache.find(r => r.path === focusPath) : fullDataCache[0];
    if (!parentRow) return;

    const maxScanDepth = parseInt(document.getElementById('depthInput').value);
    const currentLevel = parseInt(parentRow.path_level);

    // 2. Filter Subfolders (The slices)
    const children = fullDataCache.filter(r => 
        parseInt(r.path_level) === (currentLevel + 1) && r.path.startsWith(parentRow.path)
    );

    // 3. Construct Data Arrays
    let labels = children.map(r => r.name);
    let values = children.map(r => (r.total_size / 1073741824).toFixed(3));
    
    // Append Loose Files from "SizeHere" column of the Parent
    const looseFilesGB = (parentRow.here_size / 1073741824).toFixed(3);
    if (parseFloat(looseFilesGB) > 0) {
        labels.push("📁 (Files in this folder)");
        values.push(looseFilesGB);
    }

    // Professional Color Palette
    const colors = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0891b2', '#4f46e5', '#6366f1', '#facc15', '#94a3b8'];

    // 4. Render 70% Pie Chart
    chartInstance = new Chart(ctxPie, {
        type: 'pie',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: colors,
                hoverOffset: 20 // Slices pop out on hover
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                title: { 
                    display: true, 
                    text: `${parentRow.hd} | ${Object.values(parentRow.levels).join(' ')}`,
                    font: { size: 13, weight: '900' }
                },
                legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 9 } } }
            },
            onClick: (e, el) => {
                if (el.length > 0) {
                    const index = el[0].index;
                    // Only drill down if it's a folder (index is within 'children' array)
                    if (index < children.length && currentLevel < maxScanDepth) {
                        chartHistory.push(parentRow.path);
                        updateChart(children[index].path);
                    }
                }
            }
        }
    });

    // 5. Render 30% Stacked Bar (Single matched bar)
    const barDatasets = labels.map((label, i) => ({
        label: label,
        data: [values[i]],
        backgroundColor: colors[i % colors.length],
        barThickness: 80
    }));

    barChartInstance = new Chart(ctxBar, {
        type: 'bar',
        data: {
            labels: ['Folder Composition'],
            datasets: barDatasets
        },
        options: {
            indexAxis: 'x',
            maintainAspectRatio: false,
            scales: { 
                x: { stacked: true, display: false }, 
                y: { stacked: true, beginAtZero: true, title: { display: true, text: 'GB', font: {size: 10} } } 
            },
            plugins: { legend: { display: false } }
        }
    });
}

// Migration Back Up logic
function goBackChart() {
    if (chartHistory.length > 0) {
        const lastPath = chartHistory.pop();
        updateChart(lastPath);
    }
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