"""
DOCX → HTML/CSS renderer.

This is a direct WordprocessingML reader: it walks the OOXML tree and emits an
HTML document whose CSS mirrors the document's own formatting.  Everything the
printed result depends on is produced here — page size and margins, headers and
footers as CSS running elements, real page-number counters, direct character
formatting, lists, tables and images.

Design notes
------------
* Word styles become CSS classes (``.ps-s-<styleId>``) with the basedOn chain
  already flattened; direct formatting becomes inline styles.  The CSS cascade
  then reproduces Word's cascade without any per-run property merging.
* Headers and footers are emitted once per section as ``position: running()``
  elements and pulled into ``@page`` margin boxes, so they repeat on every page
  with their original formatting intact.
* ``PAGE``/``NUMPAGES`` fields become ``counter(page)``/``counter(pages)``, so
  page numbering is computed while the PDF is laid out instead of being frozen
  at whatever value Word last cached.
* Tracked changes are resolved: insertions are kept, deletions are dropped.
"""

from __future__ import annotations

import base64
import copy
import re
from dataclasses import dataclass, field
from typing import Dict, List, Optional, Tuple

from lxml import etree

from .ns import (NS, attr, eighth_points_to_pt, emu_to_pt, find, findall,
                 fmt_pt, half_points_to_pt, qn, to_int, toggle, twips_to_pt)
from .package import Package, content_type_of
from .styles import Numbering, StyleTable

# ── Options and result ───────────────────────────────────────────────────────


@dataclass
class RenderOptions:
    """Knobs the web UI exposes."""

    add_page_numbers: bool = True
    page_numbers_on_first_page: bool = True


@dataclass
class RenderResult:
    tree: etree._Element
    warnings: List[str] = field(default_factory=list)
    body_image_ids: List[str] = field(default_factory=list)

    def to_html(self) -> str:
        return '<!DOCTYPE html>\n' + etree.tostring(
            self.tree, method='html', encoding='unicode'
        )


# ── Constants ────────────────────────────────────────────────────────────────

_DEFAULT_PAGE_WIDTH_PT = 595.3   # A4 portrait
_DEFAULT_PAGE_HEIGHT_PT = 841.9
_DEFAULT_MARGIN_PT = 72.0
_DEFAULT_TAB_PT = 36.0           # Word's default tab stop: 0.5"
_NBSP = ' '

_UNSUPPORTED_IMAGE_SUBTYPES = ('emf', 'wmf', 'x-emf', 'x-wmf')

_ALIGNMENT = {
    'left': 'left', 'start': 'left',
    'right': 'right', 'end': 'right',
    'center': 'center',
    'both': 'justify', 'distribute': 'justify',
}

_UNDERLINE_STYLE = {
    'single': 'solid', 'words': 'solid',
    'double': 'double',
    'dotted': 'dotted', 'dottedHeavy': 'dotted',
    'dash': 'dashed', 'dashedHeavy': 'dashed', 'dashLong': 'dashed',
    'wave': 'wavy', 'wavyHeavy': 'wavy', 'wavyDouble': 'wavy',
    'thick': 'solid',
}

_BORDER_STYLE = {
    'single': 'solid', 'thick': 'solid', 'double': 'double',
    'dotted': 'dotted', 'dashed': 'dashed', 'dashSmallGap': 'dashed',
    'dotDash': 'dashed', 'dotDotDash': 'dashed',
    'wave': 'solid', 'triple': 'double',
}

_DECORATION_TAGS = (qn('w:u'), qn('w:strike'), qn('w:dstrike'))

# Fallback chains keyed by lower-cased font name, so the PDF still looks right
# on a machine that does not have the original font installed.
_SERIF_FONTS = {
    'times new roman', 'times', 'georgia', 'garamond', 'cambria', 'book antiqua',
    'palatino', 'palatino linotype', 'merriweather', 'pt serif', 'noto serif',
    'liberation serif', 'eb garamond', 'crimson text', 'playfair display',
}
_MONO_FONTS = {
    'courier new', 'courier', 'consolas', 'menlo', 'monaco', 'roboto mono',
    'source code pro', 'liberation mono', 'dejavu sans mono', 'inconsolata',
}
_SERIF_STACK = '"Liberation Serif", "DejaVu Serif", "Times New Roman", serif'
_SANS_STACK = '"Liberation Sans", "DejaVu Sans", Arial, sans-serif'
_MONO_STACK = '"Liberation Mono", "DejaVu Sans Mono", "Courier New", monospace'

# Bullet glyphs Word stores as Symbol/Wingdings private-use characters.
_BULLET_MAP = {
    '': '•',   # Symbol bullet          -> •
    '': '▪',   # Wingdings small square -> ▪
    '': '➢',   # Wingdings arrow head   -> ➢
    '': '❖',   # Wingdings diamond      -> ❖
    '': '✔',   # Wingdings check        -> ✔
    '': '→',   # Wingdings arrow        -> →
    '': '–',   # Symbol dash            -> –
    '': '■',   # Wingdings filled box   -> ■
    'o': '◦',        # Word's level-2 bullet  -> ◦
}

_ROMAN = [(1000, 'm'), (900, 'cm'), (500, 'd'), (400, 'cd'), (100, 'c'),
          (90, 'xc'), (50, 'l'), (40, 'xl'), (10, 'x'), (9, 'ix'),
          (5, 'v'), (4, 'iv'), (1, 'i')]

_UNSAFE_CLASS_RE = re.compile(r'[^A-Za-z0-9_-]')

_MARGIN_BOX_CSS = {
    'header': 'vertical-align: bottom; width: 100%;',
    'footer': 'vertical-align: top; width: 100%;',
}


def _class_name(prefix: str, style_id: str) -> str:
    return 'ps-%s-%s' % (prefix, _UNSAFE_CLASS_RE.sub('_', style_id))


def _style_attr(declarations: Dict[str, str]) -> str:
    return '; '.join('%s: %s' % (k, v) for k, v in declarations.items() if v)


def _merge_style(element, declarations: Dict[str, str]) -> None:
    existing = element.get('style') or ''
    addition = _style_attr(declarations)
    combined = '; '.join(part for part in (existing, addition) if part)
    if combined:
        element.set('style', combined)


def _hex_colour(value: Optional[str]) -> Optional[str]:
    if not value or value.lower() == 'auto':
        return None
    value = value.strip().lstrip('#')
    if re.fullmatch(r'[0-9A-Fa-f]{6}', value):
        return '#' + value.upper()
    return None


def _append_text(element: etree._Element, text: str) -> None:
    """Append character data to an element, honouring existing children."""
    if not text:
        return
    if len(element):
        last = element[-1]
        last.tail = (last.tail or '') + text
    else:
        element.text = (element.text or '') + text


