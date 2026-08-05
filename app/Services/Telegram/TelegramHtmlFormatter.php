<?php

namespace App\Services\Telegram;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Util\HtmlFilter;
use League\CommonMark\Util\RegexHelper;

/**
 * Converts LLM-generated Markdown into Telegram-safe HTML.
 *
 * Telegram's HTML mode only supports a small subset of tags (b/strong, i/em,
 * u/ins, s/del, a href, code, pre). Everything else — headings, lists, tables,
 * blockquotes, horizontal rules, raw HTML — is mapped to plain text or to a
 * supported tag so the Bot API never receives an unsupported construct. The
 * class is stateless; every format() call builds a fresh CommonMark
 * environment so no renderer state leaks between messages.
 */
class TelegramHtmlFormatter
{
    /**
     * Convert Markdown into Telegram-safe HTML.
     *
     * Raw HTML supplied by the agent is stripped and unsafe links (e.g.
     * javascript:) are dropped, keeping only their label text.
     */
    public function format(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }

        $document = $this->converter()->convert($markdown)->getDocument();

        return $this->renderBlocks($document);
    }

    /**
     * Build a CommonMark converter configured for Telegram-safe output.
     */
    private function converter(): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => HtmlFilter::STRIP,
            'allow_unsafe_links' => false,
            'max_nesting_level' => 64,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);

        return new MarkdownConverter($environment);
    }

    /**
     * Render the block-level children of a node, one per line separated by a
     * blank line. Empty renderings (horizontal rules, stripped HTML) are dropped.
     */
    private function renderBlocks(Node $node): string
    {
        $lines = [];

        foreach ($node->children() as $child) {
            $rendered = $this->renderBlock($child);

            if ($rendered !== '') {
                $lines[] = $rendered;
            }
        }

        return implode("\n\n", $lines);
    }

    /**
     * Render a single block node into Telegram-supported output.
     */
    private function renderBlock(Node $node): string
    {
        return match (true) {
            $node instanceof Paragraph => $this->renderInline($node),
            $node instanceof Heading => '<strong>'.$this->renderInline($node).'</strong>',
            $node instanceof ListBlock => $this->renderList($node),
            $node instanceof ListItem => $this->renderBlocks($node),
            $node instanceof BlockQuote => $this->renderBlocks($node),
            $node instanceof FencedCode => '<pre>'.$this->escape($node->getLiteral()).'</pre>',
            $node instanceof IndentedCode => '<pre>'.$this->escape($node->getLiteral()).'</pre>',
            $node instanceof Table => $this->renderTable($node),
            $node instanceof TableSection => $this->renderTableSection($node),
            $node instanceof TableRow => $this->renderTableRow($node),
            $node instanceof ThematicBreak => '',
            $node instanceof HtmlBlock => '',
            $node instanceof Document => $this->renderBlocks($node),
            default => $this->renderInline($node),
        };
    }

    /**
     * Render a list as plain lines with bullet or numeric prefixes.
     */
    private function renderList(ListBlock $node): string
    {
        $listData = $node->getListData();
        $ordered = $listData->type === ListBlock::TYPE_ORDERED;
        $index = ($listData->start ?? 1) - 1;

        $lines = [];

        foreach ($node->children() as $child) {
            if (! $child instanceof ListItem) {
                continue;
            }

            $index++;
            $prefix = $ordered ? $index.'. ' : '• ';

            $lines[] = $prefix.$this->renderBlocks($child);
        }

        return implode("\n", $lines);
    }

    /**
     * Render a table as plain text lines with cells separated by " | ".
     */
    private function renderTable(Table $node): string
    {
        $lines = [];

        foreach ($node->children() as $section) {
            if (! $section instanceof TableSection) {
                continue;
            }

            $lines[] = $this->renderTableSection($section);
        }

        return implode("\n", $lines);
    }

    /**
     * @return string[] The rendered rows of a table section.
     */
    private function renderTableSection(TableSection $section): string
    {
        $lines = [];

        foreach ($section->children() as $row) {
            if (! $row instanceof TableRow) {
                continue;
            }

            $lines[] = $this->renderTableRow($row);
        }

        return implode("\n", $lines);
    }

    /**
     * Render a single table row with cells separated by " | ".
     */
    private function renderTableRow(TableRow $row): string
    {
        $cells = [];

        foreach ($row->children() as $cell) {
            if (! $cell instanceof TableCell) {
                continue;
            }

            $cells[] = $this->renderInline($cell);
        }

        return implode(' | ', $cells);
    }

    /**
     * Render the inline children of a node concatenated without separators.
     */
    private function renderInline(Node $node): string
    {
        $parts = [];

        foreach ($node->children() as $child) {
            $parts[] = $this->renderInlineNode($child);
        }

        return implode('', $parts);
    }

    /**
     * Render a single inline node into Telegram-supported output.
     */
    private function renderInlineNode(Node $node): string
    {
        return match (true) {
            $node instanceof Text => $this->escape($node->getLiteral()),
            $node instanceof Strong => '<strong>'.$this->renderInline($node).'</strong>',
            $node instanceof Emphasis => '<em>'.$this->renderInline($node).'</em>',
            $node instanceof Strikethrough => '<s>'.$this->renderInline($node).'</s>',
            $node instanceof Link => $this->renderLink($node),
            $node instanceof Image => $this->renderImage($node),
            $node instanceof Code => '<code>'.$this->escape($node->getLiteral()).'</code>',
            $node instanceof Newline => "\n",
            $node instanceof HtmlInline => '',
            default => $this->renderInline($node),
        };
    }

    /**
     * Render a link, dropping unsafe targets (e.g. javascript:) and keeping
     * only the label text.
     */
    private function renderLink(Link $node): string
    {
        $label = $this->renderInline($node);

        if ($label === '' || RegexHelper::isLinkPotentiallyUnsafe($node->getUrl())) {
            return $label;
        }

        return '<a href="'.$this->escape($node->getUrl()).'">'.$label.'</a>';
    }

    /**
     * Render an image as its label followed by the URL, since Telegram HTML
     * mode does not support <img>. Unsafe targets (e.g. javascript:) are
     * dropped, keeping only the label text — matching how links are handled.
     */
    private function renderImage(Image $node): string
    {
        $label = $this->renderInline($node);

        if (RegexHelper::isLinkPotentiallyUnsafe($node->getUrl())) {
            return $label;
        }

        $url = $this->escape($node->getUrl());

        return $label === '' ? $url : $label.' ('.$url.')';
    }

    /**
     * HTML-escape a value for use both as text content and as an attribute.
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
