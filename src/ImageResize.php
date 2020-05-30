<?php
namespace Mxmm\ImageResize;

use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Adapter\Local as LocalAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Request;
use Exception;

class ImageResize
{
    protected $config;
    /**
     * @var String $path Image source file path
     */
    private $path;
    /**
     * Intervention Image method. Currently only supports 'fit' and 'resize' method
     * @var String $action|fit
     */
    private $action;

    private $width;
    private $height;
    private $basename;
    private $adapter;
    private $targetPath;
    private $targetMetaData = [];
    private $targetTimestamp;
    private $sourceTimestamp;

    public function __construct(array $config, string $path = null)
    {
        $this->config       = $config;
        $this->path         = $path;
        $this->basename     = pathinfo($this->path)['basename'];
    }

    /**
     * @param string|null $path
     * @param int|null $width
     * @param int|null $height
     * @param string $action
     * @return string
     */
    public static function url(string $path = null, int $width = null, int $height = null, string $action = 'fit'): string
    {
        return (new ImageResize(config('image-resize'), $path))->getResizedImage($path, $width, $height, $action);
    }

    public static function path(string $path = null, int $width = null, int $height = null, string $action = 'fit'): string
    {
        return (new ImageResize(config('image-resize'), $path))->getResizedImage($path, $width, $height, $action, false);
    }

    private function getResizedImage(string $path = null, int $width = null, int $height = null, string $action = 'fit', $url = true): string
    {
        if (!$path || $width < 1 && $height < 1) {
            return '';
        }

        $this->settings($width, $height, $action);

        if (!$this->setTargetMetaData()) {
            return '';
        }

        if (!in_array(strtolower(pathinfo($path)['extension']), ['jpg', 'jpeg', 'png', 'gif'])) {
            return $this->filePlaceholder(pathinfo($path), $path);
        }

        $this->resize();

        return $url === true ? $this->getUrl() : $this->targetPath;
    }

    private function settings(int $width = null, int $height = null, $action = 'fit'): ImageResize
    {
        $this->width    = $width;
        $this->height   = $height;
        $this->action   = $action;
        $this->adapter  = Storage::getAdapter();
        $this->setTargetPath();

        if (Cache::has($this->targetPath)) {
            $this->targetTimestamp = Cache::get($this->targetPath);
        }

        if (Cache::has($this->path)) {
            $this->sourceTimestamp = Cache::get($this->path);
        }

        return $this;
    }

    private function setTargetPath(): ImageResize
    {
        $dirName = dirname($this->path);

        $targetDirName       = $this->config['dir'];
        $targetDirName      .= $dirName !== '.' && $dirName !== '/' ? ltrim($dirName, '/') . '/' : '';
        $targetDirName      .= $this->action . '/' . $this->width . 'x' . $this->height . '/';
        $this->targetPath    = $targetDirName . $this->basename;

        return $this;
    }

    private function setTargetMetaData(): bool
    {
        if ($this->targetTimestamp) {
            return true;
        }

        try {
            $this->targetMetaData  = Storage::getMetadata($this->targetPath);
            $this->targetTimestamp = $this->setTimestamp($this->targetPath, $this->targetMetaData);
        } catch (Exception $e) {
            if (!$this->adapter instanceof LocalAdapter && !Storage::exists($this->path)) {
                if (!Storage::disk('public')->exists($this->path)) {
                    return false;
                }
                // File exists in local public disk but not in cloud
                $this->upload(
                    $this->path,
                    Storage::disk('public')->get($this->path),
                    Storage::disk('public')->mimeType($this->path)
                );
            }
        }

        return true;
    }

    private function setTimestamp($key, $metadata)
    {
        if (array_key_exists('timestamp', $metadata)) {
            $value = $metadata['timestamp'];
        } elseif (array_key_exists('info', $metadata)) {
            $value = $metadata['info']['filetime'];
        } else {
            return '';
        }

        Cache::put($key, $value, $this->config['cache-expiry']);
        return $value;
    }

    private function setSourceTimestamp(): bool
    {
        try {
            $sourceMetaData = Storage::getMetadata($this->path);
        } catch (Exception $e) {
            return false;
        }

        $this->sourceTimestamp = $this->setTimestamp($this->path, $sourceMetaData);
        return true;
    }

