<?php
// 1. GLOBAL CONFIG & ERROR SUPPRESSION a
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
            // Disable following symlinks to prevent infinite loops
            $dir = new RecursiveDirectoryIterator($path, 
                RecursiveDirectoryIterator::SKIP_DOTS | 
                RecursiveDirectoryIterator::FOLLOW_SYMLINKS === false
            );
            
            $filter = new RecursiveCallbackFilterIterator($dir, function ($current) {
                $fn = $current->getFilename();
                // Avoid hidden files and system-level directories that cause permission hangs
                return $fn[0] !== '.' && !in_array($fn, ['dev', 'Volumes', 'Library', 'System']); 
            });

            $it = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);
            $it->setMaxDepth($depthLimit - 1);

            foreach ($it as $fileinfo) {
                if ($fileinfo->isDir() && !$fileinfo->isLink() && is_readable($fileinfo->getPathname())) {
                    $targets[] = $fileinfo->getPathname();
                }
                // Stop after 1000 folders to protect memory
                if (count($targets) > 1000) break; 
            }
        } catch (Exception $e) {}

        $results = [];
        foreach ($targets as $t) {
            // Use @ to suppress permission warnings that slow down the scan
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
   
            /* Remove height restrictions to allow the whole page to expand */
            body { 
                height: auto !important; 
                overflow-y: auto !important; 
                display: block !important; /* Disables flex-col which can trap height */
            }

            /* Ensure the sticky header works with the window scrollbar */
            th { 
                position: sticky; 
                top: 0; 
                background: #f8fafc; 
                z-index: 50; 
                box-shadow: 0 1px 2px rgba(0,0,0,0.1); 
            }

            /* Adjust the chart container to a comfortable viewing size */
            #treemapContainer { 
                height: 500px; 
                margin-bottom: 2rem; 
            }
   
   
    </style>
</head>

<!-- <body class="bg-slate-50 p-4 h-screen flex flex-col"> -->

<body class="bg-slate-50 p-4"> 
    


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




<div id="treemapContainer" class="bg-white rounded-2xl border p-4 shadow-sm" style="display: none;">
    <!--
<div id="treemapContainer" class="bg-white rounded-2xl border p-4 shadow-sm hidden">
        -->

        <canvas id="pieChartCanvas"></canvas>
    </div>

    <div class="grid grid-cols-12 gap-4">
        <div class="col-span-12 lg:col-span-3 bg-white rounded-2xl border p-4 shadow-sm h-fit">
            <div id="treeRoot"></div>
        </div>
        <div class="col-span-12 lg:col-span-9 bg-white rounded-2xl border shadow-sm">
            <div class="overflow-x-auto"> <table class="w-full border-collapse" id="mainTable">
                 
            
            
            
            
           
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
            
            if(!data || data.length === 0) return;

            let maxLvlIndex = 0;
            data.forEach(r => { 
                Object.keys(r.levels).forEach(k => { if(parseInt(k) > maxLvlIndex) maxLvlIndex = parseInt(k); })
            });

            // Clean up headers
            while(head.cells.length > 11) head.deleteCell(11);
            for(let i=0; i <= maxLvlIndex; i++) {
                let th = document.createElement('th'); th.innerText = `Level${i}`; head.appendChild(th);
            }

            // Correctly build rows
            data.forEach((r, idx) => {
                let rowHtml = `<tr class="hover:bg-blue-50">
                    <td class="text-slate-400 font-mono text-center">${idx + 1}</td>
                    <td class="font-bold text-slate-700">${r.hd}</td>
                    <td class="font-bold text-blue-600 cursor-pointer" onclick="updateChart('${r.path}')">${r.name}</td>
                    <td class="text-center font-bold text-slate-500">${r.path_level}</td>
                    <td class="text-[9px] font-mono text-slate-400 max-w-[100px] truncate" title="${r.path}">${r.path}</td>
                    <td class="text-right font-black bg-blue-50/30">${(r.total_size / 1073741824).toFixed(3)}</td>
                    <td class="text-right">${r.total_files.toLocaleString()}</td>
                    <td class="text-right">${r.total_subs.toLocaleString()}</td>
                    <td class="text-right text-emerald-700 bg-emerald-50/30 font-bold">${formatSize(r.here_size)}</td>
                    <td class="text-right">${r.here_files.toLocaleString()}</td>
                    <td class="text-right">${r.here_folders.toLocaleString()}</td>`;

                // Add level cells that update the chart title
                for(let i=0; i <= maxLvlIndex; i++) {
                    const lvlVal = r.levels[i] || '';
                    rowHtml += `<td class="lvl-cell cursor-pointer hover:bg-blue-100" onclick="updateChart('${r.path}')">${lvlVal}</td>`;
                }
                body.insertAdjacentHTML('beforeend', rowHtml + '</tr>');
            });
        }

        function toggleView() {
    isChartView = !isChartView;
    const container = document.getElementById('treemapContainer');
    const btn = document.getElementById('viewBtn');
    
    // Toggle visibility
    container.style.display = isChartView ? 'block' : 'none';
    
    // Toggle button text
    btn.innerText = isChartView ? 'Show Table' : 'View Pie Chart';
    
    // Trigger update if we are switching TO chart view
    if (isChartView) {
        updateChart();
    }
}

