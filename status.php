<?php

// Define stages and substages
$forensicStages = [
    'Acquisition' => [],
    'Form Uploads' => [],
    'Forensic Image Processing' => [
        'Verification',
        'Hard drive geometry analysis',
        'Signature Analysis',
        'Compound file expansion',
        'Recover deleted items',
        'Timezone settings'
    ],
    'Extraction of files' => [
        'Powerpoint', 'Word documents', 'Excel spreadsheet',
        'PDF Files', 'Tiff Files', 'Email Files'
    ],
    'Deleted and Recovered Files' => [
        'Powerpoint', 'Word documents', 'Excel spreadsheet',
        'PDF Files', 'Tiff Files', 'Email Files'
    ],
    'Deleted Folders' => [
        'Powerpoint', 'Word documents', 'Excel spreadsheet',
        'PDF Files', 'Tiff Files', 'Email Files'
    ]
];

// Read and parse the status file
$statusFile = "NLMERSETA_2024_001_log.txt";
$statusData = file_exists($statusFile) ? file_get_contents($statusFile) : "";
$lines = explode(PHP_EOL, $statusData);

// Initialize the status array
$stageStatus = [];
foreach ($forensicStages as $stage => $substages) {
    $stageStatus[$stage]['status'] = 'incomplete';
    foreach ($substages as $substage) {
        $stageStatus[$stage]['substages'][$substage] = 'incomplete';
    }
}

// Update status based on file content
foreach ($lines as $line) {
    foreach ($forensicStages as $stage => $substages) {
        // Match stage completions
        if (stripos($line, $stage) !== false && stripos($line, 'complete') !== false) {
            $stageStatus[$stage]['status'] = 'complete';
        }

        foreach ($substages as $substage) {
            $matchPatterns = [
                'Verification' => ['Image Verification complete'],
                'Hard drive geometry analysis' => ['Geometry Analysis complete'],
                'Signature Analysis' => ['Signature Analysis complete'],
                'Compound file expansion' => ['Compound File Expansion complete'],
                'Recover deleted items' => ['File Carving complete'],
                'Timezone settings' => ['Extract Metadata complete'],
                'Powerpoint' => ['Number of ppt', 'Extraction of ppt is complete'],
                'Word documents' => ['Number of doc', 'Extraction of doc is complete'],
                'Excel spreadsheet' => ['Number of xls', 'Extraction of xls is complete'],
                'PDF Files' => ['Number of pdf', 'Extraction of pdf is complete'],
                'Tiff Files' => ['Number of tiff', 'Extraction of tiff is complete'],
                'Email Files' => ['Number of msg', 'Number of eml', 'Number of pst', 'EMAILS export', 'Email exports complete']
            ];

            foreach ((array)($matchPatterns[$substage] ?? []) as $indicator) {
                if (stripos($line, $indicator) !== false) {
                    $stageStatus[$stage]['substages'][$substage] = 'complete';
                }
            }

            // Handle folder recovery separately
            if (
                stripos($substage, 'Email Files') !== false &&
                stripos($line, 'Folder Recovery for') !== false &&
                stripos($line, 'started') !== false
            ) {
                $stageStatus[$stage]['substages'][$substage] = 'busy';
            }
            if (
                stripos($substage, 'Email Files') !== false &&
                (stripos($line, 'Folder Recovery is complete') !== false || stripos($line, 'Folder Recovery process for') !== false && stripos($line, 'complete') !== false)
            ) {
                $stageStatus[$stage]['substages'][$substage] = 'complete';
            }
        }
    }
}

// Adjust parent stage status based on substages
foreach ($stageStatus as $stage => &$details) {
    if (isset($details['substages'])) {
        $subStatuses = array_values($details['substages']);
        if (in_array('busy', $subStatuses) || (in_array('complete', $subStatuses) && in_array('incomplete', $subStatuses))) {
            $details['status'] = 'busy';
        } elseif (!in_array('busy', $subStatuses) && !in_array('incomplete', $subStatuses)) {
            $details['status'] = 'complete';
        }
    }
}

// Calculate progress
$totalItems = 0;
$completedItems = 0;
foreach ($stageStatus as $stage => $details) {
    $totalItems++;
    if ($details['status'] == 'complete') {
        $completedItems++;
    }
    if (isset($details['substages'])) {
        foreach ($details['substages'] as $substatus) {
            $totalItems++;
            if ($substatus == 'complete') {
                $completedItems++;
            }
        }
    }
}
$progressPercent = $totalItems > 0 ? round(($completedItems / $totalItems) * 100) : 0;

// Helper to get color
function getColor($status)
{
    switch ($status) {
        case 'busy': return 'orange';
        case 'complete': return 'green';
        default: return 'red';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Digital Forensics Progress Monitor</title>
    <style>
        body { font-family: Arial, sans-serif; }
        .stage { padding: 10px; margin: 5px; border-radius: 5px; display: flex; align-items: center; }
        .substage { padding-left: 30px; }
        .status-box { width: 20px; height: 20px; margin-left: auto; border-radius: 3px; }
        .stage-number { width: 25px; }
        .progress-container { width: 100%; background: #ddd; height: 30px; border-radius: 5px; margin-bottom: 20px; overflow: hidden; }
        .progress-bar { height: 100%; text-align: center; color: white; font-weight: bold; line-height: 30px; }
    </style>
</head>
<body>

<h2>Digital Forensics Progress Monitor</h2>

<div class="progress-container">
    <div class="progress-bar" style="width: <?= $progressPercent ?>%; background: <?= $progressPercent == 100 ? 'green' : 'orange' ?>;">
        <?= $progressPercent ?>%
    </div>
</div>

<?php $stageCount = 1; ?>
<?php foreach ($stageStatus as $stage => $details): ?>
    <div class="stage">
        <span class="stage-number"><?= $stageCount++; ?>.</span>
        <strong><?= htmlspecialchars($stage); ?></strong> (<?= strtoupper($details['status']); ?>)
        <div class="status-box" style="background-color: <?= getColor($details['status']); ?>"></div>
    </div>
    <?php if (isset($details['substages'])): ?>
        <?php $substageCount = 1; ?>
        <?php foreach ($details['substages'] as $substage => $substatus): ?>
            <div class="stage substage">
                <span class="stage-number"><?= ($stageCount - 1) . '.' . $substageCount++; ?></span>
                <?= htmlspecialchars($substage); ?> (<?= strtoupper($substatus); ?>)
                <div class="status-box" style="background-color: <?= getColor($substatus); ?>"></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endforeach; ?>

</body>
</html>
