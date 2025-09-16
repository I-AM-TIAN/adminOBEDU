<?php

namespace App\Support;

use PhpOffice\PhpWord\IOFactory;
use ZipArchive;

class DocxPublicationParser
{
    /**
     * Devuelve solo título y contenido (texto plano).
     *
     * @return array{title: ?string, content: string}
     */
    public static function parse(string $absolutePath): array
    {
        $title   = null;
        $content = '';

        $text = '';

        // 1) Intento con PhpWord
        try {
            if (is_file($absolutePath)) {
                $phpWord  = IOFactory::load($absolutePath);
                $sections = method_exists($phpWord, 'getSections') ? $phpWord->getSections() : [];
                $text     = self::extractTextFromPhpWord($sections);
            }
        } catch (\Throwable $e) {
            // seguimos con fallback
        }

        // 2) Fallback: abrir el DOCX como ZIP y leer word/document.xml
        if ($text === '') {
            $text = self::extractTextFromXml($absolutePath);
        }

        // 3) Normalizar saltos y líneas
        $text = self::normalizeNewlines($text);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== ''));

        // 4) Título: intenta properties (docProps/core.xml), si no, primera línea no vacía
        $coreTitle = self::readCoreTitle($absolutePath);
        if ($coreTitle) {
            $title = $coreTitle;
        } else {
            $title = $lines[0] ?? null;
        }

        // 5) Contenido: todo lo que sigue al título (si coincide); si no, todo el texto
        if (!empty($lines)) {
            $contentLines = $lines;
            if ($title) {
                $idx = array_search($title, $lines, true);
                if ($idx !== false) {
                    $contentLines = array_slice($lines, $idx + 1);
                }
            }
            $content = implode("\n\n", $contentLines);
        }

        return [
            'title'   => $title,
            'content' => $content,
        ];
    }

    /**
     * Extrae texto con PhpWord. Siempre retorna string.
     *
     * @param array<int, mixed> $sections
     */
    protected static function extractTextFromPhpWord(array $sections): string
    {
        $out = '';

        foreach ($sections ?? [] as $section) {
            if (!method_exists($section, 'getElements')) {
                continue;
            }
            foreach ($section->getElements() as $el) {
                // Títulos
                if ($el instanceof \PhpOffice\PhpWord\Element\Title) {
                    $txt = trim((string) $el->getText());
                    if ($txt !== '') $out .= $txt . "\n\n";
                    continue;
                }

                // Párrafos con runs
                if ($el instanceof \PhpOffice\PhpWord\Element\TextRun) {
                    $buf = '';
                    foreach ($el->getElements() as $child) {
                        if ($child instanceof \PhpOffice\PhpWord\Element\Text) {
                            $buf .= (string) $child->getText();
                        } elseif ($child instanceof \PhpOffice\PhpWord\Element\Link) {
                            $buf .= (string) $child->getText();
                        } elseif ($child instanceof \PhpOffice\PhpWord\Element\Footnote) {
                            foreach ($child->getElements() as $fnEl) {
                                if ($fnEl instanceof \PhpOffice\PhpWord\Element\Text) {
                                    $buf .= ' ' . (string) $fnEl->getText();
                                }
                            }
                        }
                    }
                    $buf = trim($buf);
                    if ($buf !== '') $out .= $buf . "\n\n";
                    continue;
                }

                // Párrafos simples
                if ($el instanceof \PhpOffice\PhpWord\Element\Text) {
                    $txt = trim((string) $el->getText());
                    if ($txt !== '') $out .= $txt . "\n\n";
                    continue;
                }

                // Listas
                if ($el instanceof \PhpOffice\PhpWord\Element\ListItem) {
                    $txt = trim((string) $el->getText());
                    if ($txt !== '') $out .= '- ' . $txt . "\n";
                    continue;
                }

                // Tablas (concatenamos celdas)
                if ($el instanceof \PhpOffice\PhpWord\Element\Table) {
                    foreach ($el->getRows() as $row) {
                        $rowParts = [];
                        foreach ($row->getCells() as $cell) {
                            $cellTxt = '';
                            foreach ($cell->getElements() as $cellEl) {
                                if ($cellEl instanceof \PhpOffice\PhpWord\Element\TextRun) {
                                    foreach ($cellEl->getElements() as $r) {
                                        if ($r instanceof \PhpOffice\PhpWord\Element\Text) {
                                            $cellTxt .= (string) $r->getText();
                                        }
                                    }
                                } elseif ($cellEl instanceof \PhpOffice\PhpWord\Element\Text) {
                                    $cellTxt .= (string) $cellEl->getText();
                                }
                            }
                            $cellTxt = trim($cellTxt);
                            if ($cellTxt !== '') $rowParts[] = $cellTxt;
                        }
                        if (!empty($rowParts)) {
                            $out .= implode(' | ', $rowParts) . "\n";
                        }
                    }
                    $out .= "\n";
                    continue;
                }

                // Otros tipos se ignoran (imágenes, saltos, etc.)
            }
        }

        return (string) $out;
    }

    /**
     * Fallback: abre el DOCX como ZIP y extrae texto de word/document.xml (w:t).
     */
    protected static function extractTextFromXml(string $absolutePath): string
    {
        $zip = new ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            return '';
        }

        $xml = $zip->getFromName('word/document.xml') ?: '';
        if ($xml === '') {
            $zip->close();
            return '';
        }

        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            $zip->close();
            return '';
        }

        $doc->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $paras = [];
        foreach ($doc->xpath('//w:body//w:p') as $p) {
            $buf = '';
            foreach ($p->xpath('.//w:t') as $t) {
                $buf .= (string) $t;
            }
            $buf = trim($buf);
            if ($buf !== '') $paras[] = $buf;
        }

        $zip->close();
        return implode("\n\n", $paras);
    }

    /**
     * Lee el título del core.xml si existe.
     */
    protected static function readCoreTitle(string $absolutePath): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($absolutePath) !== true) return null;

        $core = $zip->getFromName('docProps/core.xml') ?: '';
        $zip->close();
        if ($core === '') return null;

        $xml = @simplexml_load_string($core);
        if ($xml === false) return null;

        $xml->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $val = (string) ($xml->xpath('//dc:title')[0] ?? '');
        $val = trim($val);

        return $val !== '' ? $val : null;
    }

    protected static function normalizeNewlines(string $text): string
    {
        $n = preg_replace("/\r\n|\r/u", "\n", $text);
        return $n ?? '';
    }

    /**
     * Útil si después decides usar RichEditor (convierte texto plano a <p>…</p>).
     */
    public static function textToHtml(string $text): string
    {
        $text  = self::normalizeNewlines($text);
        $paras = preg_split("/\n{2,}/u", trim($text)) ?: [];
        $html  = '';
        foreach ($paras as $p) {
            $p = e($p);
            $p = str_replace("\n", "<br>", $p);
            $html .= "<p>{$p}</p>\n";
        }
        return $html;
    }
}
