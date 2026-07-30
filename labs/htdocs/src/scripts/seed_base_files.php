<?php
/**
 * Seed Base Files Script
 * 
 * Populates tom_labs_instances_db/instance_base_files with safe base template files.
 * Excludes internal files like ssh_host_keys, Dockerfile, config.json.
 * 
 * Usage: php seed_base_files.php
 */

require_once __DIR__ . '/../load.php';

$templatesDir = '/opt/labs-control-panel/lab-templates';
$templates = ['essentials', 'minio', 'n8n', 'docker_lab'];

// Files/dirs to exclude from base files (internal/sensitive)
$hiddenPaths = [
    'ssh_host_keys',
    '.gitkeep',
    'Dockerfile',
    'config.json',
    'docker-compose.yml',
    '.env',
    '.env.example',
];

$db = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
$baseFilesCol = $db->instance_base_files;

foreach ($templates as $template) {
    $basePath = $templatesDir . '/' . $template . '/Data';
    
    if (!is_dir($basePath)) {
        echo "[SKIP] Template not found: $template\n";
        continue;
    }
    
    echo "\n[SEED] Processing template: $template\n";
    
    $files = [];
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($rii as $fileInfo) {
        if ($fileInfo->isDir()) continue;
        
        $relative = ltrim(substr($fileInfo->getPathname(), strlen($basePath) + 1), '/');
        if (empty($relative)) continue;
        
        // Check if file should be hidden
        $shouldHide = false;
        foreach ($hiddenPaths as $hidden) {
            if (strpos($relative, $hidden) === 0 || strrchr($relative, '/') === '/' . $hidden) {
                $shouldHide = true;
                break;
            }
        }
        
        if ($shouldHide) {
            echo "  [HIDDEN] $relative\n";
            continue;
        }
        
        $content = file_get_contents($fileInfo->getPathname());
        $files[$relative] = [
            'content' => $content,
            'size' => strlen($content),
        ];
    }
    
    // Store in database
    $existing = $baseFilesCol->findOne(['template' => $template]);
    
    $doc = [
        'template' => $template,
        'files' => $files,
        'file_count' => count($files),
        'updated_at' => new MongoDB\BSON\UTCDateTime(),
    ];
    
    if ($existing) {
        $baseFilesCol->updateOne(
            ['template' => $template],
            ['$set' => $doc]
        );
        echo "  [UPDATED] $template: " . count($files) . " files\n";
    } else {
        $doc['created_at'] = new MongoDB\BSON\UTCDateTime();
        $baseFilesCol->insertOne($doc);
        echo "  [CREATED] $template: " . count($files) . " files\n";
    }
}

echo "\n[DONE] Base files seeded successfully.\n";
