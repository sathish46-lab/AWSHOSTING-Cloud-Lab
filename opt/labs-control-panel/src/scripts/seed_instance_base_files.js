#!/usr/bin/env mongosh
/**
 * Seed instance_base_files in tom_labs_instances_db
 * 
 * Usage: docker exec TomCloudLab mongosh /opt/labs-control-panel/src/scripts/seed_instance_base_files.js
 */

var TEMPLATES_DIR = '/opt/labs-control-panel/lab-templates';
var TEMPLATES = ['essentials', 'minio', 'n8n', 'docker_lab'];

var HIDDEN_PATHS = [
    'ssh_host_keys',
    '.gitkeep',
    'Dockerfile',
    'config.json',
    'docker-compose.yml',
    '.env',
    '.env.example',
];

var targetDb = db.getSiblingDB('tom_labs_instances_db');
var baseFilesCol = targetDb.instance_base_files;

TEMPLATES.forEach(function(template) {
    var basePath = TEMPLATES_DIR + '/' + template + '/Data';

    try {
        var stat = cat(basePath);
    } catch(e) {
        print('[SKIP] Template not found: ' + template);
        return;
    }

    print('\n[SEED] Processing template: ' + template);

    var files = {};

    function walkDir(dir, relative) {
        relative = relative || '';
        var entries = ls(dir);
        for (var i = 0; i < entries.length; i++) {
            var name = entries[i];
            var rel = relative ? relative + '/' + name : name;
            var full = dir + '/' + name;

            try {
                // Test if directory by trying to list it
                var subEntries = ls(full);
                // It's a directory
                walkDir(full, rel);
                continue;
            } catch(e) {
                // It's a file
            }

            // Check hidden paths
            var shouldHide = false;
            for (var h = 0; h < HIDDEN_PATHS.length; h++) {
                var hidden = HIDDEN_PATHS[h];
                if (rel === hidden || rel.endsWith('/' + hidden)) {
                    shouldHide = true;
                    break;
                }
            }

            if (shouldHide) {
                print('  [HIDDEN] ' + rel);
                continue;
            }

            try {
                var content = cat(full);
                files[rel] = {
                    content: content,
                    size: content.length,
                };
            } catch(e) {
                print('  [ERROR] Cannot read: ' + rel);
            }
        }
    }

    walkDir(basePath);

    var existing = baseFilesCol.findOne({ template: template });

    var doc = {
        template: template,
        files: files,
        file_count: Object.keys(files).length,
        updated_at: new Date(),
    };

    if (existing) {
        baseFilesCol.updateOne(
            { template: template },
            { $set: doc }
        );
        print('  [UPDATED] ' + template + ': ' + Object.keys(files).length + ' files');
    } else {
        doc.created_at = new Date();
        baseFilesCol.insertOne(doc);
        print('  [CREATED] ' + template + ': ' + Object.keys(files).length + ' files');
    }
});

print('\n[DONE] instance_base_files seeded successfully.');
print('Total docs: ' + baseFilesCol.countDocuments());
