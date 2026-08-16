<?php

declare(strict_types=1);

class VestaboardGenerator extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('Align', 1); // 0 = Left, 1 = Center
        $this->RequireParent('{6EACDB31-15B1-4043-98D7-1750239A060A}');

        $this->RegisterVariableString('Message', 'Nachricht', '', 1);
        $this->EnableAction('Message');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Message') {
            $this->SetValue($Ident, $Value);
            $this->SendTextToBoard((string)$Value);
        }
    }

    public function SendTextToBoard(string $text)
    {
        $layout = $this->ConvertTextToArray($text);
        $this->SendLayout($layout);
    }
    
    public function SendLayout(array $layout)
    {
        $this->SendDataToParent(json_encode([
            'DataID' => '{2579048E-50DD-4B1C-B7D9-11B3ADFA53DD}',
            'Command' => 'UpdateLayout',
            'Layout' => $layout
        ]));
    }

    private function ConvertTextToArray(string $text): array
    {
        $align = $this->ReadPropertyInteger('Align');
        $lines = explode("\n", $text);
        
        $layout = array_fill(0, 6, array_fill(0, 22, 0));
        
        $charMap = [
            ' ' => 0,
            '!' => 37, '@' => 38, '#' => 39, '$' => 40, '(' => 41, ')' => 42,
            '-' => 44, '+' => 46, '&' => 47, '=' => 48, ';' => 49, ':' => 50,
            '\'' => 52, '"' => 53, '%' => 54, ',' => 55, '.' => 56, '/' => 59,
            '?' => 60, '°' => 62
        ];

        $parsedLines = [];
        foreach ($lines as $line) {
            $tokens = [];
            // Split string keeping the {XX} color/special codes intact
            $parts = preg_split('/(\{[\d]+\})/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            
            foreach ($parts as $part) {
                if (preg_match('/^\{(\d+)\}$/', $part, $matches)) {
                    $tokens[] = (int)$matches[1];
                } else {
                    $chars = mb_str_split($part, 1, 'UTF-8');
                    foreach ($chars as $char) {
                        $char = mb_strtoupper($char, 'UTF-8');
                        if (preg_match('/^[A-Z]$/', $char)) {
                            $tokens[] = ord($char) - ord('A') + 1;
                        } elseif (preg_match('/^[1-9]$/', $char)) {
                            $tokens[] = (int)$char + 26;
                        } elseif ($char === '0') {
                            $tokens[] = 36;
                        } elseif (isset($charMap[$char])) {
                            $tokens[] = $charMap[$char];
                        } elseif ($char === 'Ä') {
                            $tokens[] = ord('A') - ord('A') + 1;
                            $tokens[] = ord('E') - ord('A') + 1;
                        } elseif ($char === 'Ö') {
                            $tokens[] = ord('O') - ord('A') + 1;
                            $tokens[] = ord('E') - ord('A') + 1;
                        } elseif ($char === 'Ü') {
                            $tokens[] = ord('U') - ord('A') + 1;
                            $tokens[] = ord('E') - ord('A') + 1;
                        } elseif ($char === 'ß') {
                            $tokens[] = ord('S') - ord('A') + 1;
                            $tokens[] = ord('S') - ord('A') + 1;
                        } else {
                            $tokens[] = 0; // Space as fallback for unknown characters
                        }
                    }
                }
            }
            
            // Split tokens into chunks of 22 (Vestaboard width) if a line is too long
            if (empty($tokens)) {
                $parsedLines[] = [];
            } else {
                $chunks = array_chunk($tokens, 22);
                foreach ($chunks as $chunk) {
                    $parsedLines[] = $chunk;
                }
            }
        }
        
        $maxLines = min(6, count($parsedLines));
        
        $startRow = 0;
        if ($align === 1) { // Center (Vertical)
            $startRow = (int)floor((6 - count($parsedLines)) / 2);
            if ($startRow < 0) $startRow = 0;
        }

        for ($r = 0; $r < $maxLines; $r++) {
            $rowTokens = $parsedLines[$r];
            $tokenCount = count($rowTokens);
            
            $startCol = 0;
            if ($align === 1) { // Center (Horizontal)
                $startCol = (int)floor((22 - $tokenCount) / 2);
                if ($startCol < 0) $startCol = 0;
            }
            
            $targetRow = $startRow + $r;
            if ($targetRow < 6) {
                for ($c = 0; $c < $tokenCount; $c++) {
                    if ($startCol + $c < 22) {
                        $layout[$targetRow][$startCol + $c] = $rowTokens[$c];
                    }
                }
            }
        }

        return $layout;
    }
}
