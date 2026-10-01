<?php

namespace App\Services;

use App\Enums\InvoicePaymentStatus;
use App\Enums\PaymentTerm;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Contracts\Config\Repository;

/**
 * Customer invoice as a shareable JPG — an independent A4 PORTRAIT document
 * render, NOT a screenshot of the mobile page.
 *
 * The sheet is A4 portrait at 150 dpi (210 × 297 mm → 1240 × 1754 px), so the
 * downloaded file can be saved on a phone, printed or forwarded through
 * instant messaging and still look like an invoice.
 *
 * Identity (UAT correction):
 *   SELLER  = the SO's supplying PRIMARY customer (name, warehouse/address,
 *             phone, email) — the Distributor who made the sale;
 *   BILL TO = the SO's sold-to SECONDARY customer (the financial debtor);
 *   FOOTER  = "Powered by {employee_id} - {employee_name} / {company_name}"
 *             with a "Scan Me" QR of the read-only public invoice URL. The raw
 *             URL is never printed next to the QR.
 *
 * Conditional display is preserved: a component with no value is not rendered
 * (no Tax row when tax is not applicable; no Discount/Settled/Outstanding row
 * when zero; due date only for PAY_LATER). Nothing is ever invented.
 */
class InvoiceImageService
{
    private const MARGIN = 56;

    /** Header geometry: baseline of the first header line. */
    private const HEADER_TOP = 96;

    /** Minimum clear space between the seller column and the meta column. */
    private const HEADER_GUTTER = 48;

    /** The seller column never measures narrower than this (px). */
    private const HEADER_MIN_LEFT = 300;

    /** Extra leading between two header lines, on top of the type size. */
    private const HEADER_LINE_GAP = 8;

    /**
     * Floor for the uniform header type scale. A header string that still does
     * not fit at this size is WRAPPED inside its column instead of overflowing.
     */
    private const HEADER_MIN_SCALE = 0.6;

    /** Pattern matching the separators an identifier (invoice/SO number) may wrap after. */
    private const HEADER_BREAK_AFTER = '#(?<=[/_.\-])#u';

    /** A4 sheet in millimetres — the page is always this aspect ratio. */
    private const A4_WIDTH_MM = 210;

    private const A4_HEIGHT_MM = 297;

    private const INK = 0x1B1B1F;

    private const MUTED = 0x5F6368;

    private const RULE = 0xDADCE0;

    private const BAND = 0xF4F6FB;

    private const ACCENT = 0x0B57D0;

    private const PAID = 0x146C2E;

    /** @var resource|\GdImage|null */
    private $image = null;

    private int $width;

    private int $y = 0;

    /**
     * Every string drawn onto the document, in draw order. This exists so the
     * document content is verifiable without OCR: the layout regression test
     * asserts what the customer actually receives (seller identity, powered-by
     * line, and the ABSENCE of the raw public URL).
     *
     * @var array<int, string>
     */
    private array $drawn = [];

    public function __construct(
        private readonly QrCodeService $qr,
        private readonly InvoicePartyService $parties,
        private readonly Repository $config,
    ) {
        $this->width = (int) $this->config->get('erp.invoice_image.width', 1240);
    }

    /** The A4 portrait sheet size in pixels: [width, height]. */
    public function pageSize(): array
    {
        return [
            $this->width,
            (int) round($this->width * self::A4_HEIGHT_MM / self::A4_WIDTH_MM),
        ];
    }

    /**
     * The text drawn by the most recent render (see $drawn).
     *
     * @return array<int, string>
     */
    public function drawnText(): array
    {
        return $this->drawn;
    }

