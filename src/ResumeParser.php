<?php
declare(strict_types=1);

final class ResumeParser
{
    private const MAX_FILE_BYTES = 5242880;
    private const MAX_TEXT_LENGTH = 20000;
    private const MAX_DOCX_ENTRY_BYTES = 10485760;
    private const MAX_DOCX_TOTAL_BYTES = 15728640;

    public function parse(string $path, string $extension): array
    {
        $extension = strtolower($extension);
        if (!in_array($extension, ['pdf', 'docx', 'txt'], true)) {
            throw new InvalidArgumentException('Choose a PDF, DOCX, or TXT resume.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('The uploaded resume could not be read.');
        }

        $size = filesize($path);
        if ($size === false || $size === 0) {
            throw new InvalidArgumentException('The uploaded resume is empty.');
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new LengthException('Resume files must be 5 MB or smaller.');
        }

        $signature = file_get_contents($path, false, null, 0, 8);
        if ($signature === false) {
            throw new InvalidArgumentException('The uploaded resume could not be read.');
        }

        $text = match ($extension) {
            'txt' => $this->parseText($path),
            'docx' => $this->parseDocx($path, $signature),
            'pdf' => $this->parsePdf($path, $signature),
        };

        $text = $this->normalizeText($text);
        return [
            'text' => $text,
            'characters' => self::textLength($text),
            'warnings' => self::textLength($text) < 80
                ? ['Review the extracted text. Add more resume details before requesting an analysis.']
                : [],
        ];
    }

    private function parseText(string $path): string
    {
        $text = file_get_contents($path);
        if ($text === false) {
            throw new InvalidArgumentException('The TXT resume could not be read.');
        }

        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (!self::isValidUtf8($text) || str_contains($text, "\0")) {
            throw new InvalidArgumentException('The TXT resume must contain readable UTF-8 text.');
        }

        return $text;
    }

    private function parseDocx(string $path, string $signature): string
    {
        if (!str_starts_with($signature, "PK\x03\x04")) {
            throw new InvalidArgumentException('The DOCX file is not a valid Office document.');
        }

        $entries = $this->readDocxEntries($path);
        if (!isset($entries['word/document.xml'])) {
            throw new InvalidArgumentException('The DOCX file does not contain a readable document body.');
        }

        $parts = [];
        foreach ($entries as $name => $xml) {
            if ($name !== 'word/document.xml'
                && preg_match('~^word/(?:header|footer)\d*\.xml$~', $name) !== 1) {
                continue;
            }
            $parts[] = $this->extractWordXml($xml);
        }

        return implode("\n", array_filter($parts, static fn(string $part): bool => trim($part) !== ''));
    }

    private function readDocxEntries(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException('The DOCX file could not be read.');
        }

        $archiveLength = strlen($contents);
        $tail = substr($contents, max(0, $archiveLength - 65557));
        $endOffset = strrpos($tail, "PK\x05\x06");
        if ($endOffset === false || strlen($tail) - $endOffset < 22) {
            throw new InvalidArgumentException('The DOCX file is incomplete or corrupted.');
        }

        $end = unpack('vdisk/vcentral_disk/ventries_disk/ventries/Vcentral_size/Vcentral_offset/vcomment_length', substr($tail, $endOffset + 4, 18));
        if (!is_array($end)
            || $end['disk'] !== 0
            || $end['central_disk'] !== 0
            || $end['entries_disk'] !== $end['entries']
            || $end['entries'] > 2048
            || $end['central_offset'] + $end['central_size'] > $archiveLength
            || $end['entries'] === 65535
            || $end['central_offset'] === 0xFFFFFFFF
            || $end['central_size'] === 0xFFFFFFFF) {
            throw new InvalidArgumentException('The DOCX archive uses an unsupported or invalid ZIP format.');
        }

        $entries = [];
        $totalExtracted = 0;
        $offset = $end['central_offset'];
        $centralEnd = $end['central_offset'] + $end['central_size'];
        for ($index = 0; $index < $end['entries']; $index++) {
            if (substr($contents, $offset, 4) !== "PK\x01\x02" || $offset + 46 > $centralEnd) {
                throw new InvalidArgumentException('The DOCX archive directory is corrupted.');
            }

            $entry = unpack('vversion_made/vversion_needed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length/vcomment_length/vdisk/vinternal/Vexternal/Vlocal_offset', substr($contents, $offset + 4, 42));
            if (!is_array($entry)) {
                throw new InvalidArgumentException('The DOCX archive directory is corrupted.');
            }
            $recordLength = 46 + $entry['name_length'] + $entry['extra_length'] + $entry['comment_length'];
            if ($offset + $recordLength > $centralEnd) {
                throw new InvalidArgumentException('The DOCX archive directory is truncated.');
            }

            $name = substr($contents, $offset + 46, $entry['name_length']);
            if (isset($entries[$name])) {
                throw new InvalidArgumentException('The DOCX archive contains duplicate document parts.');
            }
            if ($name === 'word/document.xml'
                || preg_match('~^word/(?:header|footer)\d*\.xml$~', $name) === 1) {
                $part = $this->readZipEntry($contents, $entry);
                $totalExtracted += strlen($part);
                if ($totalExtracted > self::MAX_DOCX_TOTAL_BYTES) {
                    throw new LengthException('The DOCX contains too much uncompressed content to process safely.');
                }
                $entries[$name] = $part;
            }
            $offset += $recordLength;
        }

        if ($offset !== $centralEnd) {
            throw new InvalidArgumentException('The DOCX archive directory is inconsistent.');
        }
        return $entries;
    }

