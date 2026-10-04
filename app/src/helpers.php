<?php

declare(strict_types=1);

/**
 * Helpers available to every template. Loaded through Composer's "files"
 * autoloader, so they exist before any view is rendered.
 */

if (!function_exists('e')) {
    /**
     * Escape for HTML text and quoted attribute contexts. Every value
     * interpolated into a template goes through this, without exception.
     */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (!is_scalar($value)) {
            $value = is_object($value) && method_exists($value, '__toString')
                ? (string) $value
                : '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /** Absolute URL for an app-relative path, honouring a subdirectory mount. */
    function url(string $path = '/'): string
    {
        return \Macrolab\Config::baseUrl() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('path')) {
    /** Root-relative URL for an app-relative path (for href/action attributes). */
    function path(string $path = '/'): string
    {
        $base = \Macrolab\Config::basePath();

        return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /**
     * Root-relative URL for a file under the document root, with ?v=<modified
     * time> appended so a browser fetches it again after a deployment.
     *
     * .htaccess lets browsers keep .js and .css for a week. Without the version,
     * members would run last week's app.js against this week's pages.
     */
    function asset(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        // The document root is public_html beside app/, or - in the fallback
        // layout - the directory app/ itself sits in.
        $root = dirname(__DIR__, 2);
        foreach ([$root . '/public_html' . $path, $root . $path] as $file) {
            if (is_file($file)) {
                return path($path) . '?v=' . filemtime($file);
            }
        }

        return path($path);
    }
}

if (!function_exists('date_field')) {
    /**
     * A date field that shows and takes dates day first: 08-10-2026.
     *
     * The browser's own date input displays in the browser's language - month
     * first in an American browser - and no attribute changes that. So the
     * visible field is plain text (submitted under $name; the server reads it
     * with Clock::isoDate()), and a calendar button opens the browser's picker
     * through a date input kept out of sight. app.js wires the two together;
     * without it the text field works on its own.
     *
     * @param string|null                       $isoValue   Y-m-d, or null for empty
     * @param array<string, string|bool|null>   $attributes for the text field:
     *        id, aria-label, required, data-echo (id of the day-first echo), ...
     */
    function date_field(string $name, ?string $isoValue, array $attributes = []): string
    {
        $attributes = [
            'type'         => 'text',
            'name'         => $name,
            'value'        => $isoValue === null ? '' : \Macrolab\Clock::dmy($isoValue),
            'placeholder'  => 'dd-mm-yyyy',
            'inputmode'    => 'numeric',
            'autocomplete' => 'off',
            'maxlength'    => '10',
            'title'        => 'Day-month-year, for example 08-10-2026',
            'class'        => 'date-text',
        ] + $attributes;

        $html = '<span class="date-field"><input';
        foreach ($attributes as $attribute => $value) {
            if ($value === false || $value === null) {
                continue;
            }
            $html .= ' ' . e($attribute) . ($value === true ? '' : '="' . e($value) . '"');
        }
        $html .= '>';

        // Out of sight but rendered, because showPicker() refuses a hidden input.
        $html .= '<input type="date" class="date-native" tabindex="-1" aria-hidden="true"'
            . ' value="' . e($isoValue ?? '') . '">';

        // Shown by app.js only where the browser can open its picker.
        $html .= '<button type="button" class="date-pick" hidden aria-label="Open the calendar"'
            . ' title="Open the calendar"><svg viewBox="0 0 16 16" width="16" height="16"'
            . ' aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 0h1v2h6V0h1v2h2.5'
            . 'A1.5 1.5 0 0 1 16 3.5v11a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 0 14.5v-11A1.5 1.5 0 0 1'
            . ' 1.5 2H4V0Zm-3 6v8.5c0 .28.22.5.5.5h13a.5.5 0 0 0 .5-.5V6H1Zm2 2h2v2H3V8Zm4 0h2v2H7V8Zm4'
            . ' 0h2v2h-2V8Zm-8 3h2v2H3v-2Zm4 0h2v2H7v-2Z"/></svg></button>';

        return $html . '</span>';
    }
}