let currentParentPath = null; // Tracks which folder we are currently "viewing"

function updateChart(focusPath = null) {
            const ctx = document.getElementById('pieChartCanvas').getContext('2d');
            if (chartInstance) chartInstance.destroy();
            if (!fullDataCache.length) return;

            // 1. Find the Parent (Title Source)
            const parentRow = focusPath 
                ? fullDataCache.find(r => r.path === focusPath)
                : fullDataCache[0];

            if (!parentRow) return;

            // 2. Build Title from HD and ALL levels
            const levelString = Object.values(parentRow.levels).join(" ");
            const chartTitle = `${parentRow.hd} | ${levelString}`;

            // 3. Find Children (Level + 1)
            const parentLevel = parseInt(parentRow.path_level);
            const children = fullDataCache.filter(r => {
                return parseInt(r.path_level) === (parentLevel + 1) && r.path.startsWith(parentRow.path);
            });

            const labels = children.map(r => r.name);
            const dataValues = children.map(r => (r.total_size / 1073741824).toFixed(3));

            chartInstance = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        data: dataValues,
                        backgroundColor: ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0891b2', '#4f46e5', '#6366f1'],
                        borderWidth: 1
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: {
                        title: { display: true, text: chartTitle, font: { size: 12, weight: '900' } },
                        legend: { position: 'right', labels: { boxWidth: 12, font: { size: 10 } } }
                    },
                    onClick: (event, elements) => {
                        if (elements.length > 0) {
                            updateChart(children[elements[0].index].path);
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




async function runAnalysis() {
            const checks = Array.from(document.querySelectorAll('.audit-check:checked'));
            if (!checks.length) return alert("Select folder!");
            const status = document.getElementById('statusBox');
            
            status.innerText = "Scanning..."; 
            status.className = "px-3 py-1 bg-amber-400 text-white rounded-full text-[10px] font-black uppercase animate-pulse";
            
            fullDataCache = [];
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 30000); // 30 second timeout

            for (const cb of checks) {
                try {
                    const res = await fetch(`?action=scan&path=${encodeURIComponent(cb.dataset.path)}&depthLimit=${document.getElementById('depthInput').value}`, { signal: controller.signal });
                    const json = await res.json();
                    fullDataCache = [...fullDataCache, ...json.data];
                } catch(e) {
                    console.error("Scan failed or timed out", e);
                    status.innerText = "Error / Timeout";
                    status.className = "px-3 py-1 bg-red-500 text-white rounded-full text-[10px]";
                }
            }
            clearTimeout(timeoutId);
            renderTable(fullDataCache);
            if (isChartView) updateChart();
            if (status.innerText !== "Error / Timeout") {
                status.innerText = `Complete`;
                status.className = "px-3 py-1 bg-green-500 text-white rounded-full text-[10px] font-black uppercase";
            }
        }




        window.onload = loadDrives;
    </script>
</body>
</html>