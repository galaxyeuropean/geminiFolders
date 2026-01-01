<?php
// 1. GLOBAL CONFIG
ini_set('memory_limit', '1024M');
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
        $path = $_GET['path'];
        $depthLimit = (int)$_GET['depthLimit'];
        $targets = [$path];
        
        try {
            $dir = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::UNIX_PATHS);
            $filter = new RecursiveCallbackFilterIterator($dir, function ($current) {
                $fn = $current->getFilename();
                $blocked = ['Library', 'System', 'Volumes', 'dev', 'proc', '.Trash'];
                return $fn[0] !== '.' && !in_array($fn, $blocked); 
            });
            $it = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);
            $it->setMaxDepth($depthLimit - 1);
            foreach ($it as $fileinfo) {
                if ($fileinfo->isDir() && !$fileinfo->isLink() && is_readable($fileinfo->getPathname())) {
                    $targets[] = $fileinfo->getPathname();
                }
                if (count($targets) > 2000) break; 
            }
        } catch (Exception $e) {}

        $results = [];
        foreach ($targets as $t) {
            $deep = getFolderStats($t, true);
            $local = getFolderStats($t, false);
            $rawParts = array_values(array_filter(explode('/', $t)));
            $levels = [];
            foreach ($rawParts as $idx => $p) { $levels[$idx] = "/$p/"; }

            $results[] = [
                'hd' => getHDName($t), 'path' => $t, 'path_level' => count($levels), 'name' => basename($t),
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
    <title>Auditor Pro v8.5</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f1f5f9; padding: 20px; font-family: ui-sans-serif, system-ui; }
        .folder { color: #2563eb; cursor: pointer; font-weight: 800; font-size: 11px; }
        th { font-size: 9px; background: #f8fafc; border: 1px solid #e2e8f0; padding: 6px; position: sticky; top: 0; z-index: 50; }
        td { font-size: 10px; border: 1px solid #f1f5f9; padding: 4px; white-space: nowrap; }
        .btn-tool { padding: 6px 12px; border-radius: 6px; font-weight: 900; font-size: 10px; text-transform: uppercase; border: 1px solid transparent; cursor: pointer; }
        .branch { margin-left: 15px; border-left: 1px dashed #cbd5e1; }
        #treemapContainer { display: none; margin-bottom: 2rem; background: white; border-radius: 1rem; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
    </style>
</head>
<body> 

    <div class="bg-white p-4 rounded-2xl shadow-sm border mb-4 flex flex-wrap justify-between items-center gap-4">
        <div class="flex items-center gap-4">
            <h1 class="text-xl font-black italic tracking-tighter">AUDITOR<span class="text-blue-600">PRO v8.5</span></h1>
            <input type="text" id="matrixFilter" placeholder="Filter entire path..." onkeyup="applyFilter()" class="bg-slate-100 border px-3 py-1.5 rounded-lg text-xs font-bold w-64 outline-none focus:ring-2 focus:ring-blue-500 transition-all">
        </div>
        <div class="flex gap-2">
            <div id="statusBox" class="px-3 py-1.5 bg-slate-200 rounded-full text-[10px] font-black uppercase text-slate-500">Idle</div>
            <button onclick="toggleView()" id="viewBtn" class="btn-tool bg-purple-600 text-white shadow-md">Analytics</button>
            <button onclick="runAnalysis()" class="btn-tool bg-blue-600 text-white shadow-md">Start Scan</button>
            <button onclick="copyForExcel()" class="btn-tool bg-emerald-600 text-white shadow-md">Copy for Sheets</button>
            <button onclick="exportCSV()" class="btn-tool bg-slate-800 text-white">Export CSV</button>
        </div>
    </div>

    <div id="treemapContainer">
        <div class="flex justify-between items-center mb-4 border-b pb-3">
            <div id="breadcrumb" class="text-[10px] font-black text-slate-500 truncate max-w-[70%] uppercase tracking-widest">Root</div>
            <div class="flex gap-2">
                <button onclick="goBackChart()" class="btn-tool bg-white border border-slate-200 text-slate-600 hover:bg-slate-50">← Back</button>
                <button onclick="updateChart(null)" class="btn-tool bg-blue-50 border border-blue-100 text-blue-600 hover:bg-blue-100">⟲ Top</button>
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
                    <thead><tr id="headerRow">
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
            document.getElementById('statusBox').innerText = "Scanning...";
            fullDataCache = [];
            for (const cb of checks) {
                const res = await fetch(`?action=scan&path=${encodeURIComponent(cb.dataset.path)}&depthLimit=3`);
                const json = await res.json();
                if(json.data) fullDataCache = [...fullDataCache, ...json.data];
            }
            applyFilter();
            document.getElementById('statusBox').innerText = "Done";
        }

        function renderTable(data) {
            const body = document.getElementById('tableBody');
            body.innerHTML = '';
            data.forEach((r, idx) => {
                let row = `<tr>
                    <td>${idx + 1}</td><td>${r.hd}</td>
                    <td class="font-bold text-blue-600 cursor-pointer" onclick="updateChart('${r.path}')">${r.name}</td>
                    <td class="text-center">${r.path_level}</td>
                    <td class="text-[9px] text-slate-400 truncate max-w-[150px]">${r.path}</td>
                    <td class="text-right font-black text-blue-700">${(r.total_size / 1073741824).toFixed(3)}</td>
                    <td class="text-right">${r.total_files}</td><td class="text-right">${r.total_subs}</td>
                    <td class="text-right text-emerald-700 font-bold">${formatSize(r.here_size)}</td>
                    <td class="text-right">${r.here_files}</td><td class="text-right">${r.here_folders}</td>
                </tr>`;
                body.insertAdjacentHTML('beforeend', row);
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

            if (!values.length) { alert("No sub-content found."); return; }

            if (chartInstance) chartInstance.destroy();
            if (barChartInstance) barChartInstance.destroy();

            document.getElementById('breadcrumb').innerText = parentRow.path;
            const colors = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0891b2', '#4f46e5', '#6366f1', '#facc15', '#94a3b8'];

            chartInstance = new Chart(document.getElementById('pieChartCanvas'), {
                type: 'pie',
                data: { labels: labels, datasets: [{ data: values, backgroundColor: colors }] },
                options: { maintainAspectRatio: false, onClick: (e, el) => {
                    if (el.length > 0 && el[0].index < children.length) {
                        chartHistory.push(parentRow.path);
                        updateChart(children[el[0].index].path);
                    }
                }}
            });

            barChartInstance = new Chart(document.getElementById('stackedBarCanvas'), {
                type: 'bar',
                data: { labels: ['Composition'], datasets: labels.map((l, i) => ({ label: l, data: [values[i]], backgroundColor: colors[i % colors.length], barThickness: 60 })) },
                options: { indexAxis: 'x', maintainAspectRatio: false, scales: { x: { stacked: true, display: false }, y: { stacked: true } }, plugins: { legend: { display: false } } }
            });
        }

        function goBackChart() { if (chartHistory.length) updateChart(chartHistory.pop()); }
        function toggleView() { isChartView = !isChartView; document.getElementById('treemapContainer').style.display = isChartView ? 'block' : 'none'; updateChart(); }
        function applyFilter() { renderTable(fullDataCache.filter(r => r.path.toLowerCase().includes(document.getElementById('matrixFilter').value.toLowerCase()))); }
        function formatSize(b) { if (!b || b === 0) return '0 B'; const i = Math.floor(Math.log(b) / Math.log(1024)); return (b / Math.pow(1024, i)).toFixed(2) * 1 + ' ' + ['B', 'KB', 'MB', 'GB', 'TB'][i]; }

        function copyForExcel() {
            let tsv = "HD\tFolder\tLevel\tPath\tTotalGB\tTotalFiles\tSizeHere\n";
            fullDataCache.forEach(r => tsv += `${r.hd}\t${r.name}\t${r.path_level}\t${r.path}\t${(r.total_size/1073741824).toFixed(3)}\t${r.total_files}\t${r.here_size}\n`);
            navigator.clipboard.writeText(tsv).then(() => alert("Copied! Paste into Excel/Sheets."));
        }

        function exportCSV() {
            let csv = "HD,Name,Level,Path,TotalGB\n";
            fullDataCache.forEach(r => csv += `"${r.hd}","${r.name}",${r.path_level},"${r.path}",${(r.total_size/1073741824).toFixed(3)}\n`);
            const a = document.createElement('a'); a.href = window.URL.createObjectURL(new Blob([csv], {type:'text/csv'})); a.download = `audit.csv`; a.click();
        }

        window.onload = loadDrives;
    </script>
</body>
</html>