def _format_number(value: int, fmt: str) -> str:
    if fmt == 'decimalZero':
        return '%02d' % value
    if fmt in ('lowerLetter', 'upperLetter'):
        letters = ''
        n = max(value, 1)
        while n > 0:
            n, remainder = divmod(n - 1, 26)
            letters = chr(ord('a') + remainder) + letters
        return letters.upper() if fmt == 'upperLetter' else letters
    if fmt in ('lowerRoman', 'upperRoman'):
        n = max(value, 1)
        out = ''
        for amount, numeral in _ROMAN:
            while n >= amount:
                out += numeral
                n -= amount
        return out.upper() if fmt == 'upperRoman' else out
    if fmt == 'none':
        return ''
    return str(value)


class _FieldFrame:
    """One level of a Word field (fields nest)."""

    __slots__ = ('instruction', 'in_result', 'suppress_result')

    def __init__(self):
        self.instruction = ''
        self.in_result = False
        self.suppress_result = False


class _Context:
    """Per-part rendering state: which part relationships resolve against."""

    def __init__(self, part_name: str, tag_images: bool = False,
                 header_footer: bool = False):
        self.part_name = part_name
        self.tag_images = tag_images
        self.header_footer = header_footer
        self.fields: List[_FieldFrame] = []

    @property
    def suppressing(self) -> bool:
        frame = self.fields[-1] if self.fields else None
        if frame is None:
            return False
        return (not frame.in_result) or frame.suppress_result


# ── Renderer ─────────────────────────────────────────────────────────────────


