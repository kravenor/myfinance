<?php

namespace App\Services\Import;

use Illuminate\Http\UploadedFile;

class OfxReader extends ImportReader
{
    public function format(): string
    {
        return 'ofx';
    }

    public function read(UploadedFile $file, int $limit): array
    {
        $content = $this->load($file);

        $rows = [];
        if (preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/si', $content, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (count($rows) >= $limit) {
                    break;
                }

                $amount = $this->tag($block, 'TRNAMT');
                $name = $this->tag($block, 'NAME');
                $memo = $this->tag($block, 'MEMO');

                $rows[] = [
                    'date' => $this->normalizeDate($this->tag($block, 'DTPOSTED')),
                    'amount' => $amount,
                    'description' => $name !== '' ? $name : $memo,
                    'type' => $this->normalizeType($this->tag($block, 'TRNTYPE'), $amount),
                    'external_id' => $this->tag($block, 'FITID'),
                ];
            }
        }

        return [
            'headers' => ['date', 'amount', 'description', 'type', 'external_id'],
            'rows' => $rows,
            'mapping_locked' => true,
        ];
    }

    public function suggestedMapping(array $headers): array
    {
        return [
            'date' => 'date',
            'amount' => 'amount',
            'description' => 'description',
            'type' => 'type',
            'external_id' => 'external_id',
        ];
    }

    private function load(UploadedFile $file): string
    {
        $content = (string) file_get_contents($file->getRealPath());

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }

        return $content;
    }

    /**
     * Estrae il valore di un tag leaf, robusto sia su OFX 2.x (XML) sia su 1.x (SGML).
     */
    private function tag(string $block, string $tag): string
    {
        if (preg_match('/<'.$tag.'>([^<\r\n]*)/i', $block, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    /**
     * DTPOSTED: YYYYMMDD[HHMMSS[.XXX]][TZ] -> YYYY-MM-DD.
     */
    private function normalizeDate(string $raw): string
    {
        $raw = trim($raw);
        if (strlen($raw) < 8) {
            return '';
        }

        return substr($raw, 0, 4).'-'.substr($raw, 4, 2).'-'.substr($raw, 6, 2);
    }

    private function normalizeType(string $trnType, string $amount): string
    {
        $type = strtoupper(trim($trnType));

        if (in_array($type, ['CREDIT', 'DEP', 'DIRECTDEP', 'INT', 'DIV', 'XIN'], true)) {
            return 'income';
        }
        if (in_array($type, ['DEBIT', 'PAYMENT', 'POS', 'ATM', 'FEE', 'SRVCHG', 'CHECK', 'XOUT'], true)) {
            return 'expense';
        }

        return str_starts_with(ltrim($amount), '-') ? 'expense' : 'income';
    }
}
