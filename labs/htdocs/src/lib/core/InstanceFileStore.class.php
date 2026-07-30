<?php

/**
 * Instance File Store (Copy-on-Write template inheritance)
 *
 * Storage model:
 *   - Base layer:  MinIO at labassets/instances/base/<template>/<path>
 *                  Fallback: /opt/labs-control-panel/lab-templates/<template>/
 *   - User layer:  ONE document per instance in tom_labs_instances_db.instance_files
 *                  { instance_id, template, files: { "path": {content, size, ...} } }
 *                  Created/updated copy-on-write when a user edits/creates a file.
 *
 * Rendering merges base + user overrides. Saves always write to the user layer.
 */

class InstanceFileStore {

    const S3_BASE_PREFIX = 'labassets/instances/base/';

    const TEXT_EXT = [
        'txt', 'php', 'html', 'htm', 'css', 'js', 'json', 'md', 'sh', 'py', 'yml',
        'yaml', 'ini', 'conf', 'cfg', 'Dockerfile', 'env', 'log', 'xml', 'sql',
        'toml', 'csv', 'rst', 'gitignore', 'service', 'socket', 'mount', 'path', 'link'
    ];

    // Paths to never expose to users (internal/sensitive)
    const HIDDEN_PATHS = [
        'ssh_host_keys',
        '.gitkeep',
        'Dockerfile',
        'config.json',
        'docker-compose.yml',
        '.env',
        '.env.example',
        'entrypoints',
    ];

    /** @var MongoDB\Database */
    protected static $filesDb = null;

    public static function db() {
        if (self::$filesDb === null) {
            self::$filesDb = DatabaseConnection::getClient()->selectDatabase('tom_labs_instances_db');
        }
        return self::$filesDb;
    }

    public static function collection() {
        return self::db()->selectCollection('instance_files');
    }

    /**
     * Get or create the single user-layer document for an instance.
     * Returns the MongoDB document array (with _id).
     */
    protected static function getOrCreateUserDoc($instanceId, $templateFolder, $username = '', $email = '') {
        $doc = self::collection()->findOne(['instance_id' => $instanceId]);
        if (!$doc) {
            $newDoc = [
                'instance_id' => $instanceId,
                'template' => $templateFolder,
                'username' => $username,
                'email' => $email,
                'files' => new \stdClass(),
                'created_at' => new MongoDB\BSON\UTCDateTime(),
                'updated_at' => new MongoDB\BSON\UTCDateTime(),
            ];
            self::collection()->insertOne($newDoc);
            $doc = self::collection()->findOne(['instance_id' => $instanceId]);
        } elseif ((empty($doc['username']) || empty($doc['email'])) && (!empty($username) || !empty($email))) {
            $updates = ['updated_at' => new MongoDB\BSON\UTCDateTime()];
            if (empty($doc['username']) && !empty($username)) $updates['username'] = $username;
            if (empty($doc['email']) && !empty($email)) $updates['email'] = $email;
            self::collection()->updateOne(['instance_id' => $instanceId], ['$set' => $updates]);
            $doc = self::collection()->findOne(['instance_id' => $instanceId]);
        }
        return (array) $doc;
    }

    /**
     * Map an instance's template name to a lab-templates folder.
     * Checks MinIO for existence, not filesystem.
     */
    public static function resolveTemplateFolder($instance) {
        $candidates = [];
        if (!empty($instance['template'])) $candidates[] = $instance['template'];
        if (!empty($instance['lab_type'])) $candidates[] = $instance['lab_type'];
        if (!empty($instance['type'])) $candidates[] = $instance['type'];
        $candidates[] = 'essentials';

        foreach ($candidates as $name) {
            // Check MinIO
            try {
                $prefix = self::S3_BASE_PREFIX . $name . '/';
                $keys = Storage::listObjects($prefix);
                if ($keys !== false && count($keys) > 0) {
                    return $name;
                }
            } catch (Exception $e) {
                // Fall through
            }
            // Check DB
            try {
                $baseFilesCol = self::db()->selectCollection('instance_base_files');
                $doc = $baseFilesCol->findOne(['template' => $name]);
                if ($doc && !empty($doc['files'])) {
                    return $name;
                }
            } catch (Exception $e) {
                // Fall through
            }
        }
        return null;
    }

    /**
     * Ensure an instance's base layer exists in MinIO, then return the template folder.
     */
    public static function ensureBaseForInstance($instance) {
        $folder = self::resolveTemplateFolder($instance);
        if ($folder) {
            self::seedBaseToMinIO($folder);
        }
        return $folder;
    }

