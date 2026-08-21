<?php
namespace App\Libs\security;

class HtmlSanitizer
{
    private bool $strictMode;

    public function __construct(bool $strictMode = true)
    {
        $this->strictMode = $strictMode;
    }

    /* =========================================================
     * PUBLIC ENTRY
     * ========================================================= */
    public function sanitize($input) 
    {
        return $this->sanitizeRecursive($input);
    }

    /* =========================================================
     * Recursive sanitizer (supports nested array)
     * ========================================================= */
    private function sanitizeRecursive($input)
    {
        if (is_array($input)) {
            $result = [];
            
            foreach ($input as $key => $value) {
                $result[$key] = $this->sanitizeRecursive($value);
            }

            return $result;
        }
        else {
            return $this->sanitizeOne(($input ?? ''));
        }
    }

    /* =========================================================
     * CORE SANITIZER
     * ========================================================= */
    private function sanitizeOne(string $html): string
    {
        libxml_use_internal_errors(true);

        $dom = new \DOMDocument('1.0', 'UTF-8');

        $html = '<div id="root">' . $html . '</div>';

        $dom->loadHTML(
            mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $root = $dom->getElementById('root');

        if (!$root) {
            return '';
        }

        $this->walk($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        libxml_clear_errors();

        return $output;
    }

    /* =========================================================
     * DOM TRAVERSAL
     * ========================================================= */
    private function walk(\DOMNode $node): void
    {
        if ($node->hasChildNodes()) {
            $children = [];
            foreach ($node->childNodes as $child) {
                $children[] = $child;
            }

            foreach ($children as $child) {
                $this->walk($child);
            }
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return;
        }

        /** @var DOMElement $node */
        $tag = strtolower($node->nodeName);

        // tag whitelist
        if (!$this->isAllowedTag($tag)) {
            $this->unwrap($node);
            return;
        }

        $this->sanitizeAttributes($node, $tag);
    }

    /* =========================================================
     * TAG POLICY
     * ========================================================= */
    private function isAllowedTag(string $tag): bool
    {
        $strict = [
            'p','br','b','strong','i','em',
            'ul','ol','li',
            'a','img',
            'div','span'
        ];

        $cms = [
            'p','br',
            'b','strong','i','em','u','strike',
            'ul','ol','li',
            'h1','h2','h3','h4',
            'a','img',
            'div','span',

            // table
            'table','thead','tbody','tr','td','th',

            // media
            'iframe','video','audio','source'
        ];

        return in_array(
            $tag,
            $this->strictMode ? $strict : $cms,
            true
        );
    }

    /* =========================================================
     * ATTRIBUTE SANITIZATION
     * ========================================================= */
    private function sanitizeAttributes(\DOMElement $node, string $tag): void
    {
        if (!$node->hasAttributes()) {
            return;
        }

        $allowed = $this->allowedAttributes($tag);

        foreach (iterator_to_array($node->attributes) as $attr) {

            $name = strtolower($attr->nodeName);
            $value = $attr->nodeValue;

            // remove event handlers
            if (str_starts_with($name, 'on')) {
                $node->removeAttribute($name);
                continue;
            }

            // remove javascript:
            if (preg_match('/javascript:/i', $value)) {
                $node->removeAttribute($name);
                continue;
            }

            if (!in_array($name, $allowed, true)) {
                $node->removeAttribute($name);
                continue;
            }

            // style whitelist
            if ($name === 'style') {
                $clean = $this->sanitizeStyle($value);

                if ($clean === '') {
                    $node->removeAttribute('style');
                } else {
                    $node->setAttribute('style', $clean);
                }
            }
        }

        // iframe security
        if ($tag === 'iframe') {
            $this->sanitizeIframe($node);
        }
    }

    /* =========================================================
     * ATTRIBUTE POLICY
     * ========================================================= */
    private function allowedAttributes(string $tag): array
    {
        $map = [
            'a' => ['href','title','target','rel', 'class'],
            'img' => ['src','alt','width','height', 'class'],
            'div' => ['style', 'class'],
            'span' => ['style', 'class'],
            'p' => ['style', 'class'],

            'table' => ['style','border', 'class'],
            'td' => ['rowspan','colspan','style', 'class'],
            'th' => ['rowspan','colspan','style', 'class'],

            'iframe' => ['src','width','height','allow','allowfullscreen'],
            'video' => ['src','controls','width','height'],
            'audio' => ['src','controls'],
            'source' => ['src','type'],
        ];

        return $map[$tag] ?? [];
    }

    /* =========================================================
     * INLINE STYLE SANITIZER
     * ========================================================= */
    private function sanitizeStyle(string $style): string
    {
        $allowed = [
            'color',
            'background-color',

            'font-size',
            'font-weight',
            'font-style',
            'text-align',
            'text-decoration',
            'line-height',

            'display',
            'vertical-align',
            'white-space',
            'border-collapse',
            
            'width',
            'height',
            'max-width',
            'max-height',

            'margin',
            'margin-top',
            'margin-right',
            'margin-bottom',
            'margin-left',

            'padding',
            'padding-top',
            'padding-right',
            'padding-bottom',
            'padding-left',
            
            'border',
            
            'border-width',
            'border-top-width',
            'border-right-width',
            'border-bottom-width',
            'border-left-width',
            
            'border-style',
            'border-top-style',
            'border-right-style',
            'border-bottom-style',
            'border-left-style',
            
            'border-color',
            'border-top-color',
            'border-right-color',
            'border-bottom-color',
            'border-left-color',
            
            'border-radius',
            'border-top-left-radius',
            'border-top-right-radius',
            'border-bottom-right-radius',
            'border-bottom-left-radius',

            'background',
            'background-position',
            'background-size',
            'background-repeat',
        ];

        $parts = explode(';', $style);
        $clean = [];

        foreach ($parts as $part) {
            if (!str_contains($part, ':')) {
                continue;
            }

            [$prop, $val] = array_map('trim', explode(':', $part, 2));

            $prop = strtolower($prop);

            // block dangerous patterns
            if (preg_match('/expression\(|javascript:|url\(|@import/i', $val)) {
                continue;
            }

            if (in_array($prop, $allowed, true)) {
                $clean[] = $prop . ':' . htmlspecialchars($val, ENT_QUOTES);
            }
        }

        return implode('; ', $clean);
    }

    /* =========================================================
     * IFRAME SANITIZER (YouTube / Vimeo etc)
     * ========================================================= */
    private function sanitizeIframe(\DOMElement $node): void
    {
        $src = $node->getAttribute('src');

        $allowedDomains = [
            'youtube.com',
            'www.youtube.com',
            'facebook.com',
            'www.facebook.com',
            'google.com',
            'www.google.com',
            'player.vimeo.com'
        ];

        $ok = false;

        foreach ($allowedDomains as $domain) {
            if (str_contains($src, $domain)) {
                $ok = true;
                break;
            }
        }

        if (!$ok) {
            $node->parentNode->removeChild($node);
            return;
        }

        $node->setAttribute('sandbox', 'allow-scripts allow-same-origin');
        $node->setAttribute('frameborder', '0');
    }

    /* =========================================================
     * UNWRAP INVALID NODE
     * ========================================================= */
    private function unwrap(\DOMNode $node): void
    {
        $parent = $node->parentNode;

        if (!$parent) { return; }

        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }
}