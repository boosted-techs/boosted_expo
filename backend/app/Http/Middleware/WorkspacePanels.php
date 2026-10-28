<?php

namespace App\Http\Middleware;

use Closure;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class WorkspacePanels
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $mode = (config('workspace-surfaces')[$request->route()?->getName()] ?? null);
        if (! $mode || ! $request->isMethod('GET') || $response->getStatusCode() !== 200 || ! str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            return $response;
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $content = $xpath->query('//main[contains(concat(" ", normalize-space(@class), " "), " content ")]')->item(0);
        if (! $content instanceof DOMElement) {
            return $response;
        }
        $panels = [];
        foreach ($xpath->query('.//section[contains(concat(" ", normalize-space(@class), " "), " section-card ")][not(ancestor::section)]|.//details[contains(concat(" ", normalize-space(@class), " "), " section-card ")][not(ancestor::section or ancestor::details)]', $content) as $section) {
            $heading = $xpath->query('./h2|./summary|./details/summary', $section)->item(0);
            if (! $heading) {
                continue;
            }
            $label = trim(preg_replace('/\s+/u', ' ', $heading->textContent));
            $slug = $section->getAttribute('data-workspace-key') ?: Str::slug(preg_replace('/\s*\(\d+\)\s*$/', '', $label));
            if ($slug === '') {
                continue;
            }
            $base = $slug;
            for ($i = 2; isset($panels[$slug]); $i++) {
                $slug = $base.'-'.$i;
            }
            $panels[$slug] = ['label' => $label, 'section' => $section];
        }
        $selected = $request->query('workspace_panel');
        if ($selected !== null) {
            abort_unless(is_string($selected) && isset($panels[$selected]), 404);
            $section = $panels[$selected]['section'];
            $fragment = $document->createDocumentFragment();
            foreach ($xpath->query('./div[contains(concat(" ", normalize-space(@class), " "), " flash ")]', $content) as $flash) {
                $fragment->appendChild($flash->cloneNode(true));
            }
            $intro = $document->createElement('div');
            $intro->setAttribute('class', 'page-intro');
            $intro->appendChild($document->createElement('h1', htmlspecialchars($panels[$selected]['label'], ENT_XML1)));
            $fragment->appendChild($intro);
            foreach ($xpath->query('./form[translate(@method,"get","GET")="GET"]', $content) as $filter) {
                $fragment->appendChild($filter->cloneNode(true));
            }
            $fragment->appendChild($section);
            $content->nodeValue = '';
            $content->appendChild($fragment);
            $section->setAttribute('data-panel-focused', '');
            if ($section->tagName === 'details') {
                $section->setAttribute('open', '');
                $section->setAttribute('data-panel-focused', '');
            }
            foreach ($xpath->query('.//form[translate(@method,"get","GET")="GET"]', $content) as $form) {
                $input = $document->createElement('input');
                $input->setAttribute('type', 'hidden');
                $input->setAttribute('name', 'workspace_panel');
                $input->setAttribute('value', $selected);
                $form->appendChild($input);
            }
            $title = $xpath->query('//title')->item(0);
            if ($title) {
                $title->nodeValue = $panels[$selected]['label'].' · Boosted One';
            }
        } elseif (count($panels) > 1 || ($mode === 'hub' && count($panels) === 1)) {
            $cards = $document->createElement('nav');
            $cards->setAttribute('class', 'activity-cards no-print');
            $cards->setAttribute('aria-label', 'Choose an activity');
            $index = 0;
            foreach ($panels as $slug => $panel) {
                $section = $panel['section'];
                $primary = $mode === 'register' && $index++ === 0;
                if ($primary) {
                    continue;
                }
                $section->setAttribute('data-workspace-section', $slug);
                $link = $document->createElement('a');
                $link->setAttribute('class', 'activity-card');
                $link->setAttribute('data-remote-dialog', '');
                $link->setAttribute('href', $request->fullUrlWithQuery(['workspace_panel' => $slug]));
                $strong = $document->createElement('strong');
                $strong->appendChild($document->createTextNode($panel['label']));
                $link->appendChild($strong);
                $arrow = $document->createElement('span', '↗');
                $arrow->setAttribute('aria-hidden', 'true');
                $link->appendChild($arrow);
                $cards->appendChild($link);
            }
            $first = $panels[array_key_first($panels)]['section'];
            while ($first->parentNode !== $content) {
                $first = $first->parentNode;
            }
            $content->insertBefore($cards, $first);
        }
        $original = $response instanceof \Illuminate\Http\Response ? $response->original : null;
        $response->setContent(preg_replace('/<\?xml[^>]+>/', '', $document->saveHTML()));

        if ($response instanceof \Illuminate\Http\Response) {
            $response->original = $original;
        }

        return $response;
    }
}