    /**
     * Seed base files to MinIO from DB (idempotent — skips if already uploaded).
     */
    public static function seedBaseToMinIO($templateFolder) {
        $prefix = self::S3_BASE_PREFIX . $templateFolder . '/';
        $existing = Storage::listObjects($prefix);
        if ($existing !== false && count($existing) > 0) {
            return true;
        }

        // Read from DB fallback
        try {
            $baseFilesCol = self::db()->selectCollection('instance_base_files');
            $doc = $baseFilesCol->findOne(['template' => $templateFolder]);
            if ($doc && !empty($doc['files'])) {
                foreach ((array)$doc['files'] as $path => $fileData) {
                    $fileData = (array)$fileData;
                    $content = $fileData['content'] ?? '';
                    $s3Key = $prefix . $path;
                    Storage::uploadContent($content, $s3Key);
                }
                return true;
            }
        } catch (Exception $e) {
            error_log('seedBaseToMinIO DB error: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * List all base files for a template.
     * Order: MinIO → DB fallback. NEVER reads from filesystem.
     */
    public static function listBaseFiles($templateFolder) {
        // 1. Try MinIO first
        try {
            $prefix = self::S3_BASE_PREFIX . $templateFolder . '/';
            $keys = Storage::listObjects($prefix);
            if ($keys !== false && count($keys) > 0) {
                $files = array_map(function ($key) use ($prefix) {
                    return ltrim(substr($key, strlen($prefix)), '/');
                }, $keys);
                return self::filterHiddenPaths($files);
            }
        } catch (Exception $e) {
            error_log('listBaseFiles MinIO error: ' . $e->getMessage());
        }

        // 2. DB fallback — tom_labs_instances_db.instance_base_files
        try {
            $baseFilesCol = self::db()->selectCollection('instance_base_files');
            $doc = $baseFilesCol->findOne(['template' => $templateFolder]);
            if ($doc && !empty($doc['files'])) {
                $files = array_keys((array)$doc['files']);
                return self::filterHiddenPaths($files);
            }
        } catch (Exception $e) {
            error_log('listBaseFiles DB error: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Filter out hidden/internal paths from file list.
     */
    protected static function filterHiddenPaths($files) {
        return array_filter($files, function ($path) {
            foreach (self::HIDDEN_PATHS as $hidden) {
                if ($path === $hidden || strpos($path, $hidden . '/') === 0 || strrchr($path, '/') === '/' . $hidden) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * Read base file content. MinIO first, DB fallback. NEVER reads from filesystem.
     */
    public static function readBaseFile($templateFolder, $path) {
        // 1. Try MinIO first
        try {
            $s3Key = self::S3_BASE_PREFIX . $templateFolder . '/' . $path;
            $content = Storage::download($s3Key);
            if ($content !== false && strlen($content) > 0) {
                return ['content' => $content, 'size' => strlen($content)];
            }
        } catch (Exception $e) {
            error_log('readBaseFile MinIO error for ' . $path . ': ' . $e->getMessage());
        }

        // 2. DB fallback
        try {
            $baseFilesCol = self::db()->selectCollection('instance_base_files');
            $doc = $baseFilesCol->findOne(['template' => $templateFolder]);
            if ($doc && !empty($doc['files'])) {
                $files = (array)$doc['files'];
                if (isset($files[$path])) {
                    $fileData = (array)$files[$path];
                    $content = $fileData['content'] ?? '';
                    return ['content' => $content, 'size' => strlen($content)];
                }
            }
        } catch (Exception $e) {
            error_log('readBaseFile DB error for ' . $path . ': ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Build a merged tree (base from filesystem + user overrides from Mongo) for an instance.
     */
    public static function getTree($instanceId, $templateFolder) {
        $allPaths = [];

        $baseFiles = self::listBaseFiles($templateFolder);
        foreach ($baseFiles as $relPath) {
            if ($relPath === '' || $relPath === '/') continue;
            $allPaths[$relPath] = false;
        }

        // Single user doc with all overrides
        $userDoc = self::getOrCreateUserDoc($instanceId, $templateFolder);
        $userFiles = (array)($userDoc['files'] ?? []);
        $userMap = [];
        foreach ($userFiles as $filePath => $fileData) {
            $fileData = (array) $fileData;
            $userMap[$filePath] = $fileData;
            if (empty($fileData['is_dir'])) {
                $allPaths[$filePath] = true;
            }
        }

        // Collect all folder paths
        $allFolders = [];
        foreach (array_keys($allPaths) as $filePath) {
            $parts = explode('/', $filePath);
            array_pop($parts);
            $cumulative = '';
            foreach ($parts as $part) {
                $cumulative = $cumulative === '' ? $part : $cumulative . '/' . $part;
                $allFolders[$cumulative] = true;
            }
        }
        foreach ($userMap as $path => $data) {
            if (!empty($data['is_dir'])) {
                $allFolders[$path] = true;
            }
        }

        // Build node map and parent→children index
        $nodes = [];
        $childrenOf = [];

        foreach ($allFolders as $folderPath => $_) {
            $isUser = isset($userMap[$folderPath]);
            $nodes[$folderPath] = [
                'path' => $folderPath,
                'name' => basename($folderPath),
                'is_dir' => true,
                'modified' => $isUser,
                'children' => [],
            ];
            $dir = dirname($folderPath);
            $dir = ($dir === '.' || $dir === '/') ? '' : $dir;
            $childrenOf[$dir][] = $folderPath;
        }

        foreach ($allPaths as $filePath => $isUser) {
            $data = $userMap[$filePath] ?? null;
            $nodes[$filePath] = [
                'path' => $filePath,
                'name' => basename($filePath),
                'is_dir' => false,
                'size' => $isUser ? ($data['size'] ?? 0) : 0,
                'modified' => (bool) $isUser,
            ];
            $dir = dirname($filePath);
            $dir = ($dir === '.' || $dir === '/') ? '' : $dir;
            $childrenOf[$dir][] = $filePath;
        }

        // Recursively build tree from root
        $build = function ($parentPath) use (&$build, &$nodes, &$childrenOf) {
            $children = $childrenOf[$parentPath] ?? [];
            $list = [];
            foreach ($children as $childPath) {
                $node = $nodes[$childPath];
                if ($node['is_dir']) {
                    $node['children'] = $build($childPath);
                }
                $list[] = $node;
            }
            usort($list, function ($a, $b) {
                if ($a['is_dir'] !== $b['is_dir']) return $a['is_dir'] ? -1 : 1;
                return strcasecmp($a['name'], $b['name']);
            });
            return $list;
        };

        return $build('');
    }

    /**
     * Get file content — user layer from single doc, base layer from MinIO/filesystem.
     */
    public static function getFile($instanceId, $templateFolder, $path) {
        $userDoc = self::getOrCreateUserDoc($instanceId, $templateFolder);
        $userFiles = (array)($userDoc['files'] ?? []);

        if (isset($userFiles[$path])) {
            $data = (array) $userFiles[$path];
            return [
                'base_path' => $path,
                'name' => basename($path),
                'is_dir' => !empty($data['is_dir']),
                'size' => $data['size'] ?? 0,
                'content' => $data['content'] ?? null,
                's3_key' => $data['s3_key'] ?? null,
                'modified' => true,
            ];
        }

        // Fall back to base layer
        $base = self::readBaseFile($templateFolder, $path);
        if ($base) {
            return [
                'base_path' => $path,
                'name' => basename($path),
                'is_dir' => false,
                'size' => $base['size'] ?? strlen($base['content'] ?? ''),
                'content' => $base['content'],
                'modified' => false,
            ];
        }
        return null;
    }

    /**
     * Copy-on-write save. Updates the single user doc's files map.
     */
    public static function saveFile($instanceId, $templateFolder, $path, $content, $username, $email) {
        self::getOrCreateUserDoc($instanceId, $templateFolder, $username, $email);
        $now = new MongoDB\BSON\UTCDateTime();

        // Read current files, merge, then set entire files object
        $doc = self::collection()->findOne(['instance_id' => $instanceId]);
        $currentFiles = $doc ? (array)($doc['files'] ?? []) : [];
        $currentFiles[$path] = ['content' => $content, 'size' => strlen($content)];

        $result = self::collection()->updateOne(
            ['instance_id' => $instanceId],
            ['$set' => [
                'files' => $currentFiles,
                'updated_at' => $now,
            ]]
        );

        $modified = $result->getModifiedCount();
        $matched = $result->getMatchedCount();
        error_log("saveFile: instance_id=$instanceId path=$path matched=$matched modified=$modified content_len=" . strlen($content));

        return $modified;
    }

    /**
     * Create a new file or folder in the user layer.
     */
    public static function createNode($instanceId, $templateFolder, $path, $isDir, $username, $email, $content = '') {
        $path = ltrim($path, '/');
        self::getOrCreateUserDoc($instanceId, $templateFolder, $username, $email);

        $doc = self::collection()->findOne(['instance_id' => $instanceId]);
        $currentFiles = $doc ? (array)($doc['files'] ?? []) : [];

        if (isset($currentFiles[$path])) {
            return ['status' => 'error', 'error' => 'Path already exists'];
        }

        $now = new MongoDB\BSON\UTCDateTime();
        $currentFiles[$path] = $isDir
            ? ['is_dir' => true]
            : ['content' => $content, 'size' => strlen($content)];

        self::collection()->updateOne(
            ['instance_id' => $instanceId],
            ['$set' => [
                'files' => $currentFiles,
                'username' => $username,
                'email' => $email,
                'updated_at' => $now,
            ]]
        );

        return ['status' => 'success'];
    }

    /**
     * Delete a user-layer node (and its children if dir).
     */
    public static function deleteNode($instanceId, $path) {
        $path = rtrim($path, '/');
        $doc = self::collection()->findOne(['instance_id' => $instanceId]);
        if (!$doc) return ['status' => 'success'];

        $currentFiles = (array)($doc['files'] ?? []);
        $changed = false;
        foreach ($currentFiles as $filePath => $_) {
            if ($filePath === $path || strpos($filePath, $path . '/') === 0) {
                unset($currentFiles[$filePath]);
                $changed = true;
            }
        }

        if ($changed) {
            $now = new MongoDB\BSON\UTCDateTime();
            self::collection()->updateOne(
                ['instance_id' => $instanceId],
                ['$set' => ['files' => $currentFiles, 'updated_at' => $now]]
            );
        }

        return ['status' => 'success'];
    }
}