    private function readZipEntry(string $archive, array $entry): string
    {
        if (($entry['flags'] & 1) !== 0
            || $entry['uncompressed'] > self::MAX_DOCX_ENTRY_BYTES
            || $entry['compressed'] > strlen($archive)
            || $entry['uncompressed'] === 0
            || !in_array($entry['method'], [0, 8], true)) {
            throw new InvalidArgumentException('The DOCX document part is encrypted, empty, or uses an unsupported compression format.');
        }

        $localOffset = $entry['local_offset'];
        if (substr($archive, $localOffset, 4) !== "PK\x03\x04" || $localOffset + 30 > strlen($archive)) {
            throw new InvalidArgumentException('The DOCX document part is corrupted.');
        }
        $local = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length', substr($archive, $localOffset + 4, 26));
        if (!is_array($local) || $local['method'] !== $entry['method']) {
            throw new InvalidArgumentException('The DOCX document part has inconsistent archive metadata.');
        }
        $dataOffset = $localOffset + 30 + $local['name_length'] + $local['extra_length'];
        if ($dataOffset + $entry['compressed'] > strlen($archive)) {
            throw new InvalidArgumentException('The DOCX document part is truncated.');
        }
        $compressed = substr($archive, $dataOffset, $entry['compressed']);
        $data = match ($entry['method']) {
            0 => $compressed,
            8 => @gzinflate($compressed, self::MAX_DOCX_ENTRY_BYTES),
        };

        if (!is_string($data)
            || strlen($data) !== $entry['uncompressed']
            || sprintf('%u', crc32($data)) !== (string) $entry['crc']) {
            throw new InvalidArgumentException('The DOCX document part failed integrity checks.');
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $data) === 1) {
            throw new InvalidArgumentException('The DOCX document contains unsupported XML declarations.');
        }

        return $data;
    }

    private function extractWordXml(string $xml): string
    {
        $document = new DOMDocument();
        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
        if (!$loaded) {
            throw new InvalidArgumentException('The DOCX document contains malformed XML.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $paragraphs = $xpath->query('//w:p');
        if ($paragraphs === false) {
            throw new InvalidArgumentException('The DOCX document text could not be extracted.');
        }

        $lines = [];
        foreach ($paragraphs as $paragraph) {
            $parts = $xpath->query('.//w:t | .//w:tab | .//w:br | .//w:cr', $paragraph);
            if ($parts === false) {
                continue;
            }
            $line = '';
            foreach ($parts as $part) {
                $line .= match ($part->localName) {
                    'tab' => "\t",
                    'br', 'cr' => "\n",
                    default => $part->textContent,
                };
            }
            if (trim($line) !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    private function parsePdf(string $path, string $signature): string
    {
        if (!str_starts_with($signature, '%PDF-')) {
            throw new InvalidArgumentException('The PDF file does not have a valid PDF signature.');
        }
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new RuntimeException('PDF extraction is unavailable until Composer dependencies are installed. Run composer install on the server.', 503);
        }

        try {
            $document = (new \Smalot\PdfParser\Parser())->parseFile($path);
            $text = $document->getText();
        } catch (Throwable $error) {
            throw new InvalidArgumentException('The PDF is corrupted or could not be read. Try exporting it again as a text-based PDF.', 0, $error);
        }
        if (!is_string($text) || trim($text) === '') {
            throw new InvalidArgumentException('This PDF contains no extractable text. It may be scanned; OCR is not available yet.');
        }

        return $text;
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = trim($text);
        if (!self::isValidUtf8($text) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text) === 1) {
            throw new InvalidArgumentException('The extracted resume contains unsupported characters.');
        }
        if ($text === '') {
            throw new InvalidArgumentException('No readable resume text was found in the uploaded file.');
        }

        $length = self::textLength($text);
        if ($length > self::MAX_TEXT_LENGTH) {
            throw new LengthException('Extracted resume text exceeds the 20,000-character limit.');
        }

        return $text;
    }

    private static function isValidUtf8(string $text): bool
    {
        return preg_match('//u', $text) === 1;
    }

    private static function textLength(string $text): int
    {
        $length = preg_match_all('/./us', $text);
        if ($length === false) {
            throw new InvalidArgumentException('The extracted resume must be valid UTF-8.');
        }

        return $length;
    }
}
