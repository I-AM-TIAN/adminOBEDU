<?php

namespace App\Support;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\Footnote;
use Illuminate\Support\Str;

class DocxPublicationParser
{
    public static function parse(string $absolutePath): array
    {
        // Carga el .docx (auto-detección del formato)
        $phpWord = IOFactory::load($absolutePath);

        // 1) Extraer TODO el texto en orden, respetando saltos razonables
        $fullText = self::extractText($phpWord->getSections());

        // 2) Normalizar saltos de línea (Windows/Mac → \n)
        $text = trim(preg_replace("/\r\n|\r/", "\n", $fullText));

        // 3) Parsear por etiquetas
        $title    = self::captureSingleLine($text, '/^Title:\s*(.+)$/mi');
        $abstract = self::captureBlock($text, 'Abstract:', 'Content:');
        $content  = self::captureBlock($text, 'Content:', null);

        return [
            'title'    => $title ?: null,
            'abstract' => $abstract ?: null,
            'content'  => $content ?: null,
        ];
    }

    /**
     * Extrae texto de una colección de secciones usando instanceof
     * para evitar métodos no definidos en análisis estático.
     *
     * @param Section[] $sections
     */
    protected static function extractText(array $sections): string
    {
        $out = '';

        foreach ($sections as $section) {
            foreach ($section->getElements() as $element) {
                // Párrafos complejos (runs)
                if ($element instanceof TextRun) {
                    foreach ($element->getElements() as $child) {
                        if ($child instanceof Text) {
                            $out .= $child->getText();
                        } elseif ($child instanceof Link) {
                            $out .= $child->getText(); // texto visible del link
                        } elseif ($child instanceof Footnote) {
                            // opcional: agregar el texto de la nota
                            foreach ($child->getElements() as $fnEl) {
                                if ($fnEl instanceof Text) {
                                    $out .= ' ' . $fnEl->getText();
                                }
                            }
                        }
                    }
                    $out .= PHP_EOL;
                    continue;
                }

                // Texto simple
                if ($element instanceof Text) {
                    $out .= $element->getText() . PHP_EOL;
                    continue;
                }

                // Títulos
                if ($element instanceof Title) {
                    $out .= $element->getText() . PHP_EOL;
                    continue;
                }

                // Listas
                if ($element instanceof ListItem) {
                    $out .= '- ' . $element->getText() . PHP_EOL;
                    continue;
                }

                // Tablas (leemos celdas como párrafos)
                if ($element instanceof Table) {
                    foreach ($element->getRows() as $row) {
                        foreach ($row->getCells() as $cell) {
                            if ($cell instanceof Cell) {
                                foreach ($cell->getElements() as $cellEl) {
                                    if ($cellEl instanceof TextRun) {
                                        foreach ($cellEl->getElements() as $cellRunEl) {
                                            if ($cellRunEl instanceof Text) {
                                                $out .= $cellRunEl->getText();
                                            }
                                        }
                                        $out .= PHP_EOL;
                                    } elseif ($cellEl instanceof Text) {
                                        $out .= $cellEl->getText() . PHP_EOL;
                                    }
                                }
                            }
                        }
                    }
                    continue;
                }

                // Otros tipos que no afectan: saltos, imágenes, etc. Los ignoramos.
            }
        }

        return $out;
    }

    protected static function captureSingleLine(string $text, string $pattern): ?string
    {
        return preg_match($pattern, $text, $m) ? trim($m[1]) : null;
    }

    /**
     * Captura bloque multilínea desde $startMarker hasta $endMarker (si existe).
     */
    protected static function captureBlock(string $text, string $startMarker, ?string $endMarker): ?string
    {
        $startPos = Str::of($text)->position($startMarker);
        if ($startPos === false) {
            return null;
        }
        $startPos += strlen($startMarker);

        if ($endMarker) {
            $pos = Str::of($text)->position($endMarker, $startPos);
            $endPos = ($pos === false) ? strlen($text) : $pos;
        } else {
            $endPos = strlen($text);
        }

        return trim(substr($text, $startPos, $endPos - $startPos));
    }
}