    private function getUrl(): string
    {
        if (method_exists($this->adapter, 'getUrl')) {
            $url = $this->adapter->getUrl($this->targetPath);
        } elseif ($this->adapter instanceof AwsS3Adapter) {
            $url = $this->getAwsUrl();
        } elseif ($this->adapter instanceof LocalAdapter) {
            $url = Storage::url($this->targetPath);
        } else {
            $url = '';
        }

        if (Request::secure() == true) {
            $url = str_replace('http:', 'https:', $url);
        }

        return $url;
    }

    private function getAwsUrl(): string
    {
        $endpoint = $this->adapter->getClient()->getEndpoint();
        $path     =  '/' . ltrim($this->adapter->getPathPrefix() . $this->targetPath, '/');

        if (!is_null($domain = Storage::getConfig()->get('url'))) {
            $url = rtrim($domain, '/') . $path;
        } else {
            $url  = $endpoint->getScheme() . '://' . $this->adapter->getBucket() . '.' . $endpoint->getHost() . $path;
        }

        return $url;
    }

    private function replace_extension($filename, $new_extension) {
        $info = pathinfo($filename);
        return $info['dirname']."/".$info['filename'] . '.' . $new_extension;
    }

    private function resize(): bool
    {
        if (!$this->sourceTimestamp) {
            $this->setSourceTimestamp();
        }

        if (!$this->sourceTimestamp || $this->targetTimestamp > $this->sourceTimestamp) {
            // source file doesn't exist or older that target file
            return false;
        }

        switch ($this->action) {
            case 'fit':
            case 'resize':
                try {

                    $sourceMiteType = \Illuminate\Support\Facades\File::mimeType(storage_path('app/'.$this->path));
                    $targetExt = $this->mime2ext($sourceMiteType);
                    $sourceExt = Str::lower(pathinfo(storage_path('app/'.$this->path))['extension']);
                    if ($targetExt !== $sourceExt) {
                        $targetExt = $sourceExt;
                    }

                    $image = Image::make(Storage::get($this->path))
                        ->setFileInfoFromPath(storage_path('app/' . $this->path))
                        ->orientate()
                        ->{$this->action}($this->width, $this->height, function ($constraint) {
                            $constraint->aspectRatio();
                            $constraint->upsize();
                        })->encode($targetExt, 75);

                    $this->targetPath = $this->replace_extension($this->targetPath, $targetExt);

                    $this->basename = pathinfo($this->targetPath)['basename'];

                    $this->upload($this->targetPath, (string) $image, $sourceMiteType);
                } catch (Exception $e) {
                    return false;
                }
                break;
            default:
                return false;
        }

        return true;
    }

    private function upload($path, $image, $contentType)
    {
        Storage::getDriver()->put($path, $image, [
            'visibility'         => 'public',
            'Expires'            => gmdate('D, d M Y H:i:s', time() + $this->config['browser-cache']) . ' GMT',
            'CacheControl'       => 'public, max-age=' . $this->config['browser-cache'],
            'ContentType'        => $contentType,
            'ContentDisposition' => 'inline; filename="' . $this->basename . '"',
        ]);
    }

    private function filePlaceholder(array $info, string $path): string
    {
        if (in_array($info['extension'], ['mp4', 'webm'])) {
            $url = asset('/vendor/laravel-image-resize/images/placeholders/video.svg');
        } elseif (in_array($info['extension'], ['svg'])) {
            $url = Storage::url($path);
        } else {
            $url = asset('/vendor/laravel-image-resize/images/placeholders/file.svg');
        }

        return $url;
    }