class Renderer:
    def __init__(self, package: Package, options: Optional[RenderOptions] = None):
        self.pkg = package
        self.opts = options or RenderOptions()
        self.warnings: List[str] = []
        self.body_image_ids: List[str] = []

        self.main = package.main_part_name()
        self.styles = StyleTable(package, self._related_part('styles'))
        self.numbering = Numbering(package, self._related_part('numbering'), self.styles)
        self.settings = package.tree(self._related_part('settings'))
        self.footnotes_part = self._related_part('footnotes')
        self.endnotes_part = self._related_part('endnotes')
        self._theme_fonts = self._read_theme_fonts()

        self._rules: List[str] = []
        self._image_seq = 0
        self._list_counters: Dict[Tuple[str, int], int] = {}
        self._warned: set = set()
        self._default_tab_pt = self._read_default_tab()
        self._content_width_pt = _DEFAULT_PAGE_WIDTH_PT - 2 * _DEFAULT_MARGIN_PT

    # ── Package helpers ──────────────────────────────────────────────────────

    def _related_part(self, kind: str) -> Optional[str]:
        if not self.main:
            return None
        for rel in self.pkg.rels(self.main).values():
            if rel.kind == kind and not rel.external:
                name = self.pkg.related_part_name(self.main, rel.id)
                if name and self.pkg.has_part(name):
                    return name
        return None

    def _read_theme_fonts(self) -> Dict[str, str]:
        theme_part = self._related_part('theme')
        root = self.pkg.tree(theme_part) if theme_part else None
        fonts: Dict[str, str] = {}
        if root is None:
            return fonts
        scheme = find(root, 'a:themeElements/a:fontScheme')
        for key, path in (('major', 'a:majorFont/a:latin'),
                          ('minor', 'a:minorFont/a:latin')):
            latin = find(scheme, path) if scheme is not None else None
            typeface = latin.get('typeface') if latin is not None else None
            if typeface:
                fonts[key] = typeface
        return fonts

    def _read_default_tab(self) -> float:
        value = attr(find(self.settings, 'w:defaultTabStop'), 'w:val')
        return twips_to_pt(value, _DEFAULT_TAB_PT) or _DEFAULT_TAB_PT

    def _warn(self, message: str) -> None:
        if message not in self._warned:
            self._warned.add(message)
            self.warnings.append(message)

    # ── Entry point ──────────────────────────────────────────────────────────

    def build(self) -> RenderResult:
        html = etree.Element('html', lang='nl')
        head = etree.SubElement(html, 'head')
        etree.SubElement(head, 'meta', charset='utf-8')
        etree.SubElement(head, 'title').text = 'PrintScript'
        style_el = etree.SubElement(head, 'style')
        body = etree.SubElement(html, 'body')
        body.set('class', 'ps-body')

        self._emit_base_rules()
        self._emit_style_rules()

        root = self.pkg.tree(self.main)
        body_el = find(root, 'w:body') if root is not None else None
        if body_el is None:
            raise ValueError('Het document bevat geen tekst (w:body ontbreekt).')

        for index, (children, sect_pr) in enumerate(_split_sections(body_el)):
            self._render_section(index, children, sect_pr, body)

        style_el.text = '\n'.join(self._rules)
        return RenderResult(tree=html, warnings=self.warnings,
                            body_image_ids=list(self.body_image_ids))

    # ── Stylesheet ───────────────────────────────────────────────────────────

    def _emit_base_rules(self) -> None:
        self._rules.append(
            '/* reset: the document itself is the only source of formatting */\n'
            'html, body { margin: 0; padding: 0; }\n'
            'p, h1, h2, h3, h4, h5, h6, div, table, td, th, ul, ol, li {\n'
            '    margin: 0; padding: 0; font-size: inherit; font-weight: inherit;\n'
            '    font-style: inherit; text-align: inherit;\n'
            '}\n'
            'table { border-collapse: collapse; border-spacing: 0; }'
        )

        defaults: Dict[str, str] = {
            'font-family': _SANS_STACK,
            'font-size': '11pt',
            'color': '#000000',
        }
        defaults.update(self._run_declarations([self.styles.doc_default_rpr]))
        self._rules.append('body.ps-body { %s }' % _style_attr(defaults))

        paragraph_defaults = self._paragraph_declarations([self.styles.doc_default_ppr])
        paragraph_defaults.update({
            'white-space': 'pre-wrap',
            'orphans': '2',
            'widows': '2',
        })
        self._rules.append('.ps-p { %s }' % _style_attr(paragraph_defaults))

        self._rules.append(
            '/* structural helpers */\n'
            '.ps-num { display: inline-block; }\n'
            '.ps-tab { display: inline-block; }\n'
            '.ps-pagenum::before { content: counter(page); }\n'
            '.ps-pagecount::before { content: counter(pages); }\n'
            '.ps-hf-row { display: flex; width: 100%; gap: 6pt; }\n'
            '.ps-hf-cell { flex: 1 1 0; min-width: 0; }\n'
            '.ps-hf-center { text-align: center; }\n'
            '.ps-hf-right { text-align: right; }\n'
            '.ps-auto-pagenum { text-align: center; }\n'
            '.ps-textbox { display: block; border: 0.75pt solid #999999;\n'
            '    padding: 4pt; margin: 4pt 0; }\n'
            '.ps-footnote { float: footnote; }\n'
            '.ps-table { table-layout: fixed; }\n'
            '.ps-cell { vertical-align: top; }\n'
            'h1, h2, h3, h4, h5, h6 { break-after: avoid; }'
        )

    def _emit_style_rules(self) -> None:
        for style_id in self.styles.ids:
            entry = self.styles.get(style_id)
            if entry is None:
                continue
            if entry['type'] == 'paragraph':
                declarations = self._paragraph_declarations(
                    self.styles.paragraph_properties(style_id))
                declarations.update(
                    self._run_declarations(self.styles.run_properties(style_id)))
                selector = '.' + _class_name('s', style_id)
            elif entry['type'] == 'character':
                declarations = self._run_declarations(
                    self.styles.run_properties(style_id))
                selector = '.' + _class_name('c', style_id)
            else:
                continue
            if declarations:
                self._rules.append('%s { %s }' % (selector, _style_attr(declarations)))

    # ── Property translation ─────────────────────────────────────────────────

    def _font_stack(self, rfonts) -> Optional[str]:
        if rfonts is None:
            return None
        name = attr(rfonts, 'w:ascii') or attr(rfonts, 'w:hAnsi') or attr(rfonts, 'w:cs')
        if not name:
            theme = attr(rfonts, 'w:asciiTheme') or attr(rfonts, 'w:hAnsiTheme')
            if theme:
                name = self._theme_fonts.get('major' if 'major' in theme else 'minor')
        if not name:
            return None
        lowered = name.strip().lower()
        if lowered in _MONO_FONTS:
            fallback = _MONO_STACK
        elif lowered in _SERIF_FONTS:
            fallback = _SERIF_STACK
        else:
            fallback = _SANS_STACK
        return '"%s", %s' % (name.replace('"', ''), fallback)

    def _run_declarations(self, rpr_list) -> Dict[str, str]:
        """Translate a base-first list of w:rPr elements into CSS declarations."""
        css: Dict[str, str] = {}
        decorations: List[str] = []
        saw_decoration = False

        for rpr in rpr_list:
            if rpr is None:
                continue
            for child in rpr:
                tag = child.tag
                if tag in _DECORATION_TAGS:
                    saw_decoration = True

                if tag in (qn('w:b'), qn('w:bCs')):
                    css['font-weight'] = 'bold' if toggle(child, True) else 'normal'
                elif tag in (qn('w:i'), qn('w:iCs')):
                    css['font-style'] = 'italic' if toggle(child, True) else 'normal'
                elif tag == qn('w:u'):
                    value = attr(child, 'w:val', 'single')
                    if value in (None, 'none'):
                        decorations = [d for d in decorations if d != 'underline']
                    else:
                        if 'underline' not in decorations:
                            decorations.append('underline')
                        css['text-decoration-style'] = _UNDERLINE_STYLE.get(value, 'solid')
                        colour = _hex_colour(attr(child, 'w:color'))
                        if colour:
                            css['text-decoration-color'] = colour
                elif tag in (qn('w:strike'), qn('w:dstrike')):
                    if toggle(child, True):
                        if 'line-through' not in decorations:
                            decorations.append('line-through')
                    else:
                        decorations = [d for d in decorations if d != 'line-through']
                elif tag == qn('w:color'):
                    colour = _hex_colour(attr(child, 'w:val'))
                    if colour:
                        css['color'] = colour
                elif tag in (qn('w:sz'), qn('w:szCs')):
                    size = half_points_to_pt(attr(child, 'w:val'))
                    if size and size > 0:
                        css['font-size'] = fmt_pt(size)
                elif tag == qn('w:rFonts'):
                    stack = self._font_stack(child)
                    if stack:
                        css['font-family'] = stack
                elif tag == qn('w:caps'):
                    css['text-transform'] = 'uppercase' if toggle(child, True) else 'none'
                elif tag == qn('w:smallCaps'):
                    css['font-variant'] = 'small-caps' if toggle(child, True) else 'normal'
                elif tag == qn('w:vertAlign'):
                    value = attr(child, 'w:val', 'baseline')
                    if value in ('superscript', 'subscript'):
                        css['vertical-align'] = 'super' if value == 'superscript' else 'sub'
                        css['font-size'] = '0.66em'
                    else:
                        css.pop('vertical-align', None)
                elif tag == qn('w:spacing'):
                    spacing = twips_to_pt(attr(child, 'w:val'))
                    if spacing:
                        css['letter-spacing'] = fmt_pt(spacing)
                elif tag == qn('w:position'):
                    offset = half_points_to_pt(attr(child, 'w:val'))
                    if offset:
                        css['vertical-align'] = fmt_pt(offset)
                elif tag in (qn('w:vanish'), qn('w:webHidden')):
                    if toggle(child, True):
                        css['display'] = 'none'
                    else:
                        css.pop('display', None)

        if decorations:
            css['text-decoration-line'] = ' '.join(decorations)
        elif saw_decoration:
            # An explicit "no underline / no strike-through" must be able to
            # override an inherited one.
            css['text-decoration-line'] = 'none'
        return css

    def _paragraph_declarations(self, ppr_list) -> Dict[str, str]:
        css: Dict[str, str] = {}
        for ppr in ppr_list:
            if ppr is None:
                continue
            jc = find(ppr, 'w:jc')
            if jc is not None:
                alignment = _ALIGNMENT.get(attr(jc, 'w:val', 'left') or 'left')
                if alignment:
                    css['text-align'] = alignment

            spacing = find(ppr, 'w:spacing')
            if spacing is not None:
                before = twips_to_pt(attr(spacing, 'w:before'))
                after = twips_to_pt(attr(spacing, 'w:after'))
                if before is not None:
                    css['margin-top'] = fmt_pt(before)
                if after is not None:
                    css['margin-bottom'] = fmt_pt(after)
                line = attr(spacing, 'w:line')
                if line is not None:
                    if attr(spacing, 'w:lineRule', 'auto') == 'auto':
                        css['line-height'] = '%.3f' % ((to_int(line, 240) or 240) / 240.0)
                    else:
                        exact = twips_to_pt(line)
                        if exact:
                            css['line-height'] = fmt_pt(exact)

            indent = find(ppr, 'w:ind')
            if indent is not None:
                css.update(_indent_declarations(indent))

            page_break = find(ppr, 'w:pageBreakBefore')
            if page_break is not None and toggle(page_break, True):
                css['break-before'] = 'page'
            if toggle(find(ppr, 'w:keepNext'), False):
                css['break-after'] = 'avoid'
            if toggle(find(ppr, 'w:keepLines'), False):
                css['break-inside'] = 'avoid'

            borders = find(ppr, 'w:pBdr')
            if borders is not None:
                css.update(_border_declarations(borders))
        return css

    # ── Sections ─────────────────────────────────────────────────────────────

    def _render_section(self, index: int, children, sect_pr, body) -> None:
        geometry = _page_geometry(sect_pr)
        self._content_width_pt = max(
            geometry['width'] - geometry['margin_left'] - geometry['margin_right'], 36.0)

        page_name = 'psec%d' % index
        section = etree.SubElement(body, 'div')
        section.set('class', 'ps-section')

        declarations = {'page': page_name}
        if index > 0:
            break_type = attr(find(sect_pr, 'w:type'), 'w:val', 'nextPage')
            if break_type == 'evenPage':
                declarations['break-before'] = 'left'
            elif break_type == 'oddPage':
                declarations['break-before'] = 'right'
            elif break_type != 'continuous':
                declarations['break-before'] = 'page'
        columns = find(sect_pr, 'w:cols')
        column_count = to_int(attr(columns, 'w:num'), 1) or 1
        if column_count > 1:
            declarations['column-count'] = str(column_count)
            gap = twips_to_pt(attr(columns, 'w:space'))
            if gap:
                declarations['column-gap'] = fmt_pt(gap)
        section.set('style', _style_attr(declarations))

        self._emit_page_rules(index, page_name, geometry, sect_pr, section)

        context = _Context(self.main, tag_images=True)
        for child in children:
            self._render_block(child, section, context)

    def _emit_page_rules(self, index: int, page_name: str, geometry,
                         sect_pr, section) -> None:
        margin_boxes: List[str] = []
        first_boxes: List[str] = []

        title_page = toggle(find(sect_pr, 'w:titlePg'), False)
        even_odd = toggle(find(self.settings, 'w:evenAndOddHeaders'), False)
        if title_page and index > 0:
            self._warn('Een afwijkende eerste pagina ("titelblad") wordt alleen '
                       'toegepast op de eerste sectie van het document.')

        for kind, box in (('header', '@top-center'), ('footer', '@bottom-center')):
            default_el = self._running_element(section, sect_pr, kind, 'default', index)
            first_el = self._running_element(section, sect_pr, kind, 'first', index)
            even_el = self._running_element(section, sect_pr, kind, 'even', index)

            if kind == 'footer':
                default_el = self._ensure_page_number(section, default_el, index)
                if title_page and first_el is not None:
                    first_el = self._ensure_page_number(section, first_el, index)

            if default_el is not None:
                margin_boxes.append('  %s { content: element(%s); %s }'
                                    % (box, default_el.get('data-running'),
                                       _MARGIN_BOX_CSS[kind]))

            if index == 0:
                first_source = first_el if (title_page and first_el is not None) else None
                if kind == 'footer' and not self.opts.page_numbers_on_first_page:
                    source = first_source if first_source is not None else default_el
                    stripped = self._without_page_numbers(section, source)
                    content = ('element(%s)' % stripped.get('data-running')
                               if stripped is not None else 'none')
                    first_boxes.append('  %s { content: %s; %s }'
                                       % (box, content, _MARGIN_BOX_CSS[kind]))
                elif first_source is not None:
                    first_boxes.append('  %s { content: element(%s); %s }'
                                       % (box, first_source.get('data-running'),
                                          _MARGIN_BOX_CSS[kind]))
                elif title_page:
                    first_boxes.append('  %s { content: none; }' % box)

            if even_odd and even_el is not None:
                self._rules.append('@page %s:left {\n  %s { content: element(%s); %s }\n}'
                                   % (page_name, box, even_el.get('data-running'),
                                      _MARGIN_BOX_CSS[kind]))

        rule = ['@page %s {' % page_name,
                '  size: %s %s;' % (fmt_pt(geometry['width']), fmt_pt(geometry['height'])),
                '  margin: %s %s %s %s;' % (fmt_pt(geometry['margin_top']),
                                            fmt_pt(geometry['margin_right']),
                                            fmt_pt(geometry['margin_bottom']),
                                            fmt_pt(geometry['margin_left']))]
        rule.extend(margin_boxes)
        rule.append('}')
        self._rules.append('\n'.join(rule))

        if first_boxes:
            self._rules.append('@page %s:first {\n%s\n}' % (page_name, '\n'.join(first_boxes)))

    def _new_running_element(self, section, name: str) -> etree._Element:
        holder = etree.Element('div')
        holder.set('class', 'ps-running')
        holder.set('data-running', name)
        # Declared through a rule rather than inline: an inline `position`
        # would win over the rule if the value were ever rejected.
        self._rules.append('[data-running="%s"] { position: running(%s); }' % (name, name))
        section.insert(0, holder)
        return holder

    def _running_element(self, section, sect_pr, kind: str, which: str,
                         index: int) -> Optional[etree._Element]:
        """Render a header/footer part into a running element, or return None."""
        tag = 'w:headerReference' if kind == 'header' else 'w:footerReference'
        for reference in findall(sect_pr, tag):
            if attr(reference, 'w:type', 'default') != which:
                continue
            rid = reference.get(qn('r:id'))
            part = self.pkg.related_part_name(self.main, rid) if rid else None
            root = self.pkg.tree(part) if part else None
            if root is None:
                continue
            holder = self._new_running_element(section, 'ps%s%d%s' % (kind, index, which))
            context = _Context(part, tag_images=False, header_footer=True)
            for child in list(root):
                self._render_block(child, holder, context)
            if len(holder) == 0:
                section.remove(holder)
                return None
            return holder
        return None

    def _ensure_page_number(self, section, footer_el, index: int):
        """Guarantee the footer carries a page number, per the print spec."""
        if not self.opts.add_page_numbers:
            return footer_el
        if footer_el is not None and _contains_page_number(footer_el):
            return footer_el

        if footer_el is None:
            footer_el = self._new_running_element(section, 'psfooter%dauto' % index)

        holder = etree.SubElement(footer_el, 'div')
        holder.set('class', 'ps-p ps-auto-pagenum')
        etree.SubElement(holder, 'span').set('class', 'ps-pagenum')
        return footer_el

    def _without_page_numbers(self, section, source):
        """A copy of a running element with every page-number span removed."""
        if source is None:
            return None
        clone = copy.deepcopy(source)
        name = source.get('data-running') + 'nonum'
        clone.set('data-running', name)
        for span in list(clone.iter('span')):
            classes = span.get('class') or ''
            if 'ps-pagenum' in classes or 'ps-pagecount' in classes:
                parent = span.getparent()
                if parent is not None:
                    _append_text(parent, span.tail or '')
                    parent.remove(span)
        for block in list(clone.iter('div')):
            if 'ps-auto-pagenum' in (block.get('class') or ''):
                parent = block.getparent()
                if parent is not None:
                    parent.remove(block)
        self._rules.append('[data-running="%s"] { position: running(%s); }' % (name, name))
        section.insert(0, clone)
        return clone

    # ── Block level ──────────────────────────────────────────────────────────

    def _render_block(self, element, parent, context: _Context) -> None:
        tag = element.tag
        if tag == qn('w:p'):
            self._render_paragraph(element, parent, context)
        elif tag == qn('w:tbl'):
            self._render_table(element, parent, context)
        elif tag == qn('w:sdt'):
            content = find(element, 'w:sdtContent')
            for child in list(content) if content is not None else []:
                self._render_block(child, parent, context)
        elif tag == qn('mc:AlternateContent'):
            for child in _pick_alternate(element):
                self._render_block(child, parent, context)

    def _render_paragraph(self, paragraph, parent, context: _Context) -> None:
        ppr = find(paragraph, 'w:pPr')
        style_id = attr(find(ppr, 'w:pStyle'), 'w:val') or \
            self.styles.default_paragraph_style
        heading = self.styles.heading_level(style_id)
        tag = 'h%d' % heading if heading and 1 <= heading <= 6 else 'p'

        element = etree.SubElement(parent, tag)
        classes = ['ps-p']
        if style_id and style_id in self.styles:
            classes.append(_class_name('s', style_id))
        element.set('class', ' '.join(classes))

        declarations = self._paragraph_declarations([ppr])
        num_id, level = self._resolve_numbering(ppr, style_id)
        marker = None
        if num_id:
            marker, indent_css = self._list_marker(num_id, level, ppr)
            declarations = {**indent_css, **declarations}
        if declarations:
            element.set('style', _style_attr(declarations))
        if marker is not None:
            element.append(marker)

        if context.header_footer and 1 <= _tab_count(paragraph) <= 2:
            self._render_header_footer_row(paragraph, element, context)
            return

        for child in list(paragraph):
            self._render_inline(child, element, context)

        if not has_visible_content(element):
            run_css = self._run_declarations([find(ppr, 'w:rPr')])
            if run_css.get('font-size'):
                _merge_style(element, {'font-size': run_css['font-size']})
            _append_text(element, _NBSP)

    def _render_header_footer_row(self, paragraph, element, context: _Context) -> None:
        """
        Headers and footers almost always use tab stops to build a
        left / centre / right row.  A flex row reproduces that far more
        faithfully than a fixed-width tab spacer ever could.
        """
        row = etree.SubElement(element, 'div')
        row.set('class', 'ps-hf-row')
        cell_classes = ('ps-hf-cell', 'ps-hf-cell ps-hf-center', 'ps-hf-cell ps-hf-right')
        cells = []
        for position in range(_tab_count(paragraph) + 1):
            cell = etree.SubElement(row, 'div')
            cell.set('class', cell_classes[position] if position < len(cell_classes)
                     else 'ps-hf-cell')
            cells.append(cell)
        state = {'index': 0}

        def current_cell():
            return cells[min(state['index'], len(cells) - 1)]

        for child in list(paragraph):
            if child.tag == qn('w:r'):
                self._render_run(child, current_cell(), context,
                                 on_tab=lambda: state.__setitem__('index',
                                                                  state['index'] + 1),
                                 cell_provider=current_cell)
            else:
                self._render_inline(child, current_cell(), context)

        for cell in cells:
            if not has_visible_content(cell):
                _append_text(cell, _NBSP)
        _merge_style(element, {'white-space': 'normal'})

    def _resolve_numbering(self, ppr, style_id) -> Tuple[Optional[str], int]:
        num_pr = find(ppr, 'w:numPr')
        num_id = attr(find(num_pr, 'w:numId'), 'w:val') if num_pr is not None else None
        level = to_int(attr(find(num_pr, 'w:ilvl'), 'w:val'), 0) or 0
        if num_id is None:
            num_id, level = self.styles.numbering_of(style_id)
        if not num_id or num_id == '0':
            return None, 0
        if self.numbering.level(num_id, level) is None:
            return None, 0
        return num_id, level

    def _list_marker(self, num_id: str, level: int, ppr):
        """Advance the list counters and build the marker span plus indentation."""
        key = (num_id, level)
        if key in self._list_counters:
            self._list_counters[key] += 1
        else:
            self._list_counters[key] = self.numbering.start_at(num_id, level)
        for other in [k for k in self._list_counters
                      if k[0] == num_id and k[1] > level]:
            del self._list_counters[other]

        fmt = self.numbering.format_of(num_id, level)
        template = self.numbering.text_template(num_id, level)
        if fmt == 'bullet':
            text = ''.join(_BULLET_MAP.get(ch, ch) for ch in template) or '•'
        else:
            text = template or '%%%d.' % (level + 1)
            for depth in range(9):
                placeholder = '%%%d' % (depth + 1)
                if placeholder in text:
                    counter = self._list_counters.get(
                        (num_id, depth), self.numbering.start_at(num_id, depth))
                    text = text.replace(
                        placeholder,
                        _format_number(counter, self.numbering.format_of(num_id, depth)))

        indent = find(ppr, 'w:ind')
        if indent is None:
            indent = self.numbering.indent(num_id, level)
        declarations = _indent_declarations(indent) if indent is not None else {}
        hanging = twips_to_pt(attr(indent, 'w:hanging')) if indent is not None else None

        marker = etree.Element('span')
        marker.set('class', 'ps-num')
        marker_style = {'min-width': fmt_pt(hanging)} if hanging else {'padding-right': '6pt'}
        marker_css = self._run_declarations([self.numbering.run_properties(num_id, level)])
        marker_css.pop('display', None)
        marker_css.pop('font-family', None)   # symbol fonts have no glyph coverage
        marker_style.update(marker_css)
        marker.set('style', _style_attr(marker_style))
        marker.text = text
        return marker, declarations

    # ── Inline level ─────────────────────────────────────────────────────────

    def _render_inline(self, element, parent, context: _Context) -> None:
        tag = element.tag
        if tag == qn('w:r'):
            self._render_run(element, parent, context)
        elif tag == qn('w:hyperlink'):
            anchor = etree.SubElement(parent, 'a')
            rid = element.get(qn('r:id'))
            rel = self.pkg.rels(context.part_name).get(rid) if rid else None
            if rel is not None and rel.external:
                anchor.set('href', rel.target)
            elif element.get(qn('w:anchor')):
                anchor.set('href', '#' + element.get(qn('w:anchor')))
            anchor.set('style', 'color: inherit; text-decoration: inherit')
            for child in list(element):
                self._render_inline(child, anchor, context)
        elif tag == qn('w:ins'):
            for child in list(element):
                self._render_inline(child, parent, context)
        elif tag == qn('w:del'):
            return  # tracked deletion: never printed
        elif tag in (qn('w:smartTag'), qn('w:bdo'), qn('w:dir')):
            for child in list(element):
                self._render_inline(child, parent, context)
        elif tag == qn('w:sdt'):
            content = find(element, 'w:sdtContent')
            for child in list(content) if content is not None else []:
                self._render_inline(child, parent, context)
        elif tag == qn('w:fldSimple'):
            self._render_simple_field(element, parent, context)
        elif tag == qn('mc:AlternateContent'):
            for child in _pick_alternate(element):
                self._render_inline(child, parent, context)

    def _render_run(self, run, parent, context: _Context, on_tab=None,
                    cell_provider=None) -> None:
        """
        Render one run.  *on_tab* / *cell_provider* are only used inside
        headers and footers, where a tab moves the rest of the run into the
        next flex cell.
        """
        rpr = find(run, 'w:rPr')
        declarations = self._run_declarations([rpr])
        if declarations.get('display') == 'none':
            return
        style_id = attr(find(rpr, 'w:rStyle'), 'w:val')

        def new_span(target):
            span = etree.SubElement(target, 'span')
            if style_id and style_id in self.styles:
                span.set('class', _class_name('c', style_id))
            if declarations:
                span.set('style', _style_attr(declarations))
            return span

        span = new_span(parent)
        spans = [span]
        for child in list(run):
            if on_tab is not None and child.tag == qn('w:tab'):
                on_tab()
                span = new_span(cell_provider())
                spans.append(span)
                continue
            self._render_run_child(child, span, context)

        for candidate in spans:
            if len(candidate) == 0 and not candidate.text:
                parent_of = candidate.getparent()
                if parent_of is not None:
                    parent_of.remove(candidate)

    def _render_run_child(self, child, span, context: _Context) -> None:
        tag = child.tag

        if tag == qn('w:fldChar'):
            self._handle_field_char(child, span, context)
            return
        if tag == qn('w:instrText'):
            if context.fields:
                context.fields[-1].instruction += (child.text or '')
            return
        if context.suppressing or tag == qn('w:delText'):
            return

        if tag == qn('w:t'):
            _append_text(span, child.text or '')
        elif tag == qn('w:tab'):
            tab = etree.SubElement(span, 'span')
            tab.set('class', 'ps-tab')
            tab.set('style', 'width: %s' % fmt_pt(self._default_tab_pt))
        elif tag == qn('w:br'):
            break_type = attr(child, 'w:type', 'textWrapping')
            if break_type in ('page', 'column'):
                marker = etree.SubElement(span, 'span')
                marker.set('style', 'display: block; height: 0; break-after: %s'
                           % ('page' if break_type == 'page' else 'column'))
            else:
                etree.SubElement(span, 'br')
        elif tag == qn('w:cr'):
            etree.SubElement(span, 'br')
        elif tag == qn('w:noBreakHyphen'):
            _append_text(span, '‑')
        elif tag == qn('w:softHyphen'):
            _append_text(span, '­')
        elif tag == qn('w:sym'):
            code = attr(child, 'w:char')
            try:
                character = chr(int(code, 16))
            except (TypeError, ValueError):
                character = ''
            _append_text(span, _BULLET_MAP.get(character, character))
        elif tag == qn('w:drawing'):
            self._render_drawing(child, span, context)
        elif tag == qn('w:pict'):
            self._render_vml(child, span, context)
        elif tag == qn('mc:AlternateContent'):
            for grandchild in _pick_alternate(child):
                self._render_run_child(grandchild, span, context)
        elif tag in (qn('w:footnoteReference'), qn('w:endnoteReference')):
            self._render_note(child, span, context, tag)
        elif tag == qn('w:object'):
            self._warn('Ingesloten objecten (OLE) worden niet in de PDF opgenomen.')

    def _handle_field_char(self, element, span, context: _Context) -> None:
        char_type = attr(element, 'w:fldCharType', 'begin')
        if char_type == 'begin':
            context.fields.append(_FieldFrame())
            return
        if not context.fields:
            return
        frame = context.fields[-1]
        if char_type == 'separate':
            frame.in_result = True
            instruction = frame.instruction.strip()
            keyword = instruction.split()[0].upper() if instruction else ''
            if keyword in ('PAGE', 'NUMPAGES', 'SECTIONPAGES'):
                frame.suppress_result = True
                counter = etree.SubElement(span, 'span')
                counter.set('class', 'ps-pagenum' if keyword == 'PAGE'
                            else 'ps-pagecount')
        elif char_type == 'end':
            context.fields.pop()

    def _render_simple_field(self, element, parent, context: _Context) -> None:
        instruction = (attr(element, 'w:instr', '') or '').strip()
        keyword = instruction.split()[0].upper() if instruction else ''
        if keyword in ('PAGE', 'NUMPAGES', 'SECTIONPAGES'):
            counter = etree.SubElement(parent, 'span')
            counter.set('class', 'ps-pagenum' if keyword == 'PAGE' else 'ps-pagecount')
            return
        for child in list(element):
            self._render_inline(child, parent, context)

    def _render_note(self, reference, span, context: _Context, tag: str) -> None:
        is_footnote = tag == qn('w:footnoteReference')
        part = self.footnotes_part if is_footnote else self.endnotes_part
        root = self.pkg.tree(part) if part else None
        if root is None:
            return
        note_id = reference.get(qn('w:id'))
        note_tag = 'w:footnote' if is_footnote else 'w:endnote'
        for note in findall(root, note_tag):
            if note.get(qn('w:id')) != note_id:
                continue
            if attr(note, 'w:type') in ('separator', 'continuationSeparator',
                                        'continuationNotice'):
                return
            holder = etree.SubElement(span, 'span')
            holder.set('class', 'ps-footnote')
            note_context = _Context(part, tag_images=False)
            for child in list(note):
                self._render_block(child, holder, note_context)
            return

    # ── Images ───────────────────────────────────────────────────────────────

    def _render_drawing(self, drawing, span, context: _Context) -> None:
        blip = find(drawing, './/a:blip')
        if blip is not None:
            rid = blip.get(qn('r:embed')) or blip.get(qn('r:link'))
            extent = find(drawing, './/wp:extent')
            doc_pr = find(drawing, './/wp:docPr')
            self._append_image(
                span, context, rid,
                emu_to_pt(extent.get('cx')) if extent is not None else None,
                emu_to_pt(extent.get('cy')) if extent is not None else None,
                (doc_pr.get('descr') or doc_pr.get('name') or '')
                if doc_pr is not None else '')
            return

        textbox = find(drawing, './/w:txbxContent')
        if textbox is not None:
            box = etree.SubElement(span, 'span')
            box.set('class', 'ps-textbox')
            for child in list(textbox):
                self._render_block(child, box, context)
            return

        self._warn('Een tekening zonder afbeelding is overgeslagen '
                   '(vorm, grafiek of diagram).')

    def _render_vml(self, pict, span, context: _Context) -> None:
        image_data = find(pict, './/v:imagedata')
        if image_data is None:
            textbox = find(pict, './/w:txbxContent')
            if textbox is not None:
                box = etree.SubElement(span, 'span')
                box.set('class', 'ps-textbox')
                for child in list(textbox):
                    self._render_block(child, box, context)
            return
        shape = find(pict, './/v:shape')
        width, height = _vml_size(shape.get('style') or '') if shape is not None \
            else (None, None)
        self._append_image(span, context, image_data.get(qn('r:id')), width, height,
                           image_data.get('title') or '')

    def _append_image(self, span, context: _Context, rid: Optional[str],
                      width: Optional[float], height: Optional[float],
                      alt: str) -> None:
        if not rid:
            return
        part = self.pkg.related_part_name(context.part_name, rid)
        blob = self.pkg.blob(part) if part else None
        if not blob:
            self._warn('Een afbeelding kon niet worden gelezen en is overgeslagen.')
            return

        mime = content_type_of(self.pkg, part) or _mime_from_name(part)
        subtype = mime.rsplit('/', 1)[-1].lower()
        if subtype in _UNSUPPORTED_IMAGE_SUBTYPES:
            self._warn('Een afbeelding in %s-formaat kan niet in een PDF worden '
                       'gezet en is overgeslagen.' % subtype.upper().lstrip('X-'))
            return

        # Scale down proportionally rather than letting max-width squash the
        # aspect ratio when a picture is wider than the text column.
        if width and width > self._content_width_pt:
            factor = self._content_width_pt / width
            width = self._content_width_pt
            height = height * factor if height else None

        image = etree.SubElement(span, 'img')
        image.set('src', 'data:%s;base64,%s'
                  % (mime, base64.b64encode(blob).decode('ascii')))
        if alt:
            image.set('alt', alt)
        declarations: Dict[str, str] = {}
        if width:
            declarations['width'] = fmt_pt(width)
        if height:
            declarations['height'] = fmt_pt(height)
        image.set('style', _style_attr(declarations))

        if context.tag_images:
            self._image_seq += 1
            image_id = 'psimg%d' % self._image_seq
            image.set('id', image_id)
            self.body_image_ids.append(image_id)

    # ── Tables ───────────────────────────────────────────────────────────────

    def _render_table(self, table_el, parent, context: _Context) -> None:
        tbl_pr = find(table_el, 'w:tblPr')
        style_id = attr(find(tbl_pr, 'w:tblStyle'), 'w:val')

        border_sources = [find(entry['tblpr'], 'w:tblBorders')
                          for entry in self.styles.chain(style_id)
                          if entry['tblpr'] is not None]
        border_sources.append(find(tbl_pr, 'w:tblBorders'))
        table_borders: Dict[str, str] = {}
        for source in border_sources:
            if source is not None:
                table_borders.update(_table_border_map(source))

        table = etree.SubElement(parent, 'table')
        table.set('class', 'ps-table')
        declarations = dict(_table_width(find(tbl_pr, 'w:tblW')))
        indent = twips_to_pt(attr(find(tbl_pr, 'w:tblInd'), 'w:w'))
        if indent:
            declarations['margin-left'] = fmt_pt(indent)
        for side in ('top', 'left', 'bottom', 'right'):
            if side in table_borders:
                declarations['border-%s' % side] = table_borders[side]
        table.set('style', _style_attr(declarations))

        grid = [twips_to_pt(attr(col, 'w:w'), 0) or 0
                for col in findall(table_el, 'w:tblGrid/w:gridCol')]
        if grid and sum(grid) > 0:
            colgroup = etree.SubElement(table, 'colgroup')
            total = sum(grid)
            for width in grid:
                etree.SubElement(colgroup, 'col').set(
                    'style', 'width: %.3f%%' % (100.0 * width / total))

        cell_margins = _cell_margins(find(tbl_pr, 'w:tblCellMar'))
        body_el = etree.SubElement(table, 'tbody')
        open_cells: Dict[int, etree._Element] = {}
        for row_el in [child for child in table_el if child.tag == qn('w:tr')]:
            self._render_row(row_el, body_el, context, table_borders,
                             cell_margins, open_cells)
        if len(body_el) == 0:
            parent.remove(table)

    def _render_row(self, row_el, body_el, context: _Context, table_borders,
                    cell_margins, open_cells) -> None:
        row = etree.SubElement(body_el, 'tr')
        declarations: Dict[str, str] = {}
        height = twips_to_pt(attr(find(row_el, 'w:trPr/w:trHeight'), 'w:val'))
        if height:
            declarations['height'] = fmt_pt(height)
        if toggle(find(row_el, 'w:trPr/w:cantSplit'), False):
            declarations['break-inside'] = 'avoid'
        if declarations:
            row.set('style', _style_attr(declarations))

        column = 0
        for cell_el in [child for child in row_el if child.tag == qn('w:tc')]:
            tc_pr = find(cell_el, 'w:tcPr')
            span_count = to_int(attr(find(tc_pr, 'w:gridSpan'), 'w:val'), 1) or 1
            v_merge = find(tc_pr, 'w:vMerge')
            continuing = v_merge is not None and \
                attr(v_merge, 'w:val', 'continue') != 'restart'

            if continuing and column in open_cells:
                anchor = open_cells[column]
                anchor.set('rowspan', str((to_int(anchor.get('rowspan'), 1) or 1) + 1))
                column += span_count
                continue

            cell = etree.SubElement(row, 'td')
            cell.set('class', 'ps-cell')
            if span_count > 1:
                cell.set('colspan', str(span_count))

            declarations = dict(cell_margins)
            declarations.update(_cell_margins(find(tc_pr, 'w:tcMar')) if
                                find(tc_pr, 'w:tcMar') is not None else {})
            if table_borders.get('insideH'):
                declarations['border-bottom'] = table_borders['insideH']
            if table_borders.get('insideV'):
                declarations['border-right'] = table_borders['insideV']
            own_borders = find(tc_pr, 'w:tcBorders')
            if own_borders is not None:
                declarations.update(_border_declarations(own_borders))
            shading = find(tc_pr, 'w:shd')
            fill = _hex_colour(attr(shading, 'w:fill')) if shading is not None else None
            if fill:
                declarations['background-color'] = fill
            v_align = attr(find(tc_pr, 'w:vAlign'), 'w:val')
            if v_align == 'center':
                declarations['vertical-align'] = 'middle'
            elif v_align == 'bottom':
                declarations['vertical-align'] = 'bottom'
            cell.set('style', _style_attr(declarations))

            for child in list(cell_el):
                self._render_block(child, cell, context)
            if len(cell) == 0:
                _append_text(cell, _NBSP)

            open_cells[column] = cell if v_merge is not None else None
            if open_cells[column] is None:
                del open_cells[column]
            column += span_count