    /**
     * Rendered JPEG bytes (throws when GD is unavailable).
     *
     * $publicUrl is the read-only customer URL embedded as a QR code; the
     * caller resolves it from the invoice's public token.
     */
    public function jpeg(Invoice $invoice, ?string $publicUrl = null): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            abort(500, 'The GD extension is required to render the invoice image.');
        }

        $invoice->loadMissing(['items.product', 'customer', 'company', 'salesOrder']);

        [, $pageHeight] = $this->pageSize();

        // Working canvas: tall enough for the WHOLE document at natural size.
        // It is composed onto the A4 sheet below (never cropped).
        $canvasHeight = min(12000, 1300 + max(1, $invoice->items->count()) * 110);

        $this->image = imagecreatetruecolor($this->width, $canvasHeight);
        $this->fill(0xFFFFFF);
        $this->y = 0;
        $this->drawn = [];

        $parties = $this->parties->parties($invoice);

        $this->drawHeader($invoice, $parties);
        $this->drawBillTo($parties);
        $this->drawItems($invoice);
        $this->drawTotals($invoice);

        $footerTop = $this->y;
        $this->drawFooter($parties, $publicUrl);

        $document = $this->image;
        $documentHeight = $this->y;

        $this->image = $this->compose($document, $documentHeight, $footerTop, $pageHeight);

        if ($document !== $this->image) {
            imagedestroy($document);
        }

        ob_start();
        imagejpeg($this->image, null, (int) $this->config->get('erp.invoice_image.quality', 88));
        $bytes = (string) ob_get_clean();

        imagedestroy($this->image);
        $this->image = null;

        return $bytes;
    }

    /**
     * Compose the natural-size document onto the A4 sheet.
     *
     * Fits comfortably  → the sheet simply has white space below the content
     *                     and the footer is pinned to the bottom of the page;
     * Longer than a page → the whole document is scaled uniformly (aspect and
     *                     proportions preserved) so nothing is ever cropped.
     *
     * @param  resource|\GdImage  $document  natural-size document
     * @param  int  $documentHeight  its used height
     * @param  int  $footerTop  where the footer strip starts on the document
     * @param  int  $pageHeight  the A4 sheet height
     * @return resource|\GdImage
     */
    private function compose($document, int $documentHeight, int $footerTop, int $pageHeight)
    {
        $page = imagecreatetruecolor($this->width, $pageHeight);
        $white = imagecolorallocate($page, 0xFF, 0xFF, 0xFF);
        imagefilledrectangle($page, 0, 0, $this->width, $pageHeight, $white);

        if ($documentHeight <= $pageHeight) {
            // Body (everything above the footer)…
            if ($footerTop > 0) {
                imagecopy($page, $document, 0, 0, 0, 0, $this->width, $footerTop);
            }

            // …then the footer strip, pinned to the bottom of the sheet.
            $stripHeight = $documentHeight - $footerTop;
            $target = max($footerTop, $pageHeight - self::MARGIN - $stripHeight);
            imagecopy($page, $document, 0, $target, 0, $footerTop, $this->width, $documentHeight - $footerTop);

            return $page;
        }

        $factor = $pageHeight / $documentHeight;
        $scaledWidth = max(1, (int) round($this->width * $factor));
        $scaled = imagescale($document, $scaledWidth, $pageHeight, IMG_BILINEAR_FIXED);

        if ($scaled !== false) {
            imagecopy($page, $scaled, (int) round(($this->width - $scaledWidth) / 2), 0, 0, 0, $scaledWidth, $pageHeight);
            imagedestroy($scaled);

            return $page;
        }

        // Extremely defensive fallback: never lose the document.
        imagecopy($page, $document, 0, 0, 0, 0, $this->width, $pageHeight);

        return $page;
    }

    // ---- primitives ---------------------------------------------------------

    private function fill(int $rgb): void
    {
        imagefilledrectangle(
            $this->image, 0, 0, $this->width, imagesy($this->image),
            imagecolorallocate($this->image, ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF),
        );
    }

    private function colour(int $rgb)
    {
        return imagecolorallocate($this->image, ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
    }

    private function band(int $y, int $height, int $rgb): void
    {
        imagefilledrectangle(
            $this->image, 0, $y, $this->width, $y + $height,
            imagecolorallocate($this->image, ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF),
        );
    }

    private function font(bool $bold): ?string
    {
        $candidates = (array) $this->config->get(
            $bold ? 'erp.invoice_image.font_bold_candidates' : 'erp.invoice_image.font_candidates',
            [],
        );

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function textWidth(string $text, float $size, bool $bold): float
    {
        $font = $this->font($bold);

        if ($font === null) {
            return strlen($text) * $size * 0.62;
        }

        $box = imagettfbbox($size, 0, $font, $text);

        return (float) (max($box[0], $box[2], $box[4], $box[6]) - min($box[0], $box[2], $box[4], $box[6]));
    }

    /** Draws text with its BASELINE at $baselineY; returns the line height. */
    private function text(string $text, int $x, int $baselineY, float $size, int $rgb = self::INK, bool $bold = false, string $align = 'left'): float
    {
        $font = $this->font($bold);
        $colour = $this->colour($rgb);

        if (trim($text) !== '') {
            $this->drawn[] = $text;
        }

        if ($align === 'right') {
            $x = (int) round($x - $this->textWidth($text, $size, $bold));
        } elseif ($align === 'center') {
            $x = (int) round($x - $this->textWidth($text, $size, $bold) / 2);
        }

        if ($font === null) {
            // Degraded fallback: built-in bitmap font (never silently drops content).
            imagestring($this->image, 5, $x, (int) ($baselineY - $size), $text, $colour);

            return $size * 1.4;
        }

        imagettftext($this->image, $size, 0, $x, $baselineY, $colour, $font, $text);

        return $size * 1.4;
    }

    private function rule(int $y, int $rgb = self::RULE): void
    {
        imagefilledrectangle($this->image, self::MARGIN, $y, $this->width - self::MARGIN, $y + 1, $this->colour($rgb));
    }

    /** @return array<int, string> */
    private function wrap(string $text, float $size, float $maxWidth, bool $bold = false): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($this->textWidth($candidate, $size, $bold) <= $maxWidth || $current === '') {
                $current = $candidate;

                continue;
            }

            $lines[] = $current;
            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    private function money(string $amount, string $currency): string
    {
        return $currency.' '.number_format((float) $amount, 2);
    }

    private function qty(string $quantity, string $unit): string
    {
        $value = (float) $quantity;
        $formatted = abs($value - round($value)) < 0.0005
            ? number_format($value, 0)
            : rtrim(rtrim(number_format($value, 3), '0'), '.');

        return $formatted.' '.$unit;
    }

    // ---- sections -----------------------------------------------------------

    /**
     * Header geometry — the SINGLE source of truth for both the drawing and
     * the collision regression test.
     *
     * The header is two MEASURED columns:
     *   LEFT   the seller (supplying Primary): name, address, contact, warehouse;
     *   RIGHT  the invoice meta stack: INVOICE title, invoice number, invoice
     *          date, sales order number, optional due date.
     *
     * Guarantees (asserted, not hoped for):
     *   - the meta column is measured from the ACTUAL strings; a long invoice
     *     number shrinks the meta type uniformly instead of running into the
     *     seller column or off the sheet, and the seller column keeps a minimum
     *     width;
     *   - every box in the right column sits STRICTLY below the box above it,
     *     derived from the type size — the title can never overlap the number;
     *   - no line leaves the A4 text area.
     *
     * @return array<string, mixed>
     */
    public function headerGeometry(Invoice $invoice): array
    {
        $invoice->loadMissing(['company', 'customer', 'salesOrder']);

        return $this->measuredHeader($invoice, $this->parties->parties($invoice));
    }

    /**
     * Seller block: the supplying Primary (the party that made the sale), with
     * the warehouse address the goods actually left from. The company is NOT
     * the seller — it appears only in the "Powered by" footer.
     *
     * @param  array<string, mixed>  $parties
     */
    private function drawHeader(Invoice $invoice, array $parties): void
    {
        $this->band(0, 10, self::ACCENT);

        $geometry = $this->measuredHeader($invoice, $parties);

        // LEFT: seller identity, wrapped inside its measured column.
        foreach ($geometry['left']['lines'] as $line) {
            $this->text(
                $line['text'], self::MARGIN, $line['baseline'], $line['size'],
                $line['tone'] === 'ink' ? self::INK : self::MUTED, $line['bold'],
            );
        }

        // RIGHT: title first, then each meta line below it.
        $title = $geometry['title'];
        $this->text($title['text'], $title['right'], $title['baseline'], $title['size'], self::ACCENT, true, 'right');

        foreach ($geometry['meta']['lines'] as $line) {
            $this->text(
                $line['text'], $line['right'], $line['baseline'], $line['size'],
                $line['tone'] === 'ink' ? self::INK : self::MUTED, $line['bold'], 'right',
            );
        }

        $this->y = (int) $geometry['bottom'];
        $this->rule($this->y);
        $this->y += 36;
    }

    /**
     * @param  array<string, mixed>  $parties
     * @return array<string, mixed>
     */
    private function measuredHeader(Invoice $invoice, array $parties): array
    {
        $right = $this->width - self::MARGIN;
        $available = $right - self::MARGIN;

        // Right column content, in hierarchy order (title → number → date → SO).
        $rows = [
            ['text' => 'INVOICE', 'size' => 38.0, 'bold' => true, 'tone' => 'accent', 'role' => 'title'],
            ['text' => (string) $invoice->invoice_no, 'size' => 20.0, 'bold' => true, 'tone' => 'ink', 'role' => 'meta'],
            ['text' => 'Invoice date '.$invoice->invoice_date->format('d M Y'), 'size' => 19.0, 'bold' => false, 'tone' => 'muted', 'role' => 'meta'],
            ['text' => 'Sales order '.$invoice->sales_order_no, 'size' => 19.0, 'bold' => false, 'tone' => 'muted', 'role' => 'meta'],
        ];

        // Due date only carries information for PAY_LATER terms.
        if ($invoice->payment_term === PaymentTerm::PAY_LATER && $invoice->due_date !== null) {
            $rows[] = ['text' => 'Due '.$invoice->due_date->format('d M Y'), 'size' => 19.0, 'bold' => false, 'tone' => 'muted', 'role' => 'meta'];
        }

        // The meta column may never be wider than what is left once the seller
        // column has its guaranteed minimum — that is the measured budget.
        $maxMeta = max(200.0, $available - self::HEADER_MIN_LEFT - self::HEADER_GUTTER);

        // Uniform type scale: the widest meta string decides how much the whole
        // meta stack shrinks. A long invoice number therefore becomes a smaller
        // number — never an overlapping one.
        $widest = 0.0;

        foreach ($rows as $row) {
            $widest = max($widest, $this->textWidth($row['text'], $row['size'], $row['bold']));
        }

        $scale = ($widest > $maxMeta && $widest > 0)
            ? max(self::HEADER_MIN_SCALE, $maxMeta / $widest)
            : 1.0;

        // Every meta row is wrapped inside the measured budget, so even a string
        // that is wide at ANY size (a 100-character invoice number) stays inside
        // its column: identifiers break after their separators, prose wraps.
        $wrapped = [];

        foreach ($rows as $row) {
            $size = round($row['size'] * $scale, 2);
            $fitted = $this->fitLines($row['text'], $size, $maxMeta, $row['bold']);

            foreach ($fitted['lines'] as $line) {
                $wrapped[] = [
                    'text' => $line,
                    'role' => $row['role'],
                    'tone' => $row['tone'],
                    'size' => $fitted['size'],
                    'bold' => $row['bold'],
                ];
            }
        }

        // Lay the right column out with MEASURED font metrics. Every line is
        // placed so that its ink box starts at least HEADER_LINE_GAP below the
        // ink box of the line above it, so no two lines can share a baseline or
        // touch — a guarantee that holds for the typeface actually installed.
        $boxes = [];
        $baseline = self::HEADER_TOP;
        $previousBottom = null;

        foreach ($wrapped as $row) {
            $size = $row['size'];
            $width = $this->textWidth($row['text'], $size, $row['bold']);
            [$topOffset, $bottomOffset] = $this->inkOffsets($row['text'], $size, $row['bold']);

            if ($previousBottom !== null) {
                $baseline = max($baseline, $previousBottom + self::HEADER_LINE_GAP - $topOffset);
            }

            $box = [
                'text' => $row['text'],
                'role' => $row['role'],
                'tone' => $row['tone'],
                'size' => $size,
                'bold' => $row['bold'],
                'baseline' => $baseline,
                'top' => $baseline + $topOffset,
                'bottom' => $baseline + $bottomOffset,
                'width' => (int) ceil($width),
                'left' => (int) floor($right - $width),
                'right' => $right,
            ];

            $boxes[] = $box;
            $previousBottom = $box['bottom'];
            $baseline += (int) ceil($size * 1.4) + self::HEADER_LINE_GAP;
        }

        $title = array_shift($boxes);
        $metaLines = $boxes;
        $metaWidth = 0.0;

        foreach (array_merge([$title], $metaLines) as $box) {
            $metaWidth = max($metaWidth, (float) $box['width']);
        }

        // Seller column: everything it needs, measured, never touching the meta.
        $leftMaxRight = (int) floor($right - $metaWidth - self::HEADER_GUTTER);
        $leftWidth = max(120.0, $leftMaxRight - self::MARGIN);

        $name = $this->fitLines((string) $parties['seller']['name'], 32.0, $leftWidth, true);
        $contact = collect([$parties['seller']['phone'], $parties['seller']['email']])
            ->filter()
            ->implode('  ·  ');
        $address = trim(implode(', ', $parties['seller']['lines']));

        $warehouse = '';

        if ($parties['warehouse'] !== null) {
            $warehouse = 'Warehouse · '.$parties['warehouse']['name'];
            $warehouseAddress = trim(implode(', ', $parties['warehouse']['lines']));

            if ($warehouseAddress !== '') {
                $warehouse .= ' — '.$warehouseAddress;
            }
        }

        $blocks = [
            ['text' => $name['lines'], 'size' => $name['size'], 'bold' => true, 'tone' => 'ink'],
            ['text' => $address === '' ? [] : $this->fitLines($address, 19.0, $leftWidth)['lines'], 'size' => 19.0, 'bold' => false, 'tone' => 'muted'],
            ['text' => $contact === '' ? [] : $this->fitLines($contact, 19.0, $leftWidth)['lines'], 'size' => 19.0, 'bold' => false, 'tone' => 'muted'],
            ['text' => $warehouse === '' ? [] : $this->fitLines($warehouse, 18.0, $leftWidth)['lines'], 'size' => 18.0, 'bold' => false, 'tone' => 'muted'],
        ];

        $lines = [];
        $leftBaseline = self::HEADER_TOP;
        $leftPreviousBottom = null;

        foreach ($blocks as $block) {
            foreach ($block['text'] as $line) {
                $width = $this->textWidth($line, $block['size'], $block['bold']);
                [$topOffset, $bottomOffset] = $this->inkOffsets($line, $block['size'], $block['bold']);

                if ($leftPreviousBottom !== null) {
                    $leftBaseline = max($leftBaseline, $leftPreviousBottom + self::HEADER_LINE_GAP - $topOffset);
                }

                $entry = [
                    'text' => $line,
                    'size' => $block['size'],
                    'bold' => $block['bold'],
                    'tone' => $block['tone'],
                    'baseline' => $leftBaseline,
                    'top' => $leftBaseline + $topOffset,
                    'bottom' => $leftBaseline + $bottomOffset,
                    'width' => (int) ceil($width),
                    'right' => (int) round(self::MARGIN + $width),
                ];

                $lines[] = $entry;
                $leftPreviousBottom = $entry['bottom'];
                $leftBaseline += (int) ceil($block['size'] * 1.4) + 2;
            }
        }

        $lastMeta = $metaLines === [] ? $title : $metaLines[array_key_last($metaLines)];
        $leftBottom = $lines === [] ? self::HEADER_TOP : $lines[array_key_last($lines)]['bottom'];

        return [
            'sheet' => [
                'width' => $this->width,
                'height' => $this->pageSize()[1],
                'margin' => self::MARGIN,
                'gutter' => self::HEADER_GUTTER,
                'min_left' => self::HEADER_MIN_LEFT,
            ],
            'scale' => round($scale, 4),
            'title' => $title,
            'meta' => [
                'left' => (int) floor($right - $metaWidth),
                'right' => $right,
                'width' => (int) ceil($metaWidth),
                'lines' => $metaLines,
                'bottom' => (int) $lastMeta['bottom'],
            ],
            'left' => [
                'x' => self::MARGIN,
                // The reported width rounds UP like the measured line widths do,
                // so "every line fits its column" is an exact comparison.
                'width' => (int) ceil($leftWidth),
                'max_right' => $leftMaxRight,
                'lines' => $lines,
                'bottom' => (int) $leftBottom,
            ],
            'bottom' => max((int) $lastMeta['bottom'], (int) $leftBottom) + 22,
        ];
    }

    /**
     * The ink box of one line, RELATIVE to its baseline, from the real font
     * metrics of the installed typeface.
     *
     * The box is inflated by one pixel: antialiasing paints a faint edge
     * outside the reported box, and the layout guarantee must hold for the
     * pixels that actually land on the sheet.
     *
     * @return array{0: int, 1: int} [top offset (≤ 0), bottom offset]
     */
    private function inkOffsets(string $text, float $size, bool $bold): array
    {
        $font = $this->font($bold);

        if ($font !== null) {
            // Descenders are always allowed for, even when this particular
            // string has none: the guarantee must hold for any invoice data.
            $box = imagettfbbox($size, 0, $font, ($text === '' ? 'M' : $text).'Mg');
            $top = (int) floor(min($box[5], $box[7]));
            $bottom = (int) ceil(max($box[1], $box[3]));

            return [min(-1, $top - 1), max(1, $bottom + 1)];
        }

        // Degraded built-in bitmap font: imagestring() draws from
        // (x, baselineY - size) and is ~15 px tall regardless of the type size.
        return [-(int) ceil($size) - 1, max(1, 15 - (int) ceil($size) + 1)];
    }

    /**
     * Text as one or more lines that ALL measure within $maxWidth.
     *
     * The type is shrunk for a single-line fit first; if a block must wrap, each
     * resulting line is checked, and an unbreakable token (a very long word or
     * number) is split rather than allowed to overflow — no content is dropped.
     *
     * @return array{size: float, lines: array<int, string>}
     */
    private function fitLines(string $text, float $size, float $maxWidth, bool $bold = false): array
    {
        $text = trim($text);

        if ($text === '') {
            return ['size' => $size, 'lines' => []];
        }

        $single = $this->textWidth($text, $size, $bold);

        if ($single > $maxWidth && $single > 0) {
            $size = max(9.0, $size * max(self::HEADER_MIN_SCALE, $maxWidth / $single));
        }

        $lines = [];

        // The wrap is measured with the SAME face the line is drawn with: a bold
        // block measured against the regular face can overshoot its column.
        foreach ($this->wrap($text, $size, $maxWidth, $bold) as $line) {
            if ($this->textWidth($line, $size, $bold) <= $maxWidth) {
                $lines[] = $line;

                continue;
            }

            // A single token wider than the column: it becomes as few lines as
            // possible, breaking after separators first (readable), then by
            // character — the content is never dropped.
            foreach ($this->splitOverwide($line, $size, $maxWidth, $bold) as $broken) {
                $lines[] = $broken;
            }
        }

        return ['size' => round($size, 2), 'lines' => $lines];
    }

    /**
     * The lines an over-wide line must become: pieces are packed greedily up to
     * the column width, and a piece that cannot fit even alone is split by
     * character (never truncated, never dropped).
     *
     * @return array<int, string>
     */
    private function splitOverwide(string $line, float $size, float $maxWidth, bool $bold): array
    {
        $lines = [];
        $current = '';

        foreach ($this->breakToken($line) as $piece) {
            foreach ($this->charChunks($piece, $size, $maxWidth, $bold) as $chunk) {
                if ($current !== '' && $this->textWidth($current.$chunk, $size, $bold) > $maxWidth) {
                    $lines[] = $current;
                    $current = '';
                }

                $current .= $chunk;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [$line] : $lines;
    }

    /**
     * Split an over-long token into readable pieces at its separators
     * (INV-EMANL-2026-00001 → INV- · EMANL- · 2026- · 00001). The whole token is
     * returned when it has no usable separator.
     *
     * @return array<int, string>
     */
    private function breakToken(string $token): array
    {
        $pieces = preg_split(self::HEADER_BREAK_AFTER, $token, -1, PREG_SPLIT_NO_EMPTY);

        return ($pieces === false || count($pieces) < 2) ? [$token] : $pieces;
    }

    /**
     * Character chunks of a token that is too wide for the column at this size.
     *
     * @return array<int, string>
     */
    private function charChunks(string $token, float $size, float $maxWidth, bool $bold): array
    {
        if ($this->textWidth($token, $size, $bold) <= $maxWidth) {
            return [$token];
        }

        $chunks = [];
        $chunk = '';

        foreach (preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if ($chunk !== '' && $this->textWidth($chunk.$character, $size, $bold) > $maxWidth) {
                $chunks[] = $chunk;
                $chunk = '';
            }

            $chunk .= $character;
        }

        if ($chunk !== '') {
            $chunks[] = $chunk;
        }

        return $chunks === [] ? [$token] : $chunks;
    }

    /**
     * Bill To block: the sold-to Secondary — the FINANCIAL DEBTOR. Debtor
     * semantics are untouched; only the presentation names it as the buyer.
     *
     * @param  array<string, mixed>  $parties
     */
    private function drawBillTo(array $parties): void
    {
        $this->text('BILL TO (DEBTOR)', self::MARGIN, $this->y, 17, self::MUTED, true);
        $this->y += 32;

        $this->text($parties['debtor']['name'], self::MARGIN, $this->y, 24, self::INK, true);
        $this->y += 30;

        foreach ($parties['debtor']['lines'] as $line) {
            $this->text($line, self::MARGIN, $this->y, 19, self::MUTED);
            $this->y += 26;
        }

        if ($parties['debtor']['phone'] !== null) {
            $this->text($parties['debtor']['phone'], self::MARGIN, $this->y, 19, self::MUTED);
            $this->y += 26;
        }

        $this->y += 18;
    }

    private function drawItems(Invoice $invoice): void
    {
        $columns = $this->layoutFor($invoice)['items'];

        $this->band($this->y - 26, 42, self::BAND);
        $this->text('PRODUCT', self::MARGIN + 16, $this->y, 18, self::MUTED, true);
        $this->text('QTY', $columns['qty_right'], $this->y, 18, self::MUTED, true, 'right');
        $this->text('UNIT PRICE', $columns['price_right'], $this->y, 18, self::MUTED, true, 'right');
        $this->text('AMOUNT', $columns['amount_right'], $this->y, 18, self::MUTED, true, 'right');
        $this->y += 34;

        foreach ($invoice->items->sortBy('item_no') as $item) {
            $this->drawItemRow($invoice, $item, $columns);
        }

        $this->y += 6;
        $this->rule($this->y);
    }

    /**
     * Column geometry measured from the ACTUAL strings, so a long currency
     * amount (or a long product name) can never collide with its neighbour.
     *
     * Exposed as the layout regression contract: for every block, the label
     * column ends before the value column starts.
     *
     * @return array{items: array<string, int|float>, totals: array<string, int|float|string>}
     */
    public function layoutFor(Invoice $invoice): array
    {
        $invoice->loadMissing('items');

        $right = $this->width - self::MARGIN;
        $amountRight = $right - 16;
        $amountWidth = 0.0;
        $priceWidth = 0.0;
        $qtyWidth = 0.0;

        foreach ($invoice->items as $item) {
            $amountWidth = max(
                $amountWidth,
                $this->textWidth('FREE', 20, false),
                $this->textWidth($this->money((string) $item->subtotal_amount, $invoice->currency), 20, false),
            );
            $priceWidth = max($priceWidth, $this->textWidth($this->money((string) $item->unit_price, $invoice->currency), 20, false));
            $qtyWidth = max($qtyWidth, $this->textWidth($this->qty((string) $item->quantity, (string) $item->invoice_unit), 20, false));
        }

        $gap = 32;
        $amountLeft = (int) round($amountRight - $amountWidth);
        $priceRight = $amountLeft - $gap;
        $priceLeft = (int) round($priceRight - $priceWidth);
        $qtyRight = $priceLeft - $gap;
        $qtyLeft = (int) round($qtyRight - $qtyWidth);

        $rows = $this->totalsRows($invoice);
        $labelWidth = 0.0;
        $valueWidth = 0.0;

        foreach ($rows as $row) {
            $labelWidth = max($labelWidth, $this->textWidth($row['label'], $row['label_size'], $row['bold']));
            $valueWidth = max($valueWidth, $this->textWidth($row['value'], $row['value_size'], $row['value_bold']));
        }

        $settled = Decimal::add((string) $invoice->settled_amount, (string) $invoice->credit_amount, 2);
        $outstanding = $invoice->outstandingAmount();
        $currency = (string) $invoice->currency;

        $labelWidth = max(
            $labelWidth,
            $this->textWidth('TOTAL', 26, true),
            $this->textWidth('Settled', 22, false),
            $this->textWidth('Outstanding', 22, false),
        );

        $valueWidth = max(
            $valueWidth,
            $this->textWidth($this->money((string) $invoice->invoice_amount, $currency), 28, true),
            $this->textWidth($this->money($settled, $currency), 22, false),
            $this->textWidth($this->money($outstanding, $currency), 22, true),
        );

        $totalsLabelX = (int) round($right - $valueWidth - $gap - $labelWidth);

        return [
            'items' => [
                'right' => $right,
                'amount_right' => $amountRight,
                'amount_left' => $amountLeft,
                'price_right' => $priceRight,
                'price_left' => $priceLeft,
                'qty_right' => $qtyRight,
                'qty_left' => $qtyLeft,
                'product_left' => self::MARGIN + 16,
                'product_width' => max(120, $qtyLeft - (self::MARGIN + 16) - $gap),
            ],
            'totals' => [
                'label_x' => $totalsLabelX,
                'value_right' => $right,
                'label_width' => (int) ceil($labelWidth),
                'value_width' => (int) ceil($valueWidth),
                'label_end' => (int) round($totalsLabelX + $labelWidth),
                'value_start' => (int) round($right - $valueWidth),
            ],
        ];
    }

    /**
     * The conditional totals rows (label + value) — one source of truth for
     * BOTH measuring the geometry and drawing it, so they cannot diverge.
     *
     * @return array<int, array{label: string, label_size: float, value: string, value_size: float, bold: bool, value_bold: bool}>
     */
    private function totalsRows(Invoice $invoice): array
    {
        $currency = (string) $invoice->currency;

        $rows = [[
            'label' => 'Subtotal', 'label_size' => 22.0,
            'value' => $this->money((string) $invoice->gross_amount, $currency), 'value_size' => 22.0,
            'bold' => false, 'value_bold' => false,
        ]];

        // Conditional display: components with no value are omitted entirely.
        if (Decimal::compare((string) $invoice->discount_amount, '0', 2) > 0) {
            $rows[] = [
                'label' => 'Discount', 'label_size' => 22.0,
                'value' => '-'.$this->money((string) $invoice->discount_amount, $currency), 'value_size' => 22.0,
                'bold' => false, 'value_bold' => false,
            ];
        }

        if (Decimal::compare((string) $invoice->tax_amount, '0', 2) > 0) {
            $rows[] = [
                'label' => 'Tax', 'label_size' => 22.0,
                'value' => $this->money((string) $invoice->tax_amount, $currency), 'value_size' => 22.0,
                'bold' => false, 'value_bold' => false,
            ];
        }

        return $rows;
    }

    /** @param array<string, int|float> $columns */
    private function drawItemRow(Invoice $invoice, InvoiceItem $item, array $columns): void
    {
        $product = $item->product?->product_description ?? $item->product_id;
        $lines = $this->wrap($product, 20, (float) $columns['product_width']);
        $rowHeight = max(48, count($lines) * 27 + 22);

        $firstBaseline = $this->y + 30;

        foreach ($lines as $index => $line) {
            $this->text($line, self::MARGIN + 16, $firstBaseline + ($index * 27), 20, self::INK);
        }

        $this->text($this->qty((string) $item->quantity, (string) $item->invoice_unit), (int) $columns['qty_right'], $firstBaseline, 20, self::INK, false, 'right');
        $this->text($this->money((string) $item->unit_price, $invoice->currency), (int) $columns['price_right'], $firstBaseline, 20, self::MUTED, false, 'right');

        if ($item->is_free_item) {
            $this->text('FREE', (int) $columns['amount_right'], $firstBaseline, 20, self::MUTED, false, 'right');
        } else {
            $this->text($this->money((string) $item->subtotal_amount, $invoice->currency), (int) $columns['amount_right'], $firstBaseline, 20, self::INK, false, 'right');
        }

        $this->y += $rowHeight;
    }

    private function drawTotals(Invoice $invoice): void
    {
        $geometry = $this->layoutFor($invoice)['totals'];
        $right = (int) $geometry['value_right'];
        $labelX = (int) $geometry['label_x'];
        $currency = (string) $invoice->currency;

        $this->y += 28;

        foreach ($this->totalsRows($invoice) as $row) {
            $this->text($row['label'], $labelX, $this->y, $row['label_size'], self::MUTED, $row['bold']);
            $this->text($row['value'], $right, $this->y, $row['value_size'], self::INK, $row['value_bold'], 'right');
            $this->y += 34;
        }

        $this->y += 6;
        $this->rule($this->y - 4, self::INK);
        $this->y += 42;

        $this->text('TOTAL', $labelX, $this->y, 26, self::INK, true);
        $this->text($this->money((string) $invoice->invoice_amount, $currency), $right, $this->y, 28, self::INK, true, 'right');
        $this->y += 38;

        $settled = Decimal::add((string) $invoice->settled_amount, (string) $invoice->credit_amount, 2);

        if (Decimal::compare($settled, '0', 2) > 0) {
            $this->text('Settled', $labelX, $this->y, 22, self::MUTED);
            $this->text($this->money($settled, $currency), $right, $this->y, 22, self::INK, false, 'right');
            $this->y += 34;
        }

        $outstanding = $invoice->outstandingAmount();
        $paid = $invoice->payment_status === InvoicePaymentStatus::PAID;

        if (Decimal::compare($outstanding, '0', 2) > 0) {
            $this->text('Outstanding', $labelX, $this->y, 22, self::MUTED);
            $this->text($this->money($outstanding, $currency), $right, $this->y, 22, self::INK, true, 'right');
            $this->y += 34;
        }

        $this->text(
            $paid ? 'PAID IN FULL' : str_replace('_', ' ', $invoice->payment_status->value),
            $right, $this->y + 8, 22, $paid ? self::PAID : self::MUTED, true, 'right',
        );

        $this->y += 52;
    }

    /**
     * Footer: "Powered by {employee_id} - {employee_name} / {company_name}" and
     * the "Scan Me" QR. The raw public URL is deliberately NOT printed — the QR
     * is the only public handle on the document.
     *
     * @param  array<string, mixed>  $parties
     */
    private function drawFooter(array $parties, ?string $publicUrl): void
    {
        $this->rule($this->y);
        $this->y += 34;

        $qrSize = 170;
        $qrX = $this->width - self::MARGIN - $qrSize;

        if ($publicUrl !== null) {
            $qrImage = imagecreatefromstring($this->qr->png($publicUrl, 5));

            if ($qrImage !== false) {
                imagecopyresampled(
                    $this->image, $qrImage, $qrX, $this->y,
                    0, 0, $qrSize, $qrSize, imagesx($qrImage), imagesy($qrImage),
                );

                imagedestroy($qrImage);
            }

            $this->text('Scan Me', $qrX + (int) ($qrSize / 2), $this->y + $qrSize + 26, 20, self::INK, true, 'center');
        }

        $baseline = $this->y + 22;
        $this->text('Powered by', self::MARGIN, $baseline, 17, self::MUTED, true);
        $baseline += 32;

        $employee = collect([$parties['employee']['id'], $parties['employee']['name']])
            ->filter()
            ->implode(' - ');

        if ($employee !== '') {
            $this->text($employee, self::MARGIN, $baseline, 21, self::INK, true);
            $baseline += 30;
        }

        if ($parties['company']['name'] !== null) {
            $this->text((string) $parties['company']['name'], self::MARGIN, $baseline, 19, self::MUTED);
            $baseline += 26;
        }

        $this->text('Read-only public invoice · no account required.', self::MARGIN, $baseline, 17, self::MUTED);

        $this->y = max($baseline + 20, $this->y + $qrSize + 52);
    }
}
