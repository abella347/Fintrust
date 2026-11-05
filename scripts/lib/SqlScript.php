<?php

declare(strict_types=1);

final class SqlScript
{
    /**
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $delimiter = ';';
        $buffer = '';
        $len = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $atLineStart = $i === 0 || $sql[$i - 1] === "\n";

            if ($quote === null) {
                // DELIMITER command (only valid at the start of a line).
                if ($atLineStart && strncasecmp(substr($sql, $i, 10), 'DELIMITER ', 10) === 0) {
                    $end = strpos($sql, "\n", $i) ?: $len;
                    $delimiter = trim(substr($sql, $i + 10, $end - $i - 10));
                    $i = $end;
                    continue;
                }
                // Comments
                if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
                    $i = (strpos($sql, "\n", $i) ?: $len) - 1;
                    continue;
                }
                if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                    $i = (strpos($sql, '*/', $i + 2) ?: $len) + 1;
                    continue;
                }
                // End of statement
                if (substr($sql, $i, strlen($delimiter)) === $delimiter) {
                    if (trim($buffer) !== '') {
                        $statements[] = trim($buffer);
                    }
                    $buffer = '';
                    $i += strlen($delimiter) - 1;
                    continue;
                }
                if ($c === "'" || $c === '"' || $c === '`') {
                    $quote = $c;
                }
            } elseif ($c === '\\') {
                $buffer .= $c . ($sql[$i + 1] ?? '');
                $i++;
                continue;
            } elseif ($c === $quote) {
                $quote = null;
            }
            $buffer .= $c;
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }
        return $statements;
    }
}
