<?php

namespace App\Services\Rag;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ChatKnowledgeTextExtractorService
{
    public function extract(UploadedFile $file, ?array $processedAttachment = null): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $mimeType = strtolower((string) $file->getClientMimeType());

        return match (true) {
            $this->isTextDocument($extension, $mimeType) => $this->extractTextDocument($file, $extension),
            $this->isSpreadsheet($extension, $mimeType) => $this->extractSpreadsheet($file, $extension),
            $this->isImage($extension, $mimeType) => $this->extractImage($processedAttachment),
            $this->isAudio($extension, $mimeType) => $this->extractAudio($processedAttachment),
            $this->isPdf($extension, $mimeType) => [
                'success' => false,
                'mode' => 'pdf',
                'content' => null,
                'message' => 'A extração textual de PDF será concluída pelo pipeline assíncrono do Google Drive/n8n.',
                'pending_async' => true,
            ],
            default => $this->extractFromProcessedFallback($processedAttachment, $extension, $mimeType),
        };
    }

    private function isTextDocument(string $extension, string $mimeType): bool
    {
        return in_array($extension, ['txt', 'md', 'csv', 'json'], true)
            || in_array($mimeType, ['text/plain', 'text/csv', 'application/json'], true);
    }

    private function isSpreadsheet(string $extension, string $mimeType): bool
    {
        return in_array($extension, ['xlsx', 'xlsm', 'xls', 'csv'], true)
            || in_array($mimeType, [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel.sheet.macroenabled.12',
                'application/vnd.ms-excel',
                'text/csv',
            ], true);
    }

    private function isPdf(string $extension, string $mimeType): bool
    {
        return $extension === 'pdf' || $mimeType === 'application/pdf';
    }

    private function isImage(string $extension, string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/')
            || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic'], true);
    }

    private function isAudio(string $extension, string $mimeType): bool
    {
        return str_starts_with($mimeType, 'audio/')
            || in_array($extension, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'webm'], true);
    }

    private function extractTextDocument(UploadedFile $file, string $extension): array
    {
        $raw = (string) file_get_contents($file->getRealPath());
        $decoded = $this->decodeText($raw);

        if ($extension === 'json') {
            $decoded = $this->prettyJson($decoded);
        }

        return [
            'success' => trim($decoded) !== '',
            'mode' => $extension !== '' ? $extension : 'text',
            'content' => trim($decoded) !== '' ? trim($decoded) : null,
            'message' => trim($decoded) !== '' ? null : 'O documento foi recebido, mas não retornou conteúdo textual útil.',
            'pending_async' => false,
        ];
    }

    private function extractSpreadsheet(UploadedFile $file, string $extension): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $lines = [];

            foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                $lines[] = '### Planilha: ' . $worksheet->getTitle();

                foreach ($worksheet->getRowIterator() as $row) {
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(false);

                    $values = [];
                    foreach ($cellIterator as $cell) {
                        $value = trim((string) $cell->getFormattedValue());
                        if ($value !== '') {
                            $values[] = preg_replace('/\s+/', ' ', $value);
                        }
                    }

                    if ($values !== []) {
                        $lines[] = implode(' | ', $values);
                    }
                }
            }

            $content = trim(implode("\n", $lines));

            return [
                'success' => $content !== '',
                'mode' => $extension !== '' ? $extension : 'spreadsheet',
                'content' => $content !== '' ? $content : null,
                'message' => $content !== '' ? null : 'A planilha foi recebida, mas não retornou linhas legíveis para indexação.',
                'pending_async' => false,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'mode' => $extension !== '' ? $extension : 'spreadsheet',
                'content' => null,
                'message' => 'Não foi possível extrair o conteúdo da planilha para indexação imediata.',
                'pending_async' => true,
            ];
        }
    }

    private function extractImage(?array $processedAttachment): array
    {
        $content = trim(implode("\n\n", array_filter([
            isset($processedAttachment['resumo']) ? 'Resumo: ' . trim((string) $processedAttachment['resumo']) : null,
            isset($processedAttachment['texto_extraido']) ? 'Texto extraído: ' . trim((string) $processedAttachment['texto_extraido']) : null,
            !empty($processedAttachment['itens_relevantes']) ? 'Itens relevantes: ' . implode('; ', array_map('strval', (array) $processedAttachment['itens_relevantes'])) : null,
            isset($processedAttachment['proxima_acao_sugerida']) ? 'Próxima ação sugerida: ' . trim((string) $processedAttachment['proxima_acao_sugerida']) : null,
        ])));

        return [
            'success' => $content !== '',
            'mode' => 'image',
            'content' => $content !== '' ? $content : null,
            'message' => $content !== '' ? null : 'A imagem foi salva, mas a leitura visual ainda não gerou conteúdo suficiente para indexação imediata.',
            'pending_async' => $content === '',
        ];
    }

    private function extractAudio(?array $processedAttachment): array
    {
        $content = trim(implode("\n\n", array_filter([
            isset($processedAttachment['transcricao']) ? 'Transcrição: ' . trim((string) $processedAttachment['transcricao']) : null,
            isset($processedAttachment['resumo_curto']) ? 'Resumo: ' . trim((string) $processedAttachment['resumo_curto']) : null,
        ])));

        return [
            'success' => $content !== '',
            'mode' => 'audio',
            'content' => $content !== '' ? $content : null,
            'message' => $content !== '' ? null : 'O áudio foi salvo, mas a transcrição não gerou conteúdo suficiente para indexação imediata.',
            'pending_async' => $content === '',
        ];
    }

    private function extractFromProcessedFallback(?array $processedAttachment, string $extension, string $mimeType): array
    {
        $content = trim(implode("\n\n", array_filter([
            isset($processedAttachment['resumo']) ? 'Resumo: ' . trim((string) $processedAttachment['resumo']) : null,
            isset($processedAttachment['mensagem']) ? 'Mensagem: ' . trim((string) $processedAttachment['mensagem']) : null,
            isset($processedAttachment['texto_extraido']) ? 'Texto extraído: ' . trim((string) $processedAttachment['texto_extraido']) : null,
            isset($processedAttachment['transcricao']) ? 'Transcrição: ' . trim((string) $processedAttachment['transcricao']) : null,
        ])));

        return [
            'success' => $content !== '',
            'mode' => $extension !== '' ? $extension : ($mimeType !== '' ? $mimeType : 'unknown'),
            'content' => $content !== '' ? $content : null,
            'message' => $content !== ''
                ? null
                : 'O arquivo foi salvo, mas a indexação imediata depende da ingestão assíncrona pelo Google Drive/n8n.',
            'pending_async' => true,
        ];
    }

    private function decodeText(string $raw): string
    {
        foreach (['UTF-8', 'ISO-8859-1', 'Windows-1252'] as $encoding) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', $encoding);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return $raw;
    }

    private function prettyJson(string $content): string
    {
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            return $content;
        }

        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $content;
    }
}