    private function mime2ext($mime) {
        $mime_map = [
            'video/3gpp2'                                                               => '3g2',
            'video/3gp'                                                                 => '3gp',
            'video/3gpp'                                                                => '3gp',
            'application/x-compressed'                                                  => '7zip',
            'audio/x-acc'                                                               => 'aac',
            'audio/ac3'                                                                 => 'ac3',
            'application/postscript'                                                    => 'ai',
            'audio/x-aiff'                                                              => 'aif',
            'audio/aiff'                                                                => 'aif',
            'audio/x-au'                                                                => 'au',
            'video/x-msvideo'                                                           => 'avi',
            'video/msvideo'                                                             => 'avi',
            'video/avi'                                                                 => 'avi',
            'application/x-troff-msvideo'                                               => 'avi',
            'application/macbinary'                                                     => 'bin',
            'application/mac-binary'                                                    => 'bin',
            'application/x-binary'                                                      => 'bin',
            'application/x-macbinary'                                                   => 'bin',
            'image/bmp'                                                                 => 'bmp',
            'image/x-bmp'                                                               => 'bmp',
            'image/x-bitmap'                                                            => 'bmp',
            'image/x-xbitmap'                                                           => 'bmp',
            'image/x-win-bitmap'                                                        => 'bmp',
            'image/x-windows-bmp'                                                       => 'bmp',
            'image/ms-bmp'                                                              => 'bmp',
            'image/x-ms-bmp'                                                            => 'bmp',
            'application/bmp'                                                           => 'bmp',
            'application/x-bmp'                                                         => 'bmp',
            'application/x-win-bitmap'                                                  => 'bmp',
            'application/cdr'                                                           => 'cdr',
            'application/coreldraw'                                                     => 'cdr',
            'application/x-cdr'                                                         => 'cdr',
            'application/x-coreldraw'                                                   => 'cdr',
            'image/cdr'                                                                 => 'cdr',
            'image/x-cdr'                                                               => 'cdr',
            'zz-application/zz-winassoc-cdr'                                            => 'cdr',
            'application/mac-compactpro'                                                => 'cpt',
            'application/pkix-crl'                                                      => 'crl',
            'application/pkcs-crl'                                                      => 'crl',
            'application/x-x509-ca-cert'                                                => 'crt',
            'application/pkix-cert'                                                     => 'crt',
            'text/css'                                                                  => 'css',
            'text/x-comma-separated-values'                                             => 'csv',
            'text/comma-separated-values'                                               => 'csv',
            'application/vnd.msexcel'                                                   => 'csv',
            'application/x-director'                                                    => 'dcr',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
            'application/x-dvi'                                                         => 'dvi',
            'message/rfc822'                                                            => 'eml',
            'application/x-msdownload'                                                  => 'exe',
            'video/x-f4v'                                                               => 'f4v',
            'audio/x-flac'                                                              => 'flac',
            'video/x-flv'                                                               => 'flv',
            'image/gif'                                                                 => 'gif',
            'application/gpg-keys'                                                      => 'gpg',
            'application/x-gtar'                                                        => 'gtar',
            'application/x-gzip'                                                        => 'gzip',
            'application/mac-binhex40'                                                  => 'hqx',
            'application/mac-binhex'                                                    => 'hqx',
            'application/x-binhex40'                                                    => 'hqx',
            'application/x-mac-binhex40'                                                => 'hqx',
            'text/html'                                                                 => 'html',
            'image/x-icon'                                                              => 'ico',
            'image/x-ico'                                                               => 'ico',
            'image/vnd.microsoft.icon'                                                  => 'ico',
            'text/calendar'                                                             => 'ics',
            'application/java-archive'                                                  => 'jar',
            'application/x-java-application'                                            => 'jar',
            'application/x-jar'                                                         => 'jar',
            'image/jp2'                                                                 => 'jp2',
            'video/mj2'                                                                 => 'jp2',
            'image/jpx'                                                                 => 'jp2',
            'image/jpm'                                                                 => 'jp2',
            'image/jpeg'                                                                => 'jpeg',
            'image/pjpeg'                                                               => 'jpeg',
            'application/x-javascript'                                                  => 'js',
            'application/json'                                                          => 'json',
            'text/json'                                                                 => 'json',
            'application/vnd.google-earth.kml+xml'                                      => 'kml',
            'application/vnd.google-earth.kmz'                                          => 'kmz',
            'text/x-log'                                                                => 'log',
            'audio/x-m4a'                                                               => 'm4a',
            'application/vnd.mpegurl'                                                   => 'm4u',
            'audio/midi'                                                                => 'mid',
            'application/vnd.mif'                                                       => 'mif',
            'video/quicktime'                                                           => 'mov',
            'video/x-sgi-movie'                                                         => 'movie',
            'audio/mpeg'                                                                => 'mp3',
            'audio/mpg'                                                                 => 'mp3',
            'audio/mpeg3'                                                               => 'mp3',
            'audio/mp3'                                                                 => 'mp3',
            'video/mp4'                                                                 => 'mp4',
            'video/mpeg'                                                                => 'mpeg',
            'application/oda'                                                           => 'oda',
            'audio/ogg'                                                                 => 'ogg',
            'video/ogg'                                                                 => 'ogg',
            'application/ogg'                                                           => 'ogg',
            'application/x-pkcs10'                                                      => 'p10',
            'application/pkcs10'                                                        => 'p10',
            'application/x-pkcs12'                                                      => 'p12',
            'application/x-pkcs7-signature'                                             => 'p7a',
            'application/pkcs7-mime'                                                    => 'p7c',
            'application/x-pkcs7-mime'                                                  => 'p7c',
            'application/x-pkcs7-certreqresp'                                           => 'p7r',
            'application/pkcs7-signature'                                               => 'p7s',
            'application/pdf'                                                           => 'pdf',
            'application/octet-stream'                                                  => 'pdf',
            'application/x-x509-user-cert'                                              => 'pem',
            'application/x-pem-file'                                                    => 'pem',
            'application/pgp'                                                           => 'pgp',
            'application/x-httpd-php'                                                   => 'php',
            'application/php'                                                           => 'php',
            'application/x-php'                                                         => 'php',
            'text/php'                                                                  => 'php',
            'text/x-php'                                                                => 'php',
            'application/x-httpd-php-source'                                            => 'php',
            'image/png'                                                                 => 'png',
            'image/x-png'                                                               => 'png',
            'application/powerpoint'                                                    => 'ppt',
            'application/vnd.ms-powerpoint'                                             => 'ppt',
            'application/vnd.ms-office'                                                 => 'ppt',
            'application/msword'                                                        => 'doc',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/x-photoshop'                                                   => 'psd',
            'image/vnd.adobe.photoshop'                                                 => 'psd',
            'audio/x-realaudio'                                                         => 'ra',
            'audio/x-pn-realaudio'                                                      => 'ram',
            'application/x-rar'                                                         => 'rar',
            'application/rar'                                                           => 'rar',
            'application/x-rar-compressed'                                              => 'rar',
            'audio/x-pn-realaudio-plugin'                                               => 'rpm',
            'application/x-pkcs7'                                                       => 'rsa',
            'text/rtf'                                                                  => 'rtf',
            'text/richtext'                                                             => 'rtx',
            'video/vnd.rn-realvideo'                                                    => 'rv',
            'application/x-stuffit'                                                     => 'sit',
            'application/smil'                                                          => 'smil',
            'text/srt'                                                                  => 'srt',
            'image/svg+xml'                                                             => 'svg',
            'application/x-shockwave-flash'                                             => 'swf',
            'application/x-tar'                                                         => 'tar',
            'application/x-gzip-compressed'                                             => 'tgz',
            'image/tiff'                                                                => 'tiff',
            'text/plain'                                                                => 'txt',
            'text/x-vcard'                                                              => 'vcf',
            'application/videolan'                                                      => 'vlc',
            'text/vtt'                                                                  => 'vtt',
            'audio/x-wav'                                                               => 'wav',
            'audio/wave'                                                                => 'wav',
            'audio/wav'                                                                 => 'wav',
            'application/wbxml'                                                         => 'wbxml',
            'video/webm'                                                                => 'webm',
            'audio/x-ms-wma'                                                            => 'wma',
            'application/wmlc'                                                          => 'wmlc',
            'video/x-ms-wmv'                                                            => 'wmv',
            'video/x-ms-asf'                                                            => 'wmv',
            'application/xhtml+xml'                                                     => 'xhtml',
            'application/excel'                                                         => 'xl',
            'application/msexcel'                                                       => 'xls',
            'application/x-msexcel'                                                     => 'xls',
            'application/x-ms-excel'                                                    => 'xls',
            'application/x-excel'                                                       => 'xls',
            'application/x-dos_ms_excel'                                                => 'xls',
            'application/xls'                                                           => 'xls',
            'application/x-xls'                                                         => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
            'application/vnd.ms-excel'                                                  => 'xlsx',
            'application/xml'                                                           => 'xml',
            'text/xml'                                                                  => 'xml',
            'text/xsl'                                                                  => 'xsl',
            'application/xspf+xml'                                                      => 'xspf',
            'application/x-compress'                                                    => 'z',
            'application/x-zip'                                                         => 'zip',
            'application/zip'                                                           => 'zip',
            'application/x-zip-compressed'                                              => 'zip',
            'application/s-compressed'                                                  => 'zip',
            'multipart/x-zip'                                                           => 'zip',
            'text/x-scriptzsh'                                                          => 'zsh',
        ];

        return isset($mime_map[$mime]) === true ? $mime_map[$mime] : false;
    }
}
