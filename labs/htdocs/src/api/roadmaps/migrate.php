<?php
/**
 * Roadmaps Feature - Database Migration
 * Run once: php src/api/roadmaps/migrate.php
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/utils/config.php';
require_once __DIR__ . '/../../../src/lib/core/DatabaseConnection.class.php';

try {
    $client = DatabaseConnection::getClient();
    $db = $client->selectDatabase('tom_labs_db');
    
    echo "=== Roadmaps Migration ===\n\n";
    
    // ── 1. ai_roadmaps ──
    echo "[1/3] Creating ai_roadmaps collection...\n";
    
    $collections = $db->listCollectionNames();
    
    if (!in_array('ai_roadmaps', $collections)) {
        $db->createCollection('ai_roadmaps', [
            'validator' => [
                '$jsonSchema' => [
                    '$required' => ['slug', 'title', 'user_id', 'sections', 'created_at'],
                    '$properties' => [
                        'slug' => ['$type' => 'string'],
                        'title' => ['$type' => 'string'],
                        'description' => ['$type' => 'string'],
                        'prompt' => ['$type' => 'string'],
                        'level' => ['$type' => 'string', '$in' => ['Beginner', 'Intermediate', 'Advanced']],
                        'hours' => ['$type' => 'int'],
                        'tags' => ['$type' => 'array', '$items' => ['$type' => 'string']],
                        'user_id' => ['$type' => 'int'],
                        'author' => ['$type' => 'string'],
                        'author_email' => ['$type' => 'string'],
                        'visibility' => ['$type' => 'string', '$in' => ['public', 'private']],
                        'sections' => ['$type' => 'array'],
                        'progress' => ['$type' => 'int', '$gte' => 0, '$lte' => 100],
                        'checkpoints_total' => ['$type' => 'int'],
                        'checkpoints_completed' => ['$type' => 'int'],
                        'created_at' => ['$type' => 'date'],
                        'updated_at' => ['$type' => 'date'],
                    ]
                ]
            ]
        ]);
        
        $db->ai_roadmaps->createIndex(['user_id' => 1, 'slug' => 1], ['unique' => true]);
        $db->ai_roadmaps->createIndex(['user_id' => 1, 'created_at' => -1]);
        $db->ai_roadmaps->createIndex(['visibility' => 1, 'created_at' => -1]);
        $db->ai_roadmaps->createIndex(['sections.topics.id' => 1]);
        
        echo "  ✓ Created with indexes\n";
    } else {
        echo "  → Already exists, skipping\n";
    }
    
    // ── 2. ai_roadmap_jobs ──
    echo "[2/3] Creating ai_roadmap_jobs collection...\n";
    
    if (!in_array('ai_roadmap_jobs', $collections)) {
        $db->createCollection('ai_roadmap_jobs');
        
        $db->ai_roadmap_jobs->createIndex(['request_id' => 1], ['unique' => true]);
        $db->ai_roadmap_jobs->createIndex(['user_id' => 1, 'created_at' => -1]);
        $db->ai_roadmap_jobs->createIndex(['user_id' => 1, 'status' => 1]);
        
        echo "  ✓ Created with indexes\n";
    } else {
        echo "  → Already exists, skipping\n";
    }
    
    // ── 3. ai_roadmap_progress ──
    echo "[3/3] Creating ai_roadmap_progress collection...\n";
    
    if (!in_array('ai_roadmap_progress', $collections)) {
        $db->createCollection('ai_roadmap_progress');
        
        $db->ai_roadmap_progress->createIndex(
            ['user_id' => 1, 'roadmap_id' => 1, 'topic_id' => 1],
            ['unique' => true]
        );
        $db->ai_roadmap_progress->createIndex(['user_id' => 1, 'roadmap_id' => 1]);
        
        echo "  ✓ Created with indexes\n";
    } else {
        echo "  → Already exists, skipping\n";
    }
    
    echo "\n=== Migration Complete ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
