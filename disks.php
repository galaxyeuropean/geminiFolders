<?php
/**
 * Scans local and networked drives, identifying SSD/HDD type and capacity.
 */

function getDriveData() {
    // PowerShell command to get Drive Letter, Friendly Name, Media Type, and Size
    // This specifically looks for SSD vs HDD and includes networked drives
    $psCommand = 'Get-PhysicalDisk | Select-Object DeviceID, FriendlyName, MediaType, Size; Get-WmiObject Win32_LogicalDisk | Select-Object DeviceID, VolumeName, Size, FreeSpace';
    
    // For a simpler "all-in-one" view including Network drives:
    $command = 'powershell "Get-WmiObject Win32_LogicalDisk | Select-Object DeviceID, VolumeName, Size, FreeSpace, DriveType"';
    exec($command, $output);
    
    return $output;
}

function formatBytes($bytes) {
    if ($bytes == 0) return "0 B";
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
}

// Drive Type Mapping
$driveTypes = [
    0 => "Unknown",
    1 => "No Root Dir",
    2 => "Removable (USB)",
    3 => "Local Disk (HDD/SSD)",
    4 => "Network Drive",
    5 => "Compact Disc",
    6 => "RAM Disk"
];

$data = getDriveData();
?>

<!DOCTYPE html>
<html>
<head>
    <style>
        table { width: 100%; border-collapse: collapse; font-family: sans-serif; }
        th, td { padding: 12px; border: 1px solid #ddd; text-align: left; }
        th { background-color: #f2f2f2; }
        .ssd { color: #2ecc71; font-weight: bold; }
        .network { color: #3498db; }
    </style>
</head>
<body>

<h2>Network & Local Storage Scanner</h2>
<table>
    <tr>
        <th>Drive</th>
        <th>Name</th>
        <th>Type</th>
        <th>Total</th>
        <th>Used</th>
        <th>Free</th>
    </tr>
    <?php
    // Skip the header rows from PowerShell output and parse
    foreach ($data as $index => $line) {
        if ($index < 3 || trim($line) == "") continue;

        // Split the fixed-width output from PowerShell
        $parts = preg_split('/\s{2,}/', trim($line));
        if (count($parts) < 4) continue;

        $id = $parts[0];
        $name = $parts[1] ?: "Unnamed";
        $total = (float)$parts[2];
        $free = (float)$parts[3];
        $typeCode = (int)$parts[4];

        $used = $total - $free;
        $typeName = $driveTypes[$typeCode] ?? "Unknown";

        echo "<tr>
                <td>$id</td>
                <td>$name</td>
                <td class='".($typeCode == 4 ? 'network' : '')."'>$typeName</td>
                <td>".formatBytes($total)."</td>
                <td>".formatBytes($used)."</td>
                <td>".formatBytes($free)."</td>
              </tr>";
    }
    ?>
</table>

</body>
</html>