# ── Module-level helpers ─────────────────────────────────────────────────────


def _split_sections(body_el) -> List[Tuple[List, object]]:
    """Split the body into (children, sectPr) pairs — one per Word section."""
    body_sect_pr = None
    for child in body_el:
        if child.tag == qn('w:sectPr'):
            body_sect_pr = child

    sections: List[Tuple[List, object]] = []
    current: List = []
    for child in body_el:
        if child.tag == qn('w:sectPr'):
            continue
        current.append(child)
        if child.tag == qn('w:p'):
            sect_pr = find(child, 'w:pPr/w:sectPr')
            if sect_pr is not None:
                sections.append((current, sect_pr))
                current = []
    if current or not sections:
        sections.append((current, body_sect_pr))
    return sections


def _page_geometry(sect_pr) -> Dict[str, float]:
    page_size = find(sect_pr, 'w:pgSz') if sect_pr is not None else None
    page_margin = find(sect_pr, 'w:pgMar') if sect_pr is not None else None

    width = twips_to_pt(attr(page_size, 'w:w'), _DEFAULT_PAGE_WIDTH_PT) \
        if page_size is not None else _DEFAULT_PAGE_WIDTH_PT
    height = twips_to_pt(attr(page_size, 'w:h'), _DEFAULT_PAGE_HEIGHT_PT) \
        if page_size is not None else _DEFAULT_PAGE_HEIGHT_PT
    width = width or _DEFAULT_PAGE_WIDTH_PT
    height = height or _DEFAULT_PAGE_HEIGHT_PT
    if attr(page_size, 'w:orient') == 'landscape' and width < height:
        width, height = height, width

    def margin(name: str) -> float:
        value = twips_to_pt(attr(page_margin, name), _DEFAULT_MARGIN_PT) \
            if page_margin is not None else _DEFAULT_MARGIN_PT
        if value is None:
            value = _DEFAULT_MARGIN_PT
        return max(value, 0.0)

    return {
        'width': width,
        'height': height,
        'margin_top': margin('w:top'),
        'margin_right': margin('w:right'),
        'margin_bottom': margin('w:bottom'),
        'margin_left': margin('w:left'),
    }


