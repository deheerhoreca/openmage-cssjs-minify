<?php
/**
 * @category   FBalliano
 * @package    Fballiano_CssjsMinify
 * @copyright  Copyright (c) Fabrizio Balliano (http://fabrizioballiano.com)
 * @license    https://opensource.org/license/osl-3 Open Software License (OSL 3.0)
 */
class Fballiano_CssjsMinify_Model_Observer
{
    /**
     * Folder inside media/ where minified files are stored.
     */
    public const MINIFIED_FILES_FOLDER = 'min';
    
    /**
     * Cache version to force refresh of minified files on change of minification logic.
     */
    public const CACHE_VERSION = 1;
    
    /**
     * Observes: http_response_send_before
     *
     * @param  Varien_Event_Observer $observer
     */
    public function httpResponseSendBefore(Varien_Event_Observer $observer): void
    {
        $response = $observer->getResponse();
        $html = $response->getBody();
        $html = self::minifyCssJs($html);
        $response->setBody($html);
    }
    
    /**
     * Minify CSS and JS files in the given HTML content.
     * > Skip minifying a file if it appears to be already minified.
     *
     * @param string $html
     * @return string
     */
    public static function minifyCssJs(string $html): string
    {
        if (defined('MAHO_PUBLIC_DIR')) {
            $baseDir = MAHO_PUBLIC_DIR;
        } else {
            $baseDir = Mage::getBaseDir();
        }
        $mediaDir = Mage::getBaseDir('media');
        $mediaUrl = Mage::getBaseUrl('media');
        $minifiedDir = "{$mediaDir}/" . self::MINIFIED_FILES_FOLDER . '/';
        $minifiedUrl = "{$mediaUrl}" . self::MINIFIED_FILES_FOLDER . '/';

        if (!file_exists($minifiedDir)) {
            mkdir($minifiedDir, 0755, true);
        }

        // Process JS
        $pattern = '/(<script.+src\s*=\s*["\'])(.*\.js)(["\'].*>)/iU';
        $html = preg_replace_callback($pattern, function($matches) use ($baseDir, $minifiedDir, $minifiedUrl) {
            $url         = $matches[2];
            $origPathRel = (string) parse_url($url, PHP_URL_PATH);
            $origPathAbs = $baseDir.$origPathRel;
            if (file_exists($origPathAbs)) {
                $origPathFilename = pathinfo($origPathAbs, PATHINFO_FILENAME);
                $minifiedFile = $origPathFilename."-".hash("adler32", $origPathAbs, false)."-".filemtime($origPathAbs)."-".self::CACHE_VERSION.".min.js";
                $minifiedPath = $minifiedDir.$minifiedFile;
                if (!file_exists($minifiedPath)) {
                    try {
                        if(self::isVendorMinified($origPathAbs)) {
                        // Just copy the original file if it's already minified, prevents double minification effort and bugs
                            copy($origPathAbs, $minifiedPath);
                        } else {
                            // Minify the JS file
                            $minifier = new \MatthiasMullie\Minify\JS($origPathAbs);
                            $minifier->minify($minifiedPath);
                        }
                    } catch (Throwable $e) {
                        Mage::logException($e);
                        return $matches[1] . $matches[2] . $matches[3];
                    }
                }
                $matches[2] = $minifiedUrl . $minifiedFile;
            }
            return $matches[1] . $matches[2] . $matches[3];
        }, $html);

        // Process CSS
        $pattern = '/(<link.+href\s*=\s*["\'])(.*\.css)(["\'].*>)/iU';
        $html = preg_replace_callback($pattern, function($matches) use ($baseDir, $minifiedDir, $minifiedUrl) {
            $url         = $matches[2];
            $origPathRel = (string) parse_url($url, PHP_URL_PATH);
            $origPathAbs = $baseDir.$origPathRel;
            if (file_exists($origPathAbs)) {
                $origPathFilename = pathinfo($origPathAbs, PATHINFO_FILENAME);
                $minifiedFile = $origPathFilename."-".hash("adler32", $origPathAbs, false)."-".filemtime($origPathAbs)."-".self::CACHE_VERSION.".min.css";
                $minifiedPath = $minifiedDir.$minifiedFile;
                if (!file_exists($minifiedPath)) {
                    try {
                        if(self::isVendorMinified($origPathAbs)) {
                            // Just copy the original file if it's already minified, prevents double minification effort and bugs
                            copy($origPathAbs, $minifiedPath);
                        } else {
                            // Minify the CSS file
                            $minifier = new \MatthiasMullie\Minify\CSS($origPathAbs);
                            $minifier->minify($minifiedPath);
                        }
                    } catch (Throwable $e) {
                        Mage::logException($e);
                        return $matches[1] . $matches[2] . $matches[3];
                    }
                }
                $matches[2] = $minifiedUrl . $minifiedFile;
            }
            return $matches[1] . $matches[2] . $matches[3];
        }, $html);

        return $html;
    }

    /**
     * Check if a file is already minified based on its name.
     *
     * @param string $filePath
     * @return bool
     */
    public static function isVendorMinified(string $filePath): bool
    {
        $minifiedIndicators = ['.min.', '-min.', '.pack.', '-pack.'];
        foreach ($minifiedIndicators as $indicator) {
            if (strpos($filePath, $indicator) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Keep files newer than 7 days only.
     * @return void
     */
    public function dailyCron(): void
    {
        $mediaDir = Mage::getBaseDir('media');
        $minifiedDir = "{$mediaDir}/" . self::MINIFIED_FILES_FOLDER;
        if (!is_dir($minifiedDir)) {
            return;
        }
        $files = scandir($minifiedDir, SCANDIR_SORT_DESCENDING);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = "{$minifiedDir}/{$file}";
            if (is_file($path) && filemtime($path) + (60 * 60 * 24 * 7) < time() && is_writable($path)) {
                unlink($path);
            }
        }
    }
}
