<?php

namespace SilverStripe\StaticPublishQueue\Publisher;

use SilverStripe\Assets\Filesystem;
use SilverStripe\Control\HTTPResponse;
use function SilverStripe\StaticPublishQueue\PathToURL;
use SilverStripe\StaticPublishQueue\Publisher;
use function SilverStripe\StaticPublishQueue\URLtoPath;

class FilesystemPublisher extends Publisher
{
    /**
     * @var string
     */
    protected $destFolder = 'cache';

    /**
     * @var string
     */
    protected $fileExtension = 'html';

    /**
     * @var bool
     */
    private static $use_gzip_compression = true;

    /**
     * @return string
     */
    public function getDestPath()
    {
        $base = defined('PUBLIC_PATH') ? PUBLIC_PATH : BASE_PATH;
        return $base . DIRECTORY_SEPARATOR . $this->getDestFolder();
    }

    public function setDestFolder($destFolder)
    {
        $this->destFolder = $destFolder;
        return $this;
    }

    public function getDestFolder()
    {
        return $this->destFolder;
    }

    public function setFileExtension($fileExtension)
    {
        $fileExtension = strtolower($fileExtension);
        if (! in_array($fileExtension, ['html', 'php'], true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Bad file extension "%s" passed to %s::%s',
                    $fileExtension,
                    static::class,
                    __FUNCTION__
                )
            );
        }
        $this->fileExtension = $fileExtension;
        return $this;
    }

    public function getFileExtension()
    {
        return $this->fileExtension;
    }

    public function purgeURL(string $url): array
    {
        if (! $url) {
            user_error('Bad url:' . var_export($url, true), E_USER_WARNING);
            return [];
        }
        if ($path = $this->URLtoPath($url)) {
            $success = $this->deleteFromPath($path . '.html') && $this->deleteFromPath($path . '.php');
            return [
                'success' => $success,
                'url' => $url,
                'path' => $this->getDestPath() . DIRECTORY_SEPARATOR . $path,
            ];
        }
        return [
            'success' => false,
            'url' => $url,
            'path' => false,
        ];
    }

    public function purgeAll(): bool
    {
        Filesystem::removeFolder($this->getDestPath());

        return file_exists($this->getDestPath()) ? false : true;
    }

    /**
     * @param string $url
     * @return array A result array
     */
    public function publishURL(string $url, ?bool $forcePublish = false): array
    {
        if (! $url) {
            user_error('Bad url:' . var_export($url, true), E_USER_WARNING);
            return [];
        }
        $success = false;
        $response = $this->generatePageResponse($url);
        $statusCode = $response->getStatusCode();
        $doPublish = ($forcePublish && $this->getFileExtension() === 'php') || $statusCode < 400;

        if ($statusCode < 300) {
            // publish success response
            $success = $this->publishPage($response, $url);
        } elseif ($statusCode < 400) {
            // publish redirect response
            $success = $this->publishRedirect($response, $url);
        } elseif ($doPublish) {
            // only publish error pages if we are able to send status codes via PHP
            $success = $this->publishPage($response, $url);
        }
        return [
            'published' => $doPublish,
            'success' => $success,
            'responsecode' => $statusCode,
            'url' => $url,
        ];
    }

    public function getPublishedURLs(?string $dir = null, array &$result = []): array
    {
        if ($dir === null) {
            $dir = $this->getDestPath();
        }

        $root = scandir($dir);
        foreach ($root as $fileOrDir) {
            if (strpos($fileOrDir, '.') === 0) {
                continue;
            }
            $fullPath = $dir . DIRECTORY_SEPARATOR . $fileOrDir;
            // we know html will always be generated, this prevents double ups
            if (is_file($fullPath) && pathinfo($fullPath, PATHINFO_EXTENSION) === 'html') {
                $result[] = $this->pathToURL($fullPath);
                continue;
            }

            if (is_dir($fullPath)) {
                $this->getPublishedURLs($fullPath, $result);
            }
        }
        return $result;
    }

    /**
     * @param HTTPResponse $response
     * @param string       $url
     * @return bool
     */
    protected function publishRedirect($response, $url)
    {
        $success = true;
        if ($path = $this->URLtoPath($url)) {
            $location = $response->getHeader('Location');
            if ($this->getFileExtension() === 'php') {
                $phpContent = $this->generatePHPCacheFile($response);
                $success = $this->saveToPath($phpContent, $path . '.php');
            }
            return $this->saveToPath($this->generateHTMLCacheRedirection($location), $path . '.html') && $success;
        }
        return false;
    }

    /**
     * @param HTTPResponse $response
     * @param string       $url
     * @return bool
     */
    protected function publishPage($response, $url)
    {
        $success = true;
        if ($path = $this->URLtoPath($url)) {
            // little hack to make sure we do not include pages with live forms.
            if (stripos($response->getBody(), 'name="SecurityID"')) {
                return false;
            }
            if ($this->getFileExtension() === 'php') {
                $phpContent = $this->generatePHPCacheFile($response);
                $success = $this->saveToPath($phpContent, $path . '.php');
            }
            return $this->saveToPath($response->getBody(), $path . '.html') && $success;
        }
        return false;
    }

    /**
     * returns true on access and false on failure
     * @param string $content
     * @param string $filePath
     * @return bool
     */
    protected function saveToPath($content, $filePath): bool
    {
        if (empty($content)) {
            return false;
        }

        // Write to a temporary file first
        $temporaryPath = tempnam(TEMP_PATH, 'filesystempublisher_');
        if (file_put_contents($temporaryPath, $content) === false) {
            return false;
        }

        // Move the temporary file to the desired location (prevents unlocked files from being read during write)
        $publishPath = $this->getDestPath() . DIRECTORY_SEPARATOR . $filePath;
        Filesystem::makeFolder(dirname($publishPath));
        $successWithPublish = rename($temporaryPath, $publishPath);
        if ($successWithPublish) {
            if (FilesystemPublisher::config()->get('use_gzip_compression')) {
                $publishPath = $this->compressFile($publishPath);
            }
        }

        return file_exists($publishPath);
    }

    protected function compressFile(string $publishPath): string
    {
        //we keep the html file for now ... so use second parameter to achieve this.
        $publishPathGZipped = $publishPath . '.gz';
        exec('rm ' . $publishPathGZipped . ' -rf');
        exec('gzip ' . $publishPath);
        exec('chmod 0777 ' . $publishPathGZipped);

        return $publishPathGZipped;
    }

    protected function deleteFromPath($filePath)
    {
        $deletePath = $this->getDestPath() . DIRECTORY_SEPARATOR . $filePath;
        if (file_exists($deletePath)) {
            $success = unlink($deletePath);
        } else {
            $success = true;
        }
        if (file_exists($deletePath . '.gz')) {
            return $this->deleteFromPath($deletePath . '.gz');
        }

        return $success;
    }

    protected function URLtoPath(string $url): string
    {
        return URLtoPath($url, BASE_URL, FilesystemPublisher::config()->get('domain_based_caching'));
    }

    protected function pathToURL(string $path): string
    {
        return PathToURL($path, $this->getDestPath(), FilesystemPublisher::config()->get('domain_based_caching'));
    }
}