def _indent_declarations(indent) -> Dict[str, str]:
    css: Dict[str, str] = {}
    left = twips_to_pt(attr(indent, 'w:left') or attr(indent, 'w:start'))
    right = twips_to_pt(attr(indent, 'w:right') or attr(indent, 'w:end'))
    first_line = twips_to_pt(attr(indent, 'w:firstLine'))
    hanging = twips_to_pt(attr(indent, 'w:hanging'))
    if left is not None:
        css['margin-left'] = fmt_pt(left)
    if right is not None:
        css['margin-right'] = fmt_pt(right)
    if hanging:
        css['text-indent'] = fmt_pt(-hanging)
    elif first_line:
        css['text-indent'] = fmt_pt(first_line)
    return css


def _border_value(border) -> Optional[str]:
    if border is None:
        return None
    value = attr(border, 'w:val', 'single')
    if value in ('nil', 'none', None):
        return 'none'
    width = eighth_points_to_pt(attr(border, 'w:sz'), 0.75) or 0.75
    colour = _hex_colour(attr(border, 'w:color')) or '#000000'
    return '%s %s %s' % (fmt_pt(max(width, 0.25)),
                         _BORDER_STYLE.get(value, 'solid'), colour)


def _border_declarations(borders) -> Dict[str, str]:
    css: Dict[str, str] = {}
    for side in ('top', 'left', 'bottom', 'right'):
        value = _border_value(find(borders, 'w:%s' % side))
        if value:
            css['border-%s' % side] = value
    return css


