<?php

declare(strict_types=1);

namespace Quitt\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Liest eine hochgeladene Datei aus einem multipart/form-data-Request.
 * Noetig, weil PHP multipart-Bodies bei PUT nicht nach $_FILES parst
 * (request_parse_body() erst ab PHP 8.4).
 */
final class Multipart
{
    /**
     * @return array{bytes: string, filename: ?string}|null null = Feld fehlt
     * @throws ApiException 422 bei zu grosser Datei / Upload-Fehler
     */
    public static function file(ServerRequestInterface $request, string $field, int $maxBytes): ?array
    {
        $files = $request->getUploadedFiles();
        if (isset($files[$field]) && $files[$field] instanceof \Psr\Http\Message\UploadedFileInterface) {
            $f = $files[$field];
            if (in_array($f->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($f->getSize() ?? 0) > $maxBytes) {
                throw new ApiException(422, 'validation_failed', null, ['fields' => [$field => 'validation.file_too_large']]);
            }
            if ($f->getError() !== UPLOAD_ERR_OK) {
                return null;
            }
            return ['bytes' => (string) $f->getStream(), 'filename' => $f->getClientFilename()];
        }

        $type = $request->getHeaderLine('Content-Type');
        if (!preg_match('/^multipart\/form-data;.*boundary=(?:"([^"]+)"|([^;\s]+))/i', $type, $m)) {
            return null;
        }
        $boundary = $m[1] !== '' ? $m[1] : $m[2];
        $body = $request->getBody();
        $size = $body->getSize();
        if ($size !== null && $size > $maxBytes + 65536) {
            throw new ApiException(422, 'validation_failed', null, ['fields' => [$field => 'validation.file_too_large']]);
        }
        $raw = (string) $body;
        foreach (explode('--' . $boundary, $raw) as $part) {
            $split = strpos($part, "\r\n\r\n");
            if ($split === false) {
                continue;
            }
            $headers = substr($part, 0, $split);
            if (!preg_match('/content-disposition:[^\r\n]*\bname="([^"]*)"/i', $headers, $nm) || $nm[1] !== $field) {
                continue;
            }
            $content = substr($part, $split + 4);
            if (str_ends_with($content, "\r\n")) {
                $content = substr($content, 0, -2);
            }
            if (strlen($content) > $maxBytes) {
                throw new ApiException(422, 'validation_failed', null, ['fields' => [$field => 'validation.file_too_large']]);
            }
            $filename = preg_match('/filename="([^"]*)"/i', $headers, $fm) ? $fm[1] : null;
            return ['bytes' => $content, 'filename' => $filename];
        }
        return null;
    }
}
