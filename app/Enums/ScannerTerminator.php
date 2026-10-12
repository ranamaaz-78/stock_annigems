<?php

namespace App\Enums;

/**
 * ScannerTerminator
 *
 * Key the wedge scanner sends at the end of a burst. Global because shops
 * standardise on one model.
 */
enum ScannerTerminator: string
{
    case ENTER = 'enter';
    case TAB = 'tab';
    case BOTH = 'both';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::ENTER => 'Enter',
            self::TAB => 'Tab',
            self::BOTH => 'Enter or Tab',
        };
    }

    /**
     * Keyboard event.key values the scanner helper should treat as end-of-scan.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return match ($this) {
            self::ENTER => ['Enter'],
            self::TAB => ['Tab'],
            self::BOTH => ['Enter', 'Tab'],
        };
    }
}