def _table_border_map(borders) -> Dict[str, str]:
    result: Dict[str, str] = {}
    for side in ('top', 'left', 'bottom', 'right', 'insideH', 'insideV'):
        value = _border_value(find(borders, 'w:%s' % side))
        if value:
            result[side] = value
    return result


def _cell_margins(cell_mar) -> Dict[str, str]:
    if cell_mar is None:
        return {'padding': '2pt 4pt'}
    css: Dict[str, str] = {}
    for side, prop in (('top', 'padding-top'), ('bottom', 'padding-bottom'),
                       ('left', 'padding-left'), ('right', 'padding-right')):
        node = find(cell_mar, 'w:%s' % side)
        value = twips_to_pt(attr(node, 'w:w')) if node is not None else None
        if value is not None:
            css[prop] = fmt_pt(value)
    return css or {'padding': '2pt 4pt'}


def _table_width(tbl_w) -> Dict[str, str]:
    if tbl_w is None:
        return {'width': '100%'}
    width_type = attr(tbl_w, 'w:type', 'auto')
    raw = attr(tbl_w, 'w:w', '0')
    if width_type == 'pct':
        try:
            percent = float(str(raw).rstrip('%')) / 50.0
        except ValueError:
            return {'width': '100%'}
        return {'width': '%.2f%%' % min(percent, 100.0)}
    if width_type == 'dxa':
        value = twips_to_pt(raw)
        return {'width': fmt_pt(value)} if value else {'width': '100%'}
    return {'width': '100%'}


