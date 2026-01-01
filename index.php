<?php
// 1. GLOBAL CONFIG & SYSTEM BYPASS
set_time_limit(0); 
ini_set('memory_limit', '2048M');
error_reporting(0); 

// 2. PROTECTED FUNCTIONS
if (!function_exists('getHDName')) {
    function getHDName($path) {
        if ($path === '/' || strpos($path, '/Volumes/') === false) return "Macintosh HD";
        $parts = explode('/', trim($path, '/'));
        return ($parts[0] === 'Volumes' && isset($parts[1])) ? $parts[1] : "Macintosh HD";
    }
}

if (!function_exists('getFolderStats')) {
    function getFolderStats($dir, $recursive = true) {
        // Server-side check for runaway/aborted connection
        if (connection_aborted()) exit; 
        
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
        $driveList = [['name' => 'Macintosh HD', 'path' => '/']];
        $volPath = '/Volumes';
        if (is_dir($volPath)) {
            $list = @scandir($volPath);
            foreach ($list ?: [] as $v) {
                if ($v[0] === '.' || $v === '..' || $v === 'Macintosh HD') continue;
                $fullPath = $volPath . '/' . $v;
                if (is_dir($fullPath)) $driveList[] = ['name' => $v, 'path' => $fullPath];
            }
        }

        $drives = [];
        foreach ($driveList as $d) {
            $total = @disk_total_space($d['path']) ?: 0;
            $free = @disk_free_space($d['path']) ?: 0;
            $used = $total - $free;
            $percent = ($total > 0) ? round(($used / $total) * 100, 1) : 0;

            $drives[] = [
                'name' => $d['name'],
                'path' => $d['path'],
                'total' => $total,
                'used' => $used,
                'free' => $free,
                'percent' => $percent
            ];
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
        $path = $_GET['path'];
        $depthLimit = (int)$_GET['depthLimit'];
        $targets = [$path];
        
        try {
            $dir = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::UNIX_PATHS);
            $filter = new RecursiveCallbackFilterIterator($dir, function ($current) {
                $fn = $current->getFilename();
                $blocked = ['Library', 'System', 'Volumes', 'dev', 'proc', '.Trash', 'node_modules'];
                return $fn[0] !== '.' && !in_array($fn, $blocked); 
            });
            $it = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);
            $it->setMaxDepth($depthLimit - 1);
            foreach ($it as $fileinfo) {
                if ($fileinfo->isDir() && !$fileinfo->isLink() && is_readable($fileinfo->getPathname())) {
                    $targets[] = $fileinfo->getPathname();
                }
                if (count($targets) > 3000) break; 
            }
        } catch (Exception $e) {}

        $results = [];
        foreach ($targets as $t) {
            if (connection_aborted()) exit;
            $deep = getFolderStats($t, true);
            $local = getFolderStats($t, false);
            $parts = array_values(array_filter(explode('/', $t)));
            $levels = [0 => "/"];
            foreach ($parts as $idx => $p) { $levels[$idx + 1] = $p . "/"; }

            $results[] = [
                'hd' => getHDName($t), 'path' => $t, 'path_level' => count($levels) - 1, 'name' => basename($t),
                'total_size' => $deep['s'], 'total_files' => $deep['f'], 'total_subs' => $deep['d'],
                'here_size' => $local['s'], 'here_files' => $local['f'], 'here_folders' => $local['d'], 'levels' => $levels
            ];
        }
        echo json_encode(['data' => $results]); exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Auditor Pro v8.6</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f1f5f9; padding: 20px; font-family: ui-sans-serif, system-ui; }
        .folder { color: #2563eb; cursor: pointer; font-weight: 800; font-size: 11px; }
        th { font-size: 9px; background: #f8fafc; border: 1px solid #e2e8f0; padding: 6px; position: sticky; top: 0; z-index: 50; text-transform: uppercase; }
        td { font-size: 10px; border: 1px solid #f1f5f9; padding: 4px; white-space: nowrap; }
        .lvl-cell { color: #64748b; font-family: monospace; font-size: 9px; border-left: 1px solid #e2e8f0; cursor: pointer; }
        .lvl-cell:hover { background: #eff6ff; color: #2563eb; }
        .btn-tool { padding: 6px 12px; border-radius: 6px; font-weight: 900; font-size: 10px; text-transform: uppercase; border: 1px solid transparent; cursor: pointer; transition: all 0.1s; }
        .btn-tool:active { transform: scale(0.95); }
        .branch { margin-left: 15px; border-left: 1px dashed #cbd5e1; }
        #treemapContainer { display: none; margin-bottom: 2rem; background: white; border-radius: 1rem; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        input[type="range"] { height: 6px; appearance: none; background: #cbd5e1; border-radius: 5px; }
    </style>
</head>
<body> 

    <div class="bg-white p-4 rounded-2xl shadow-sm border mb-4 flex flex-wrap justify-between items-center gap-4">
        <div class="flex items-center gap-4">
            <h1 class="text-xl font-black italic tracking-tighter">AUDITOR<span class="text-blue-600">PRO v8.6</span></h1>
            <input type="text" id="matrixFilter" placeholder="Filter path..." onkeyup="applyFilter()" class="bg-slate-100 border px-3 py-1.5 rounded-lg text-xs font-bold w-48 outline-none focus:ring-2 focus:ring-blue-500">
            
            <div class="flex flex-col gap-1">
                <span class="text-[8px] font-black text-slate-400 uppercase leading-none">Scan Depth: <span id="depthVal" class="text-blue-600">3</span></span>
                <input type="range" id="depthInput" min="1" max="10" value="3" oninput="document.getElementById('depthVal').innerText=this.value">
            </div>

            <div class="flex flex-col gap-1">
                <span class="text-[8px] font-black text-slate-400 uppercase leading-none">Timeout: <span id="timeVal" class="text-blue-600">30</span>s</span>
                <input type="range" id="timeoutInput" min="5" max="300" step="5" value="30" oninput="document.getElementById('timeVal').innerText=this.value">
            </div>
        </div>

        <div class="flex gap-2">

        <button onclick="showDriveReport()" class="btn-tool bg-slate-100 border border-slate-300 text-slate-700 hover:bg-white">💾 Disk Health</button>

            <div id="statusBox" class="px-3 py-1.5 bg-slate-200 rounded-full text-[10px] font-black uppercase text-slate-500">Idle</div>
            <button onclick="toggleView()" id="viewBtn" class="btn-tool bg-purple-600 text-white shadow-md">Analytics</button>
            <button id="startBtn" onclick="runAnalysis()" class="btn-tool bg-blue-600 text-white shadow-md">Start Scan</button>
            <button id="stopBtn" onclick="stopAnalysis()" class="btn-tool bg-red-500 text-white shadow-md hidden">Stop</button>
            <button onclick="copyForExcel()" class="btn-tool bg-emerald-600 text-white shadow-md">Copy for Sheets</button>
            <button onclick="exportCSV()" class="btn-tool bg-slate-800 text-white">CSV</button>
        </div>
    </div>



<div id="driveReport" class="hidden bg-white p-6 rounded-2xl border shadow-lg mb-4 animate-in fade-in duration-300">
    <!-- 
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-sm font-black uppercase tracking-widest text-slate-800">System Storage Overview</h2>
        <button onclick="document.getElementById('driveReport').classList.add('hidden')" class="text-slate-400 hover:text-red-500">✕</button>
    </div>
    -->

    <div class="flex justify-between items-center mb-4">
    <div class="flex items-center gap-4">
        <h2 class="text-sm font-black uppercase tracking-widest text-slate-800">System Storage Overview</h2>
        <div class="flex items-center gap-2 border-l pl-4">
            <span class="text-[9px] font-bold text-slate-400">SQUISH</span>
            <input type="range" id="rowSquish" min="1/5" max="10" value="2" 
                   oninput="updateDriveDensity(this.value)" class="w-24">
        </div>
    </div>
    <button onclick="document.getElementById('driveReport').classList.add('hidden')" class="text-slate-400 hover:text-red-500">✕</button>
</div>

    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
    <tr class="text-[8px] uppercase text-slate-400 border-b tracking-widest">
        <th class="pb-1 cursor-pointer hover:text-blue-600" onclick="sortDrives('total')">Total Size ↕</th>
        <th class="pb-1">Scale</th>
        <th class="pb-1 cursor-pointer hover:text-blue-600" onclick="sortDrives('name')">Drive Name ↕</th>
        <th class="pb-1 cursor-pointer hover:text-blue-600" onclick="sortDrives('percent')">Usage ↕</th>
        <th class="pb-1 text-right cursor-pointer hover:text-blue-600" onclick="sortDrives('free')">Available ↕</th>
    </tr>
</thead>
            <tbody id="driveTableBody"></tbody>
        </table>
    </div>
</div>







    <div id="treemapContainer">
        <div class="flex justify-between items-center mb-4 border-b pb-3">
            <div id="breadcrumb" class="text-[10px] font-black text-slate-500 truncate max-w-[70%] uppercase tracking-widest">Root</div>
            <div class="flex gap-2">
                <button onclick="goBackChart()" class="btn-tool bg-white border border-slate-200 text-slate-600">← Back</button>
                <button onclick="updateChart(null)" class="btn-tool bg-blue-50 border border-blue-100 text-blue-600">⟲ Top</button>
            </div>
        </div>
        <div class="flex flex-row gap-6 h-[420px]">
            <div style="width: 70%;" class="relative"><canvas id="pieChartCanvas"></canvas></div>
            <div style="width: 30%;" class="border-l border-slate-100 pl-4 relative"><canvas id="stackedBarCanvas"></canvas></div>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-4">
        <div class="col-span-12 lg:col-span-3 bg-white rounded-2xl border p-4 shadow-sm h-fit">
            <div id="treeRoot" class="max-h-[600px] overflow-y-auto"></div>
        </div>
        <div class="col-span-12 lg:col-span-9 bg-white rounded-2xl border shadow-sm overflow-hidden">
            <div class="overflow-x-auto max-h-[800px] overflow-y-auto">
                <table class="w-full border-collapse" id="mainTable">
                    <thead id="tableHead"><tr id="headerRow">
                        <th>#</th><th>HD</th><th>Folder</th><th>Lvl</th><th>Path</th>
                        <th class="bg-blue-50">Total GB</th><th class="bg-blue-50">Files(R)</th><th class="bg-blue-50">Dirs(R)</th>
                        <th class="bg-emerald-50 text-emerald-700">Size Here</th><th class="bg-emerald-50">Files</th><th class="bg-emerald-50">Dirs</th>
                    </tr></thead>
                    <tbody id="tableBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        let fullDataCache = [];
        let chartInstance = null, barChartInstance = null;
        let isChartView = false, chartHistory = [];
        let abortController = null;

        async function loadDrives() {
            const root = document.getElementById('treeRoot');
            const res = await fetch('?action=get_drives');
            const drives = await res.json();
            root.innerHTML = '';
            drives.forEach(d => buildNode(d, root, true));
        }

        function buildNode(item, parent, isDrive=false) {
            const div = document.createElement('div');
            div.className = "my-1";
            const safeId = btoa(item.path).replace(/[^a-zA-Z0-9]/g, '');
            div.innerHTML = `<div class="flex items-center gap-2">
                <input type="checkbox" class="audit-check w-3 h-3 rounded" data-path="${item.path}">
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
            
            // Toggle Buttons
            document.getElementById('startBtn').classList.add('hidden');
            document.getElementById('stopBtn').classList.remove('hidden');
            document.getElementById('statusBox').innerText = "Scanning...";
            
            abortController = new AbortController();
            const timeoutVal = parseInt(document.getElementById('timeoutInput').value) * 1000;
            const signal = abortController.signal;

            // Timeout Logic
            const timeoutId = setTimeout(() => { if(abortController) abortController.abort(); }, timeoutVal);

            fullDataCache = [];
            try {
                for (const cb of checks) {
                    const depth = document.getElementById('depthInput').value;
                    const res = await fetch(`?action=scan&path=${encodeURIComponent(cb.dataset.path)}&depthLimit=${depth}`, { signal });
                    const json = await res.json();
                    if(json.data) fullDataCache = [...fullDataCache, ...json.data];
                }
                applyFilter();
                document.getElementById('statusBox').innerText = "Done";
            } catch (err) {
                if (err.name === 'AbortError') {
                    document.getElementById('statusBox').innerText = "Stopped/Timeout";
                } else {
                    document.getElementById('statusBox').innerText = "Error";
                }
            } finally {
                clearTimeout(timeoutId);
                resetButtons();
            }
        }

        function stopAnalysis() {
            if (abortController) {
                abortController.abort();
                resetButtons();
            }
        }

        function resetButtons() {
            document.getElementById('startBtn').classList.remove('hidden');
            document.getElementById('stopBtn').classList.add('hidden');
            abortController = null;
        }

        function renderTable(data) {
            const body = document.getElementById('tableBody');
            const head = document.getElementById('headerRow');
            body.innerHTML = '';
            if(!data.length) return;

            let maxLvlIndex = 0;
            data.forEach(r => { Object.keys(r.levels).forEach(k => { if(parseInt(k) > maxLvlIndex) maxLvlIndex = parseInt(k); })});
            while(head.cells.length > 11) head.deleteCell(11);
            for(let i=0; i <= maxLvlIndex; i++) { 
                let th = document.createElement('th'); th.innerText = `Lv${i}`; head.appendChild(th); 
            }

            data.forEach((r, idx) => {
                let row = `<tr class="hover:bg-blue-50/50">
                    <td>${idx + 1}</td><td>${r.hd}</td>
                    <td class="font-bold text-blue-600 cursor-pointer" onclick="updateChart('${r.path}')">${r.name}</td>
                    <td class="text-center font-bold text-slate-400">${r.path_level}</td>
                    <td class="text-[9px] text-slate-400 truncate max-w-[150px]" onclick="navigator.clipboard.writeText('${r.path}')">${r.path}</td>
                    <td class="text-right font-black text-blue-700 bg-blue-50/30">${(r.total_size / 1073741824).toFixed(3)}</td>
                    <td class="text-right">${r.total_files}</td><td class="text-right">${r.total_subs}</td>
                    <td class="text-right text-emerald-700 bg-emerald-50/30 font-bold">${formatSize(r.here_size)}</td>
                    <td class="text-right">${r.here_files}</td><td class="text-right">${r.here_folders}</td>`;
                for(let i=0; i <= maxLvlIndex; i++) {
                    row += `<td class="lvl-cell" onclick="updateChart('${r.path}')">${r.levels[i] || ''}</td>`;
                }
                body.insertAdjacentHTML('beforeend', row + '</tr>');
            });
        }

        function updateChart(focusPath = null) {
            if (!isChartView || !fullDataCache.length) return;
            const filter = document.getElementById('matrixFilter').value.toLowerCase();
            const activeData = fullDataCache.filter(r => r.path.toLowerCase().includes(filter));
            const parentRow = focusPath ? activeData.find(r => r.path === focusPath) : activeData[0];
            if (!parentRow) return;

            const children = activeData.filter(r => parseInt(r.path_level) === (parseInt(parentRow.path_level) + 1) && r.path.startsWith(parentRow.path));
            let labels = children.map(r => r.name);
            let values = children.map(r => (r.total_size / 1073741824).toFixed(3));
            const looseGB = (parentRow.here_size / 1073741824).toFixed(3);
            if (parseFloat(looseGB) > 0) { labels.push("📁 (Loose Files)"); values.push(looseGB); }

            if (!values.length) { alert("No sub-content."); return; }

            if (chartInstance) chartInstance.destroy();
            if (barChartInstance) barChartInstance.destroy();

            document.getElementById('breadcrumb').innerText = parentRow.path;
            const colors = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0891b2', '#4f46e5', '#6366f1', '#facc15', '#94a3b8'];

            chartInstance = new Chart(document.getElementById('pieChartCanvas'), {
                type: 'pie',
                data: { labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }] },
                options: { maintainAspectRatio: false, onClick: (e, el) => {
                    if (el.length > 0 && el[0].index < children.length) {
                        chartHistory.push(parentRow.path);
                        updateChart(children[el[0].index].path);
                    }
                }}
            });

            barChartInstance = new Chart(document.getElementById('stackedBarCanvas'), {
                type: 'bar',
                data: { labels: ['Composition'], datasets: labels.map((l, i) => ({ label: l, data: [values[i]], backgroundColor: colors[i % colors.length], barThickness: 50 })) },
                options: { indexAxis: 'x', maintainAspectRatio: false, scales: { x: { stacked: true, display: false }, y: { stacked: true } }, plugins: { legend: { display: false } } }
            });
        }

        function goBackChart() { if (chartHistory.length) updateChart(chartHistory.pop()); }
        function toggleView() { isChartView = !isChartView; document.getElementById('treemapContainer').style.display = isChartView ? 'block' : 'none'; updateChart(); }
        function applyFilter() { renderTable(fullDataCache.filter(r => r.path.toLowerCase().includes(document.getElementById('matrixFilter').value.toLowerCase()))); }
        function formatSize(b) { if (!b || b === 0) return '0 B'; const i = Math.floor(Math.log(b) / Math.log(1024)); return (b / Math.pow(1024, i)).toFixed(2) * 1 + ' ' + ['B', 'KB', 'MB', 'GB', 'TB'][i]; }

        function copyForExcel() {
    if (!fullDataCache.length) return alert("No data to copy!");

    let maxLvlIndex = 0;
    fullDataCache.forEach(r => {
        Object.keys(r.levels).forEach(k => {
            if (parseInt(k) > maxLvlIndex) maxLvlIndex = parseInt(k);
        });
    });

    // Build Header
    let tsv = "HD\tFolder\tLvl\tPath\tTotal GB\tFiles(R)\tDirs(R)\tSize Here\tFiles\tDirs";
    for (let i = 0; i <= maxLvlIndex; i++) { tsv += `\tLv${i}`; }
    tsv += "\n";

    fullDataCache.forEach(r => {
        // Apply /Value/ formatting to text columns
        let row = [
            `/${r.hd}/`, 
            `/${r.name}/`, 
            r.path_level, 
            `/${r.path.replace(/^\/|\/$/g, '')}/`, // Ensure single wrapping /
            (r.total_size / 1073741824).toFixed(3),
            r.total_files, 
            r.total_subs,
            formatSize(r.here_size), 
            r.here_files, 
            r.here_folders
        ];
        
        for (let i = 0; i <= maxLvlIndex; i++) {
            let val = r.levels[i] ? r.levels[i].replace(/^\/|\/$/g, '') : '';
            row.push(`/${val}/`);
        }
        tsv += row.join("\t") + "\n";
    });

    navigator.clipboard.writeText(tsv).then(() => alert("Copied with /Text/ formatting."));
}

function exportCSV() {
    if (!fullDataCache.length) return alert("No data to export!");

    let maxLvlIndex = 0;
    fullDataCache.forEach(r => {
        Object.keys(r.levels).forEach(k => {
            if (parseInt(k) > maxLvlIndex) maxLvlIndex = parseInt(k);
        });
    });

    let csv = "HD,Folder,Lvl,Path,Total GB,Files_R,Dirs_R,Size_Here,Files,Dirs";
    for (let i = 0; i <= maxLvlIndex; i++) { csv += `,Lv${i}`; }
    csv += "\n";

    fullDataCache.forEach(r => {
        // Apply /Value/ formatting and CSV quotes
        let row = [
            `"/${r.hd}/"`, 
            `"/${r.name}/"`, 
            r.path_level, 
            `"/${r.path.replace(/^\/|\/$/g, '')}/"`,
            (r.total_size / 1073741824).toFixed(3),
            r.total_files, 
            r.total_subs,
            `"${formatSize(r.here_size)}"`, 
            r.here_files, 
            r.here_folders
        ];
        
        for (let i = 0; i <= maxLvlIndex; i++) {
            let val = r.levels[i] ? r.levels[i].replace(/^\/|\/$/g, '') : '';
            row.push(`"/${val}/"`);
        }
        csv += row.join(",") + "\n";
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.setAttribute("download", `audit_export_${new Date().getTime()}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}


let driveDataCache = []; // Global storage for drive stats
let sortDirection = 1;   // 1 for Asc, -1 for Desc

async function showDriveReport() {
    const reportDiv = document.getElementById('driveReport');
    reportDiv.classList.remove('hidden');
    
    // Initial Fetch
    try {
        const res = await fetch('?action=get_drives');
        driveDataCache = await res.json();
        renderDriveTable(driveDataCache);
    } catch (err) {
        document.getElementById('driveTableBody').innerHTML = '<tr><td colspan="5">Error loading drives.</td></tr>';
    }
}

function sortDrives(key) {
    sortDirection *= -1; // Toggle direction
    driveDataCache.sort((a, b) => {
        let valA = a[key];
        let valB = b[key];
        
        // Handle string comparison for names
        if (typeof valA === 'string') {
            return sortDirection * valA.localeCompare(valB);
        }
        // Handle numeric comparison for sizes/percents
        return sortDirection * (valA - valB);
    });
    renderDriveTable(driveDataCache);
}

function renderDriveTable(drives) {
    const tbody = document.getElementById('driveTableBody');
    const maxCapacity = Math.max(...drives.map(d => d.total));
    tbody.innerHTML = '';

    drives.forEach(d => {
        const usageColor = d.percent > 90 ? 'bg-red-500' : d.percent > 75 ? 'bg-amber-500' : 'bg-blue-600';
        const relativeWidth = ((d.total / maxCapacity) * 100).toFixed(1);

        const row = `
            <tr class="border-b last:border-0 hover:bg-slate-50">
                <td class="drive-cell font-mono text-[10px] text-slate-600 font-bold">${formatSize(d.total)}</td>
                <td class="drive-cell w-32">
                    <div class="h-1.5 bg-slate-200 rounded-full overflow-hidden">
                        <div class="h-full bg-slate-400" style="width: ${relativeWidth}%"></div>
                    </div>
                </td>
                <td class="drive-cell font-black text-slate-800 text-[9px] italic tracking-tight">/${d.name}/</td>
                <td class="drive-cell w-48">
                    <div class="flex items-center gap-2">
                        <div class="flex-1 h-2 bg-slate-100 rounded-full overflow-hidden border">
                            <div class="h-full ${usageColor}" style="width: ${d.percent}%"></div>
                        </div>
                        <span class="text-[8px] font-bold text-slate-400 w-6">${Math.round(d.percent)}%</span>
                    </div>
                </td>
                <td class="drive-cell text-[10px] font-mono text-emerald-600 font-bold text-right">${formatSize(d.free)} Free</td>
            </tr>`;
        tbody.insertAdjacentHTML('beforeend', row);
    });
    
    // Maintain the squish level after sorting
    updateDriveDensity(document.getElementById('rowSquish').value);
}


function updateDriveDensity(val) {
    // val is 1 to 10. We map this to padding in pixels.
    const padding = val + "px";
    const cells = document.querySelectorAll('.drive-cell');
    cells.forEach(cell => {
        cell.style.paddingTop = padding;
        cell.style.paddingBottom = padding;
    });
}


        window.onload = loadDrives;
    </script>
</body>
</html>