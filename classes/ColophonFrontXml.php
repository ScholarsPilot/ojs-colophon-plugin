<?php
/**
 * @file classes/ColophonFrontXml.php
 *
 * Build a minimal JATS <front> from the OJS publication, for the one-call
 * intake. This is the piece that removes the corresponding-author blocker
 * family on the Colophon side: OJS holds every author's email, so the front
 * carries them, and Colophon applies it at IMPORT trust right after
 * extraction. Enrich-only over there — a reviewed value never moves.
 *
 * Deliberately free of OJS classes below the build() signature so it can be
 * unit-tested with plain arrays; the collect() helper does the OJS reading.
 */

namespace APP\plugins\generic\colophon\classes;

class ColophonFrontXml
{
    /**
     * @param array $meta {
     *   journal_title, issn_print, issn_electronic,
     *   article_title, doi, volume, issue, year, fpage, lpage,
     *   authors: [{given, family, email, orcid, corresponding, affiliations: [string]}]
     * }
     */
    public static function build(array $meta): string
    {
        $x = fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $affTexts = [];
        foreach (($meta['authors'] ?? []) as $author) {
            foreach (($author['affiliations'] ?? []) as $aff) {
                $aff = trim((string) $aff);
                if ($aff !== '' && !in_array($aff, $affTexts, true)) {
                    $affTexts[] = $aff;
                }
            }
        }
        $affIdByText = [];
        foreach ($affTexts as $i => $text) {
            $affIdByText[$text] = 'aff' . ($i + 1);
        }

        $contribs = '';
        foreach (($meta['authors'] ?? []) as $author) {
            $corresp = !empty($author['corresponding']) ? ' corresp="yes"' : '';
            $contribs .= '<contrib contrib-type="author"' . $corresp . '>';
            if (!empty($author['orcid'])) {
                $contribs .= '<contrib-id contrib-id-type="orcid">' . $x($author['orcid']) . '</contrib-id>';
            }
            $contribs .= '<name><surname>' . $x($author['family'] ?? '') . '</surname>'
                . '<given-names>' . $x($author['given'] ?? '') . '</given-names></name>';
            if (!empty($author['email'])) {
                $contribs .= '<email>' . $x($author['email']) . '</email>';
            }
            foreach (($author['affiliations'] ?? []) as $aff) {
                $aff = trim((string) $aff);
                if ($aff !== '' && isset($affIdByText[$aff])) {
                    $contribs .= '<xref ref-type="aff" rid="' . $affIdByText[$aff] . '"/>';
                }
            }
            $contribs .= '</contrib>';
        }
        $affs = '';
        foreach ($affIdByText as $text => $id) {
            $affs .= '<aff id="' . $id . '">' . $x($text) . '</aff>';
        }

        $journalMeta = '<journal-meta>'
            . '<journal-title-group><journal-title>' . $x($meta['journal_title'] ?? '') . '</journal-title></journal-title-group>'
            . (!empty($meta['issn_print']) ? '<issn publication-format="print">' . $x($meta['issn_print']) . '</issn>' : '')
            . (!empty($meta['issn_electronic']) ? '<issn publication-format="electronic">' . $x($meta['issn_electronic']) . '</issn>' : '')
            . '</journal-meta>';

        $articleMeta = '<article-meta>';
        if (!empty($meta['doi'])) {
            $articleMeta .= '<article-id pub-id-type="doi">' . $x($meta['doi']) . '</article-id>';
        }
        $articleMeta .= '<title-group><article-title>' . $x($meta['article_title'] ?? '') . '</article-title></title-group>';
        if ($contribs !== '') {
            $articleMeta .= '<contrib-group>' . $contribs . '</contrib-group>';
        }
        $articleMeta .= $affs;
        if (!empty($meta['year'])) {
            $articleMeta .= '<pub-date publication-format="electronic"><year>' . $x($meta['year']) . '</year></pub-date>';
        }
        if (!empty($meta['volume'])) {
            $articleMeta .= '<volume>' . $x($meta['volume']) . '</volume>';
        }
        if (!empty($meta['issue'])) {
            $articleMeta .= '<issue>' . $x($meta['issue']) . '</issue>';
        }
        if (!empty($meta['fpage'])) {
            $articleMeta .= '<fpage>' . $x($meta['fpage']) . '</fpage>';
            if (!empty($meta['lpage'])) {
                $articleMeta .= '<lpage>' . $x($meta['lpage']) . '</lpage>';
            }
        }
        // OJS's own history: dateSubmitted → received, the ACCEPT decision →
        // accepted. JATS wants <history> after the pagination block.
        $history = '';
        foreach (['received' => 'date_received', 'accepted' => 'date_accepted'] as $type => $key) {
            $value = (string) ($meta[$key] ?? '');
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
                $history .= '<date date-type="' . $type . '">'
                    . '<day>' . $m[3] . '</day><month>' . $m[2] . '</month>'
                    . '<year>' . $m[1] . '</year></date>';
            }
        }
        if ($history !== '') {
            $articleMeta .= '<history>' . $history . '</history>';
        }
        $articleMeta .= '</article-meta>';

        // Wrapped in an <article> root: the platform's reader recognises
        // whole documents, and the wrapper costs nothing.
        return '<article xmlns:xlink="http://www.w3.org/1999/xlink"><front>'
            . $journalMeta . $articleMeta . '</front></article>';
    }
}
