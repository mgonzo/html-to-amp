<?php

namespace Predmond\HtmlToAmp;

use Predmond\HtmlToAmp\Converter\ConverterInterface;

class AmpConverter
{
    protected $environment;

    public function __construct(Environment $env = null)
    {
        $this->environment = $env;

        if ($this->environment === null) {
            $this->environment = Environment::createDefaultEnvironment();
        }
    }

    public function convert($html)
    {
        if (trim($html) === '') {
            return '';
        }

        $document = $this->createDocument($html);

        if (!($root = $document->getElementsByTagName('html')->item(0))) {
            throw new \InvalidArgumentException('Invalid HTML was provided');
        }

        $root = new Element($root);
        $this->convertChildren($root);
        $this->removeProhibited($document);
        $ampHtml = $this->sanitize($document->saveHTML());

        return $ampHtml;
    }

    /**
     * @param string $html
     *
     * @return \DOMDocument
     */
    private function createDocument($html)
    {
        $document = new \DOMDocument();

        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        $document->encoding = 'UTF-8';
        libxml_clear_errors();

        return $document;
    }

    private function convertChildren(ElementInterface $element)
    {
        if ($element->hasChildren()) {
            foreach ($element->getChildren() as $child) {
                $this->convertChildren($child);
            }
        }

        $this->convertToAmp($element);
    }

    private function convertToAmp(ElementInterface $element)
    {
        $tag = $element->getTagName();

        /** @var ConverterInterface $converter */
        $event = $this->environment->getEventEmitter()
            ->emit("convert.{$tag}", $element, $tag);
    }

    private function sanitize($html)
    {
        $html = preg_replace('/<!DOCTYPE [^>]+>/', '', $html);
        $unwanted = array('<html>', '</html>', '<body>', '</body>', '<head>', '</head>', '<?xml encoding="UTF-8">', '&#xD;');
        $html = str_replace($unwanted, '', $html);
        $html = trim($html, "\n\r\0\x0B");

        return $html;
    }

    private function removeProhibited(\DOMDocument $document)
    {
        // TODO: Config-based
        $xpath = '//' . implode('|//', [
            'base',
            'frame',
            'frameset',
            'object',
            'param',
            'applet',
            'embed',
            'form',
            'input',
            'textarea',
            'script',
            'select',
            'option',
            'meta'
        ]);

        $elements = (new \DOMXPath($document))->query($xpath);

        /** @var \DOMElement $element */
        foreach ($elements as $element) {
            if ($element->nodeName === 'meta' && $element->getAttribute('http-equiv') === '') {
                continue;
            }

            if ($element->parentNode !== null) {
                $element->parentNode->removeChild($element);
            }
        }

        // Remove anchors with javascript in the href
        $anchors = (new \DOMXPath($document))
            ->query('//a[contains(@href, "javascript:")]');

        foreach ($anchors as $a) {
            if ($a->parentNode !== null) {
                $a->parentNode->removeChild($a);
            }
        }
    }
}