def _pick_alternate(alternate) -> List:
    """mc:AlternateContent — prefer the Choice, fall back to the Fallback."""
    choice = find(alternate, 'mc:Choice')
    if choice is not None and len(choice):
        return list(choice)
    fallback = find(alternate, 'mc:Fallback')
    return list(fallback) if fallback is not None else []


def _vml_size(style: str) -> Tuple[Optional[float], Optional[float]]:
    values: Dict[str, float] = {}
    for declaration in style.split(';'):
        name, _, raw = declaration.partition(':')
        name = name.strip().lower()
        raw = raw.strip().lower()
        if name in ('width', 'height') and raw.endswith('pt'):
            try:
                values[name] = float(raw[:-2])
            except ValueError:
                pass
    return values.get('width'), values.get('height')


def _mime_from_name(name: Optional[str]) -> str:
    extension = (name or '').rsplit('.', 1)[-1].lower()
    return {
        'png': 'image/png', 'jpg': 'image/jpeg', 'jpeg': 'image/jpeg',
        'gif': 'image/gif', 'bmp': 'image/bmp', 'svg': 'image/svg+xml',
        'webp': 'image/webp', 'tif': 'image/tiff', 'tiff': 'image/tiff',
        'emf': 'image/emf', 'wmf': 'image/wmf',
    }.get(extension, 'application/octet-stream')


def _tab_count(paragraph) -> int:
    return len(paragraph.findall('.//' + qn('w:tab')))


def has_visible_content(element) -> bool:
    if (element.text or '').strip():
        return True
    for node in element.iter():
        if node is element:
            continue
        if node.tag in ('img', 'br'):
            return True
        if (node.text or '').strip() or (node.tail or '').strip():
            return True
    return False


def _contains_page_number(element) -> bool:
    for node in element.iter('span'):
        classes = node.get('class') or ''
        if 'ps-pagenum' in classes or 'ps-pagecount' in classes:
            return True
    return False


def render(package: Package, options: Optional[RenderOptions] = None) -> RenderResult:
    """Render a cleaned package to an HTML tree."""
    return Renderer(package, options).build()


__all__ = ['RenderOptions', 'RenderResult', 'Renderer', 'render', 'NS']
