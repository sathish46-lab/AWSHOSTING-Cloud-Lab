<?php
use Aws\S3\S3Client;

class Storage {
    private static $client = null;

    public static function getClient() {
        if (self::$client === null) {
            $config = get_config('s3');
            self::$client = new S3Client([
                'version'     => 'latest',
                'region'      => $config['region'],
                'endpoint'    => $config['endpoint'],
                'use_path_style_endpoint' => $config['use_path_style'],
                'signature_version' => 'v4',
                'use_aws_shared_config_files' => false,
                'credentials' => [
                    'key'    => $config['access_key'],
                    'secret' => $config['secret_key'],
                ],
            ]);
        }
        return self::$client;
    }

    /**
     * Upload a file to MinIO
     */
    public static function upload($localFilePath, $s3Path, $acl = 'private') {
        $client = self::getClient();
        $config = get_config('s3');

        try {
            $result = $client->putObject([
                'Bucket' => $config['bucket'],
                'Key'    => ltrim($s3Path, '/'),
                'SourceFile' => $localFilePath,
                'ACL'    => $acl,
                'ContentType' => mime_content_type($localFilePath)
            ]);
            return $result;
        } catch (Aws\S3\Exception\S3Exception $e) {
            error_log("MinIO Upload Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate a pre-signed URL for temporary access to a private object.
     * @param int $expires Lifetime in seconds (default 3600 = 1 hour)
     */
    public static function getSignedUrl($s3Path, $expires = 3600) {
        $client = self::getClient();
        $config = get_config('s3');

        try {
            $cmd = $client->getCommand('GetObject', [
                'Bucket' => $config['bucket'],
                'Key'    => ltrim($s3Path, '/'),
            ]);
            $request = $client->createPresignedRequest($cmd, $expires);
            return (string) $request->getUri();
        } catch (Aws\S3\Exception\S3Exception $e) {
            error_log("MinIO Signed URL Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Download a file's content from MinIO.
     * Returns the body string on success, false on failure.
     */
    public static function download($s3Path) {
        $client = self::getClient();
        $config = get_config('s3');

        try {
            $result = $client->getObject([
                'Bucket' => $config['bucket'],
                'Key'    => ltrim($s3Path, '/'),
            ]);
            return (string) $result['Body'];
        } catch (Aws\S3\Exception\S3Exception $e) {
            error_log("MinIO Download Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * List all object keys under a prefix.
     * Returns an array of key strings.
     */
    public static function listObjects($prefix) {
        $client = self::getClient();
        $config = get_config('s3');
        $keys = [];

        try {
            $iterator = $client->getIterator('ListObjectsV2', [
                'Bucket' => $config['bucket'],
                'Prefix' => ltrim($prefix, '/'),
            ]);
            foreach ($iterator as $object) {
                $keys[] = $object['Key'];
            }
        } catch (Aws\S3\Exception\S3Exception $e) {
            error_log("MinIO List Error: " . $e->getMessage());
            return false;
        }
        return $keys;
    }
}