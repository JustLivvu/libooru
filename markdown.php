<?php
declare(strict_types=1);

final class WikiMarkdown
{
    private static mixed $converter = null;

    public static function render(string $markdown): string
    {
        if (!class_exists(League\CommonMark\GithubFlavoredMarkdownConverter::class)) {
            $autoload = __DIR__ . '/vendor/autoload.php';
            if (is_file($autoload)) require_once $autoload;
        }

        if (!class_exists(League\CommonMark\GithubFlavoredMarkdownConverter::class)) {
            return '<p>' . nl2br(htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
        }

        if (self::$converter === null) {
            self::$converter = new League\CommonMark\GithubFlavoredMarkdownConverter([
                'html_input' => 'allow',
                'allow_unsafe_links' => false,
                'max_nesting_level' => 50,
                'max_delimiters_per_line' => 1000,
            ]);
        }

        $html = (string)self::$converter->convert($markdown);
        return self::sanitize($html);
    }

    private static function sanitize(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<!doctype html><html><head><meta charset="UTF-8"></head><body><div id="wiki-markdown-root">' . $html . '</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        if (!$loaded) return '';

        $root = $document->getElementById('wiki-markdown-root');
        if (!$root) return '';

        self::sanitizeChildren($root);

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }
        return $result;
    }

    private static function sanitizeChildren(DOMNode $parent): void
    {
        $children = [];
        foreach ($parent->childNodes as $child) $children[] = $child;

        $allowedTags = [
            'a', 'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'blockquote', 'pre', 'code', 'em', 'strong', 'del', 'ul', 'ol', 'li',
            'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'img', 'input',
            'details', 'summary', 'kbd', 'sup', 'sub', 'mark', 'span', 'div',
        ];
        $dropWithContents = [
            'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form',
            'button', 'textarea', 'select', 'option', 'video', 'audio', 'canvas',
        ];

        foreach ($children as $node) {
            if ($node instanceof DOMComment || $node instanceof DOMProcessingInstruction) {
                $parent->removeChild($node);
                continue;
            }
            if (!($node instanceof DOMElement)) continue;

            $tag = strtolower($node->tagName);
            if (!in_array($tag, $allowedTags, true)) {
                if (in_array($tag, $dropWithContents, true)) {
                    $parent->removeChild($node);
                    continue;
                }
                self::sanitizeChildren($node);
                while ($node->firstChild) $parent->insertBefore($node->firstChild, $node);
                $parent->removeChild($node);
                continue;
            }

            self::sanitizeAttributes($node, $tag);
            self::sanitizeChildren($node);
        }
    }

    private static function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $allowed = [
            'a' => ['href', 'title', 'target', 'rel', 'id', 'class'],
            'img' => ['src', 'alt', 'title', 'width', 'height', 'id', 'class'],
            'input' => ['type', 'checked', 'disabled', 'class'],
            'th' => ['align', 'colspan', 'rowspan', 'id', 'class'],
            'td' => ['align', 'colspan', 'rowspan', 'id', 'class'],
            'ol' => ['start', 'id', 'class'],
            'details' => ['open', 'id', 'class'],
        ];
        $global = ['id', 'class'];
        $names = [];
        foreach ($element->attributes as $attribute) $names[] = strtolower($attribute->name);

        foreach ($names as $name) {
            if (!in_array($name, $allowed[$tag] ?? $global, true)) {
                $element->removeAttribute($name);
            }
        }

        if ($element->hasAttribute('id')) {
            $id = $element->getAttribute('id');
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,127}$/', $id)) $element->removeAttribute('id');
        }
        if ($element->hasAttribute('class')) {
            $class = trim($element->getAttribute('class'));
            if (!preg_match('/^[A-Za-z0-9 _-]{1,160}$/', $class)) $element->removeAttribute('class');
        }

        if ($tag === 'a') {
            if ($element->hasAttribute('href') && !self::safeUrl($element->getAttribute('href'), true)) {
                $element->removeAttribute('href');
            }
            $target = $element->getAttribute('target');
            if ($target !== '' && !in_array($target, ['_blank', '_self'], true)) {
                $element->removeAttribute('target');
            }
            if ($element->getAttribute('target') === '_blank') {
                $element->setAttribute('rel', 'noopener noreferrer');
            } elseif ($element->hasAttribute('rel')) {
                $rel = strtolower($element->getAttribute('rel'));
                $tokens = array_intersect(preg_split('/\s+/', $rel) ?: [], ['nofollow', 'noopener', 'noreferrer']);
                if ($tokens) $element->setAttribute('rel', implode(' ', array_unique($tokens)));
                else $element->removeAttribute('rel');
            }
        } elseif ($tag === 'img') {
            if (!$element->hasAttribute('src') || !self::safeUrl($element->getAttribute('src'), false)) {
                $element->parentNode?->removeChild($element);
                return;
            }
            foreach (['width', 'height'] as $dimension) {
                if ($element->hasAttribute($dimension) && !preg_match('/^[1-9][0-9]{0,3}$/', $element->getAttribute($dimension))) {
                    $element->removeAttribute($dimension);
                }
            }
        } elseif ($tag === 'input') {
            if (strtolower($element->getAttribute('type')) !== 'checkbox') {
                $element->parentNode?->removeChild($element);
                return;
            }
            $element->setAttribute('type', 'checkbox');
            $element->setAttribute('disabled', 'disabled');
        }
    }

    private static function safeUrl(string $url, bool $allowMailto): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url)) return false;
        if (str_starts_with($url, '#') || str_starts_with($url, '/')
            || str_starts_with($url, './') || str_starts_with($url, '../')) return true;

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme === '') return true;
        $allowed = $allowMailto ? ['http', 'https', 'mailto'] : ['http', 'https'];
        return in_array($scheme, $allowed, true);
    }
}
